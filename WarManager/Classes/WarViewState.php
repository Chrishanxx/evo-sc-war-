<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;

final class WarViewState
{
    public static function latest(): ?object
    {
        $war = WarRepository::latest();
        if (!$war) {
            return null;
        }

        $scores = DB::table('war-players')->where('war_id', $war->id)
            ->selectRaw('locked_team, SUM(total_points) points')->groupBy('locked_team')
            ->pluck('points', 'locked_team');
        $mapCount = DB::table('war-maps')->where('war_id', $war->id)->count();
        $scoredMapCount = DB::table('war-records')->where('war_id', $war->id)
            ->pluck('map_uid')->unique()->count();

        return (object)[
            'war' => $war,
            'team_a_points' => (int)($scores[$war->team_a] ?? 0),
            'team_b_points' => (int)($scores[$war->team_b] ?? 0),
            'team_a_color' => self::color(config('war-manager.team-a-color', 'D8D184FF')),
            'team_b_color' => self::color(config('war-manager.team-b-color', 'FFFFFFFF')),
            'map_count' => (int)$mapCount,
            'scored_map_count' => (int)$scoredMapCount,
            'time_left' => self::timeLeft($war),
        ];
    }

    private static function timeLeft($war): string
    {
        if (in_array($war->status, [WarState::FINISHED, WarState::CANCELLED], true)) {
            return 'FINISHED';
        }
        if (!$war->end_at) {
            return $war->status === WarState::DRAFT ? 'Waiting' : '--';
        }
        $clock = $war->status === WarState::PAUSED && $war->paused_at
            ? strtotime($war->paused_at . ' UTC') : time();
        $seconds = max(0, strtotime($war->end_at . ' UTC') - $clock);
        if ($seconds >= 86400) {
            return floor($seconds / 86400) . 'd ' . sprintf('%02d', floor(($seconds % 86400) / 3600)) . 'h';
        }
        if ($seconds >= 3600) {
            return floor($seconds / 3600) . 'h ' . sprintf('%02d', floor(($seconds % 3600) / 60)) . 'm';
        }
        return floor($seconds / 60) . 'm';
    }

    private static function color(string $color): string
    {
        return preg_match('/^[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', $color)
            ? strtoupper($color) : 'FFFFFFFF';
    }
}
