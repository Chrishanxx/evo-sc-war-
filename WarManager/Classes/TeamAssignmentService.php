<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Models\Player;
use RuntimeException;

final class TeamAssignmentService
{
    public static function join(Player $player, string $team, string $assignedBy = 'overlay', bool $adminOverride = false): void
    {
        $war = WarRepository::current();
        if (!$war || !in_array($war->status, [WarState::DRAFT, WarState::ACTIVE, WarState::PAUSED], true)) {
            throw new RuntimeException('There is no joinable war.');
        }
        if (!$adminOverride && !$war->overlay_join_enabled) {
            throw new RuntimeException('Team joining through the overlay is disabled.');
        }
        if ($team !== $war->team_a && $team !== $war->team_b) {
            throw new RuntimeException('The selected team is not active in this war.');
        }
        $existing = DB::table('war-players')->where('war_id', $war->id)
            ->where('player_login', $player->Login)->first();
        if ($existing && $existing->locked_team === $team) {
            return;
        }
        if ($existing && !$adminOverride && !$war->allow_team_switch) {
            throw new RuntimeException('Team switching is disabled for this war.');
        }
        if ($war->team_limit) {
            $members = DB::table('war-players')->where('war_id', $war->id)->where('locked_team', $team)->count();
            if ($members >= (int)$war->team_limit) {
                throw new RuntimeException('This team is full.');
            }
        }

        DB::transaction(static function () use ($war, $player, $team, $assignedBy, $existing): void {
            DB::table('war-players')->updateOrInsert(
                ['war_id' => $war->id, 'player_login' => $player->Login],
                [
                    'display_name' => $player->NickName,
                    'locked_team' => $team,
                    'total_points' => $existing ? (int)$existing->total_points : 0,
                    'joined_at' => $existing && $existing->joined_at ? $existing->joined_at : gmdate('Y-m-d H:i:s'),
                    'assigned_by' => $assignedBy,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            if ($existing) {
                DB::table('war-records')->where('war_id', $war->id)->where('player_login', $player->Login)
                    ->update(['team' => $team, 'display_name' => $player->NickName]);
            }
            self::promotePending((int)$war->id, $player->Login, $player->NickName, $team);
        });
        RecordService::recalculateAll((int)$war->id);
        WarRepository::audit((int)$war->id, $player->Login, 'player.team.assigned', [
            'team' => $team, 'assigned_by' => $assignedBy,
        ]);
    }

    public static function assignFromNickname(Player $player): bool
    {
        $war = WarRepository::current();
        if (!$war || !$war->nickname_detection_enabled) {
            return false;
        }
        if (DB::table('war-players')->where('war_id', $war->id)->where('player_login', $player->Login)->exists()) {
            DB::table('war-players')->where('war_id', $war->id)->where('player_login', $player->Login)
                ->update(['display_name' => $player->NickName, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return true;
        }
        $detected = TeamDetector::detect($player->NickName, $war->team_a, $war->team_b);
        if (!$detected) {
            return false;
        }
        self::join($player, $detected['team'], 'nickname', true);
        DB::table('war-players')->where('war_id', $war->id)->where('player_login', $player->Login)
            ->update(['display_name' => $detected['name'], 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return true;
    }

    public static function reset(Player $admin, string $login): void
    {
        $war = WarRepository::current();
        if (!$war) {
            throw new RuntimeException('There is no current war.');
        }
        $records = DB::table('war-records')->where('war_id', $war->id)->where('player_login', $login)->get();
        DB::transaction(static function () use ($war, $login, $records): void {
            foreach ($records as $record) {
                DB::table('war-pending-records')->updateOrInsert(
                    ['war_id' => $war->id, 'map_uid' => $record->map_uid, 'player_login' => $login],
                    ['display_name' => $record->display_name, 'record_time' => $record->record_time, 'recorded_at' => $record->recorded_at]
                );
            }
            DB::table('war-records')->where('war_id', $war->id)->where('player_login', $login)->delete();
            DB::table('war-players')->where('war_id', $war->id)->where('player_login', $login)->delete();
        });
        RecordService::recalculateAll((int)$war->id);
        WarRepository::audit((int)$war->id, $admin->Login, 'player.team.reset', ['player_login' => $login]);
    }

    public static function move(Player $admin, string $login): void
    {
        $war = WarRepository::current();
        $entry = $war ? DB::table('war-players')->where('war_id', $war->id)->where('player_login', $login)->first() : null;
        if (!$entry) {
            throw new RuntimeException('The selected player is no longer assigned.');
        }
        $target = $entry->locked_team === $war->team_a ? $war->team_b : $war->team_a;
        DB::transaction(static function () use ($war, $entry, $target): void {
            DB::table('war-players')->where('war_id', $war->id)->where('player_login', $entry->player_login)
                ->update(['locked_team' => $target, 'assigned_by' => 'admin', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            DB::table('war-records')->where('war_id', $war->id)->where('player_login', $entry->player_login)
                ->update(['team' => $target]);
        });
        RecordService::recalculateAll((int)$war->id);
        WarRepository::audit((int)$war->id, $admin->Login, 'player.team.moved', [
            'player_login' => $login, 'team' => $target,
        ]);
    }

    private static function promotePending(int $warId, string $login, string $name, string $team): void
    {
        $pending = DB::table('war-pending-records')->where('war_id', $warId)->where('player_login', $login)->get();
        foreach ($pending as $record) {
            DB::table('war-records')->updateOrInsert(
                ['war_id' => $warId, 'map_uid' => $record->map_uid, 'player_login' => $login],
                [
                    'display_name' => $name,
                    'team' => $team,
                    'record_time' => $record->record_time,
                    'recorded_at' => $record->recorded_at,
                ]
            );
        }
        DB::table('war-pending-records')->where('war_id', $warId)->where('player_login', $login)->delete();
    }
}
