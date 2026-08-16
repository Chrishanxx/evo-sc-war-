<?php

namespace EvoSC\Modules\WarApiBridge\Classes;

use EvoSC\Classes\DB;
use EvoSC\Modules\WarManager\Classes\WarState;

final class WarSnapshotExporter
{
    public static function export(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $wars = DB::table('wars')->orderByDesc('id')->limit($limit)->get();
        $payload = [];
        $activeWarId = null;

        foreach ($wars as $war) {
            if ($activeWarId === null && in_array($war->status, [WarState::DRAFT, WarState::ACTIVE, WarState::PAUSED], true)) {
                $activeWarId = (int)$war->id;
            }
            $payload[] = self::exportWar($war);
        }

        return [
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'source' => 'evosc-war-manager',
            'activeWarId' => $activeWarId,
            'wars' => $payload,
        ];
    }

    private static function exportWar($war): array
    {
        $records = DB::table('war-records')->where('war_id', $war->id)
            ->orderBy('map_uid')->orderBy('rank')->get();
        $players = DB::table('war-players')->where('war_id', $war->id)
            ->orderByDesc('total_points')->orderBy('display_name')->get();
        $maps = DB::table('war-maps')->where('war_id', $war->id)
            ->orderBy('position')->orderBy('id')->get();

        $recordStats = [];
        $mapRecords = [];
        foreach ($records as $record) {
            $login = (string)$record->player_login;
            if (!isset($recordStats[$login])) {
                $recordStats[$login] = ['lr1' => 0, 'lr2' => 0, 'lr3' => 0, 'podiums' => 0, 'maps' => []];
            }
            $rank = (int)$record->rank;
            if ($rank >= 1 && $rank <= 3) {
                $recordStats[$login]['lr' . $rank]++;
                $recordStats[$login]['podiums']++;
            }
            $recordStats[$login]['maps'][(string)$record->map_uid] = true;
            $mapRecords[(string)$record->map_uid][] = $record;
        }

        $exportedPlayers = [];
        $teamScores = [(string)$war->team_a => 0, (string)$war->team_b => 0];
        foreach ($players as $player) {
            $stats = $recordStats[(string)$player->player_login] ?? ['lr1' => 0, 'lr2' => 0, 'lr3' => 0, 'podiums' => 0, 'maps' => []];
            $points = (int)$player->total_points;
            if (array_key_exists((string)$player->locked_team, $teamScores)) {
                $teamScores[(string)$player->locked_team] += $points;
            }
            $exportedPlayers[] = [
                'login' => (string)$player->player_login,
                'displayName' => (string)$player->display_name,
                'team' => (string)$player->locked_team,
                'points' => $points,
                'lr1' => $stats['lr1'],
                'lr2' => $stats['lr2'],
                'lr3' => $stats['lr3'],
                'podiums' => $stats['podiums'],
                'mapsPlayed' => count($stats['maps']),
            ];
        }

        $exportedMaps = [];
        foreach ($maps as $map) {
            $scores = [(string)$war->team_a => 0, (string)$war->team_b => 0];
            $exportedRecords = [];
            foreach ($mapRecords[(string)$map->map_uid] ?? [] as $record) {
                if (array_key_exists((string)$record->team, $scores)) {
                    $scores[(string)$record->team] += (int)$record->points;
                }
                $exportedRecords[] = [
                    'login' => (string)$record->player_login,
                    'displayName' => (string)$record->display_name,
                    'team' => (string)$record->team,
                    'time' => (int)$record->record_time,
                    'rank' => $record->rank === null ? null : (int)$record->rank,
                    'points' => (int)$record->points,
                    'recordedAt' => (string)$record->recorded_at,
                ];
            }
            $winner = null;
            if ($scores[(string)$war->team_a] !== $scores[(string)$war->team_b]) {
                $winner = $scores[(string)$war->team_a] > $scores[(string)$war->team_b]
                    ? (string)$war->team_a : (string)$war->team_b;
            }
            $exportedMaps[] = [
                'uid' => (string)$map->map_uid,
                'name' => (string)$map->map_name,
                'position' => (int)($map->position ?? 0),
                'enabled' => (bool)($map->enabled ?? true),
                'teamAScore' => $scores[(string)$war->team_a],
                'teamBScore' => $scores[(string)$war->team_b],
                'winner' => $winner,
                'records' => $exportedRecords,
            ];
        }

        $winner = null;
        if ($teamScores[(string)$war->team_a] !== $teamScores[(string)$war->team_b]) {
            $winner = $teamScores[(string)$war->team_a] > $teamScores[(string)$war->team_b]
                ? (string)$war->team_a : (string)$war->team_b;
        }

        return [
            'id' => (int)$war->id,
            'name' => (string)$war->name,
            'status' => (string)$war->status,
            'startAt' => $war->start_at,
            'endAt' => $war->end_at,
            'finishedAt' => $war->finished_at,
            'durationDays' => (int)$war->duration_days,
            'pausedSeconds' => (int)($war->paused_seconds ?? 0),
            'scoringPaused' => (bool)($war->scoring_paused ?? false),
            'teamA' => ['name' => (string)$war->team_a, 'score' => $teamScores[(string)$war->team_a]],
            'teamB' => ['name' => (string)$war->team_b, 'score' => $teamScores[(string)$war->team_b]],
            'winner' => $winner,
            'players' => $exportedPlayers,
            'maps' => $exportedMaps,
        ];
    }
}
