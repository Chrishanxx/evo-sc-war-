<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Models\Player;
use RuntimeException;

final class WarRepository
{
    public static function latest()
    {
        return DB::table('wars')->orderByDesc('id')->first();
    }

    public static function current()
    {
        return DB::table('wars')->whereIn('status', [WarState::DRAFT, WarState::ACTIVE, WarState::PAUSED])
            ->orderByDesc('id')->first();
    }

    public static function create(Player $admin, int $days, string $teamA, string $teamB, string $name): int
    {
        if ($days < 1 || $days > 14) {
            throw new RuntimeException('Duration must be between 1 and 14 days.');
        }
        $teamA = trim($teamA);
        $teamB = trim($teamB);
        if (strcasecmp($teamA, $teamB) === 0 || $teamA === '' || $teamB === '') {
            throw new RuntimeException('Team tags must be non-empty and different.');
        }
        if (mb_strlen($teamA) > 32 || mb_strlen($teamB) > 32) {
            throw new RuntimeException('Team tags cannot exceed 32 characters.');
        }
        if (self::current()) {
            throw new RuntimeException('A draft or running war already exists.');
        }

        return DB::transaction(static function () use ($admin, $days, $teamA, $teamB, $name): int {
            $id = DB::table('wars')->insertGetId([
                'name' => $name ?: "{$teamA} vs {$teamB}",
                'team_a' => $teamA,
                'team_b' => $teamB,
                'duration_days' => $days,
                'status' => WarState::DRAFT,
                'created_by' => $admin->Login,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $defaults = config('war-manager.default-points', range(16, 1));
            foreach (array_values($defaults) as $index => $points) {
                DB::table('war-points')->insert(['war_id' => $id, 'rank' => $index + 1, 'points' => (int)$points]);
            }
            self::audit($id, $admin->Login, 'war.created', compact('days', 'teamA', 'teamB', 'name'));
            return $id;
        });
    }

    public static function transition(Player $admin, string $target): void
    {
        $scrim = self::requireCurrent();
        WarState::assertTransition($scrim->status, $target);
        if ($target === WarState::ACTIVE && !$scrim->start_at
            && !DB::table('war-maps')->where('war_id', $scrim->id)->exists()) {
            throw new RuntimeException('Add at least one server map before starting the war.');
        }
        $values = ['status' => $target, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($target === WarState::ACTIVE && !$scrim->start_at) {
            $start = time();
            $values['start_at'] = gmdate('Y-m-d H:i:s', $start);
            $values['end_at'] = gmdate('Y-m-d H:i:s', $start + ((int)$scrim->duration_days * 86400));
            $values['map_pool_locked'] = 1;
            $values['points_locked'] = 1;
        } elseif ($target === WarState::PAUSED) {
            $values['paused_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($target === WarState::ACTIVE && $scrim->paused_at) {
            $pausedFor = max(0, time() - strtotime($scrim->paused_at . ' UTC'));
            $values['paused_at'] = null;
            $values['paused_seconds'] = (int)$scrim->paused_seconds + $pausedFor;
            if ($scrim->end_at) {
                $values['end_at'] = gmdate('Y-m-d H:i:s', strtotime($scrim->end_at . ' UTC') + $pausedFor);
            }
        }
        if ($target === WarState::FINISHED || $target === WarState::CANCELLED) {
            $values['finished_at'] = gmdate('Y-m-d H:i:s');
        }
        DB::table('wars')->where('id', $scrim->id)->update($values);
        self::audit($scrim->id, $admin->Login, 'war.transition', ['from' => $scrim->status, 'to' => $target]);
    }

    public static function updateTeams(Player $admin, string $teamA, string $teamB, string $name = ''): void
    {
        $war = self::requireCurrent();
        if ($war->status !== WarState::DRAFT) {
            throw new RuntimeException('Team names can only be changed while the war is a draft.');
        }

        $teamA = trim($teamA);
        $teamB = trim($teamB);
        $name = trim($name);
        if ($teamA === '' || $teamB === '' || strcasecmp($teamA, $teamB) === 0) {
            throw new RuntimeException('Team tags must be non-empty and different.');
        }
        if (mb_strlen($teamA) > 32 || mb_strlen($teamB) > 32) {
            throw new RuntimeException('Team tags cannot exceed 32 characters.');
        }

        DB::table('wars')->where('id', $war->id)->update([
            'team_a' => $teamA,
            'team_b' => $teamB,
            'name' => $name ?: "{$teamA} vs {$teamB}",
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::audit($war->id, $admin->Login, 'war.teams.updated', compact('teamA', 'teamB', 'name'));
    }

    public static function updateDuration(Player $admin, int $days): void
    {
        $war = self::requireCurrent();
        if ($war->status !== WarState::DRAFT) {
            throw new RuntimeException('Duration can only be changed while the war is a draft.');
        }
        if ($days < 1 || $days > 14) {
            throw new RuntimeException('Duration must be between 1 and 14 days.');
        }
        DB::table('wars')->where('id', $war->id)->update([
            'duration_days' => $days,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::audit($war->id, $admin->Login, 'war.duration.updated', compact('days'));
    }

    public static function updateMatchsettingsProfile(
        Player $admin,
        string $teamAName,
        string $teamBName,
        int $mapTimeLimit,
        int $chatTime,
        bool $restoreAfterRestart
    ): void {
        $war = self::requireCurrent();
        if ($war->status !== WarState::DRAFT) {
            throw new RuntimeException('The TM_War_Online profile can only be changed while the war is a draft.');
        }
        $teamAName = trim($teamAName) ?: $war->team_a;
        $teamBName = trim($teamBName) ?: $war->team_b;
        if (mb_strlen($teamAName) > 64 || mb_strlen($teamBName) > 64) {
            throw new RuntimeException('Team display names cannot exceed 64 characters.');
        }
        if ($mapTimeLimit < 60 || $mapTimeLimit > 3600) {
            throw new RuntimeException('Map time must be between 60 and 3600 seconds.');
        }
        if ($chatTime < 0 || $chatTime > 300) {
            throw new RuntimeException('Chat time must be between 0 and 300 seconds.');
        }
        DB::table('wars')->where('id', $war->id)->update([
            'mode_type' => 'WAR',
            'trackmania_script' => WarMatchSettingsService::BASE_SCRIPT,
            'team_a_name' => $teamAName,
            'team_b_name' => $teamBName,
            'map_time_limit' => $mapTimeLimit,
            'chat_time' => $chatTime,
            'restore_after_restart' => $restoreAfterRestart ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::audit($war->id, $admin->Login, 'matchsettings.profile.updated', [
            'team_a_name' => $teamAName,
            'team_b_name' => $teamBName,
            'map_time_limit' => $mapTimeLimit,
            'chat_time' => $chatTime,
            'restore_after_restart' => $restoreAfterRestart,
        ]);
    }

    public static function updateParticipationSettings(
        Player $admin,
        bool $overlayJoin,
        bool $nicknameDetection,
        bool $allowTeamSwitch,
        int $teamLimit
    ): void {
        $war = self::requireCurrent();
        if ($war->status !== WarState::DRAFT) {
            throw new RuntimeException('Participation settings can only be changed while the war is a draft.');
        }
        if ($teamLimit < 0 || $teamLimit > 100) {
            throw new RuntimeException('Team limit must be 0 (unlimited) or between 1 and 100.');
        }
        if ($teamLimit > 0) {
            $largestTeam = DB::table('war-players')->where('war_id', $war->id)
                ->selectRaw('COUNT(*) members')->groupBy('locked_team')->orderByDesc('members')->first();
            if ((int)($largestTeam->members ?? 0) > $teamLimit) {
                throw new RuntimeException('Team limit cannot be lower than the current number of team members.');
            }
        }
        DB::table('wars')->where('id', $war->id)->update([
            'overlay_join_enabled' => $overlayJoin ? 1 : 0,
            'nickname_detection_enabled' => $nicknameDetection ? 1 : 0,
            'allow_team_switch' => $allowTeamSwitch ? 1 : 0,
            'team_limit' => $teamLimit ?: null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::audit($war->id, $admin->Login, 'war.participation.updated', [
            'overlay_join_enabled' => $overlayJoin,
            'nickname_detection_enabled' => $nicknameDetection,
            'allow_team_switch' => $allowTeamSwitch,
            'team_limit' => $teamLimit,
        ]);
    }

    public static function setPointProfile(Player $admin, array $profile): void
    {
        $war = self::requireCurrent();
        if ($war->points_locked) {
            throw new RuntimeException('The point profile is locked after the war starts.');
        }
        $profile = array_slice(array_values($profile), 0, 16);
        if (count($profile) !== 16) {
            throw new RuntimeException('The point profile must contain 16 ranks.');
        }
        foreach ($profile as $points) {
            if (!is_numeric($points) || (int)$points < 0) {
                throw new RuntimeException('Points must be zero or greater.');
            }
        }
        DB::transaction(static function () use ($war, $profile): void {
            foreach ($profile as $index => $points) {
                DB::table('war-points')->updateOrInsert(
                    ['war_id' => $war->id, 'rank' => $index + 1],
                    ['points' => (int)$points]
                );
            }
        });
        self::audit($war->id, $admin->Login, 'points.profile.changed', ['points' => array_map('intval', $profile)]);
    }

    public static function finishExpired(): bool
    {
        $scrim = self::current();
        if (!$scrim || $scrim->status !== WarState::ACTIVE
            || !$scrim->end_at || strtotime($scrim->end_at . ' UTC') > time()) {
            return false;
        }
        DB::table('wars')->where('id', $scrim->id)->update([
            'status' => WarState::FINISHED,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::audit($scrim->id, 'system', 'war.expired', []);
        return true;
    }

    public static function addMap(Player $admin, string $uid, string $name): void
    {
        $scrim = self::requireCurrent();
        if ($scrim->map_pool_locked) {
            throw new RuntimeException('The map pool is locked after the war starts.');
        }
        $uid = trim($uid);
        if ($uid === '') {
            throw new RuntimeException('A MapUID is required.');
        }
        $serverMap = DB::table('maps')->where('uid', $uid)->first();
        if (!$serverMap) {
            throw new RuntimeException('The MapUID is not available on this server.');
        }
        $name = trim($name) ?: $serverMap->name;
        DB::table('war-maps')->updateOrInsert(['war_id' => $scrim->id, 'map_uid' => $uid], ['map_name' => $name]);
        self::audit($scrim->id, $admin->Login, 'map.added', compact('uid', 'name'));
    }

    public static function removeMap(Player $admin, string $uid): void
    {
        $scrim = self::requireCurrent();
        if ($scrim->map_pool_locked) {
            throw new RuntimeException('The map pool is locked after the war starts.');
        }
        $uid = trim($uid);
        if ($uid === '') {
            throw new RuntimeException('A MapUID is required.');
        }
        DB::table('war-maps')->where('war_id', $scrim->id)->where('map_uid', $uid)->delete();
        self::audit($scrim->id, $admin->Login, 'map.removed', compact('uid'));
    }

    public static function setPoints(Player $admin, int $rank, int $points): void
    {
        $scrim = self::requireCurrent();
        if ($scrim->points_locked) {
            throw new RuntimeException('The point profile is locked after the war starts.');
        }
        if ($rank < 1 || $rank > 100 || $points < 0) {
            throw new RuntimeException('Rank must be 1..100 and points cannot be negative.');
        }
        DB::table('war-points')->updateOrInsert(['war_id' => $scrim->id, 'rank' => $rank], ['points' => $points]);
        self::audit($scrim->id, $admin->Login, 'points.changed', compact('rank', 'points'));
    }

    public static function requireCurrent()
    {
        $scrim = self::current();
        if (!$scrim) {
            throw new RuntimeException('No current war exists.');
        }
        return $scrim;
    }

    public static function audit(int $scrimId, string $login, string $action, array $data): void
    {
        DB::table('war-admin-log')->insert([
            'war_id' => $scrimId, 'admin_login' => $login, 'action' => $action,
            'data' => json_encode($data), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
