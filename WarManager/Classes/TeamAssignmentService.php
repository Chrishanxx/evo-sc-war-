<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Models\Player;
use RuntimeException;

final class TeamAssignmentService
{
    public static function join(
        Player $player,
        string $team,
        string $assignedBy = 'overlay',
        ?string $originalName = null,
        ?string $warDisplayName = null
    ): void
    {
        $war = WarRepository::current();
        if (!$war) {
            throw new RuntimeException('There is no current war.');
        }
        if (!$war->overlay_join_enabled) {
            throw new RuntimeException('Team joining through the overlay is disabled.');
        }
        if ($team !== $war->team_a && $team !== $war->team_b) {
            throw new RuntimeException('The selected team is not active in this war.');
        }
        $existing = DB::table('war-players')->where('war_id', $war->id)
            ->where('player_login', $player->Login)->first();
        TeamJoinPolicy::assertCanJoin($war->status, $existing ? $existing->locked_team : null);
        if ($war->team_limit) {
            $members = DB::table('war-players')->where('war_id', $war->id)->where('locked_team', $team)->count();
            if ($members >= (int)$war->team_limit) {
                throw new RuntimeException('This team is full.');
            }
        }

        $scoringName = $originalName ?: $player->NickName;
        DB::transaction(static function () use ($war, $player, $team, $assignedBy, $originalName, $warDisplayName, $scoringName): void {
            $existing = DB::table('war-players')->where('war_id', $war->id)
                ->where('player_login', $player->Login)->first();
            if ($existing) {
                throw new RuntimeException('You are already assigned to ' . $existing->locked_team . '. Team switching is disabled for this scrim.');
            }
            DB::table('war-players')->insert([
                    'war_id' => $war->id,
                    'player_login' => $player->Login,
                    'display_name' => $scoringName,
                    'locked_team' => $team,
                    'total_points' => 0,
                    'joined_at' => gmdate('Y-m-d H:i:s'),
                    'assigned_by' => $assignedBy,
                    'original_name' => $originalName ?: $player->NickName,
                    'war_display_name' => $warDisplayName ?: $player->NickName,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            self::promotePending((int)$war->id, $player->Login, $scoringName, $team);
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
        $detected = TeamDetector::detect($player->NickName, $war->team_a, $war->team_b);
        $existing = DB::table('war-players')->where('war_id', $war->id)
            ->where('player_login', $player->Login)->first();
        if ($existing) {
            DB::table('war-players')->where('war_id', $war->id)->where('player_login', $player->Login)
                ->update([
                    'display_name' => $detected ? $detected['name'] : $player->NickName,
                    'war_display_name' => $player->NickName,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            return true;
        }
        return false;
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
