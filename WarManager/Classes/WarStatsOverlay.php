<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Classes\Template;
use EvoSC\Controllers\MapController;
use EvoSC\Models\Player;
use Throwable;

final class WarStatsOverlay
{
    private const ROWS_PER_PAGE = 10;

    private static array $openTabs = [];
    private static array $playerPages = [];
    private static array $mapPages = [];
    private static array $selectedMapUids = [];
    private static array $confirmTeams = [];

    public static function showWidget(Player $player): void
    {
        $state = WarViewState::latest();
        if (!$state || !in_array($state->war->status, [WarState::ACTIVE, WarState::PAUSED], true)) {
            Template::hide($player, 'WarManager');
            return;
        }
        $war = $state->war;
        $teamAPoints = $state->team_a_points;
        $teamBPoints = $state->team_b_points;
        $teamAColor = $state->team_a_color;
        $teamBColor = $state->team_b_color;
        $teamAScoreColor = $teamAPoints > $teamBPoints ? 'E4DA72FF' : 'F5F5F5FF';
        $teamBScoreColor = $teamBPoints > $teamAPoints ? 'E4DA72FF' : 'F5F5F5FF';
        $timeLeft = $state->time_left;
        $mapCount = $state->map_count;
        $rotationNumber = $state->rotation_number;
        $rotationPosition = $state->rotation_position;
        $assignment = DB::table('war-players')->where('war_id', $war->id)
            ->where('player_login', $player->Login)->first();
        $currentMap = MapController::getCurrentMap();
        $currentMapValid = $currentMap && DB::table('war-maps')->where('war_id', $war->id)
            ->where('map_uid', $currentMap->uid)->exists();
        $manualScoringPause = !empty($war->scoring_paused);
        $scoringPaused = $war->status === WarState::ACTIVE && ($manualScoringPause || !$currentMapValid);
        $statusLabel = $scoringPaused ? 'SCORING PAUSED' : ($war->status === WarState::FINISHED ? 'FINAL' : $war->status);
        $statusDetail = $manualScoringPause ? strtoupper((string)($war->scoring_pause_reason ?: 'MANUALLY PAUSED')) :
            ($scoringPaused ? 'MAP NOT PART OF SCRIM' :
            ($war->status === WarState::DRAFT ? 'WAITING' :
                ($war->status === WarState::PAUSED ? 'TIMER PAUSED' : $timeLeft)));
        $mapLabel = $war->status === WarState::DRAFT ? 'MAPS ' . $mapCount :
            'MAP ' . $rotationPosition . '/' . $mapCount;
        $canOpenAdmin = $player->hasAccess('war_manage');
        Template::show($player, 'WarManager.overview', compact(
            'war', 'teamAPoints', 'teamBPoints', 'teamAColor', 'teamBColor',
            'teamAScoreColor', 'teamBScoreColor', 'timeLeft', 'mapCount',
            'rotationNumber', 'rotationPosition', 'assignment', 'currentMapValid', 'scoringPaused',
            'statusLabel', 'statusDetail', 'mapLabel', 'canOpenAdmin'
        ));
    }

    public static function show(Player $player, string $tab = 'overview'): void
    {
        $tab = in_array($tab, ['overview', 'teams', 'players', 'maps', 'map-detail'], true) ? $tab : 'overview';
        $state = WarViewState::latest();
        if (!$state) {
            infoMessage('There is no current war.')->send($player);
            return;
        }
        $war = $state->war;
        $warId = (int)$war->id;
        $teamAPoints = $state->team_a_points;
        $teamBPoints = $state->team_b_points;
        $teamAColor = $state->team_a_color;
        $teamBColor = $state->team_b_color;
        $timeLeft = $state->time_left;

        $maps = DB::table('war-maps')->where('war_id', $warId)->orderBy('position')->orderBy('id')->get();
        $mapScores = DB::table('war-records')->where('war_id', $warId)
            ->selectRaw('map_uid, team, SUM(points) points')->groupBy('map_uid', 'team')->get();
        $scoredMapUids = DB::table('war-records')->where('war_id', $warId)->pluck('map_uid')->unique()->all();
        $currentMap = MapController::getCurrentMap();
        $currentMapUid = $currentMap ? $currentMap->uid : '';
        $currentMapName = $currentMap ? $currentMap->name : '--';
        $currentMapValid = $currentMap && DB::table('war-maps')->where('war_id', $warId)
            ->where('map_uid', $currentMapUid)->exists();
        $currentWarMap = $maps->firstWhere('map_uid', $currentMapUid);
        $currentPosition = $currentWarMap ? (int)$currentWarMap->position : 0;
        $displayStatus = $war->status === WarState::ACTIVE && (!empty($war->scoring_paused) || !$currentMapValid)
            ? 'SCORING PAUSED' : ($war->status === WarState::FINISHED ? 'FINAL' : $war->status);
        $mapRows = $maps->map(static function ($map) use ($mapScores, $scoredMapUids, $currentMapUid, $currentPosition, $war) {
            $scores = $mapScores->where('map_uid', $map->map_uid)->pluck('points', 'team');
            $status = $map->map_uid === $currentMapUid ? 'CURRENT' :
                (in_array($map->map_uid, $scoredMapUids, true) ? 'DONE' :
                    ($currentPosition > 0 && (int)$map->position === $currentPosition + 1 ? 'NEXT' : 'UPCOMING'));
            return (object)[
                'uid' => $map->map_uid,
                'name' => $map->map_name,
                'team_a_points' => (int)($scores[$war->team_a] ?? 0),
                'team_b_points' => (int)($scores[$war->team_b] ?? 0),
                'status' => $status,
            ];
        });
        $historyRows = $mapRows->where('status', 'DONE')->take(7)->values();
        $players = DB::table('war-players')->where('war_id', $warId)
            ->orderByDesc('total_points')->orderBy('display_name')->get()
            ->map(static function ($entry) use ($war, $teamAColor, $teamBColor, $player) {
                $entry->team_color = $entry->locked_team === $war->team_a ? $teamAColor : $teamBColor;
                $entry->is_self = $entry->player_login === $player->Login;
                return $entry;
            });
        $teamARows = $players->where('locked_team', $war->team_a)->take(7)->values();
        $teamBRows = $players->where('locked_team', $war->team_b)->take(7)->values();
        $teamABestPlayer = (int)($players->where('locked_team', $war->team_a)->max('total_points') ?? 0);
        $teamBBestPlayer = (int)($players->where('locked_team', $war->team_b)->max('total_points') ?? 0);
        $teamAMapWins = $mapRows->where('status', 'DONE')->filter(static function ($row) {
            return $row->team_a_points > $row->team_b_points;
        })->count();
        $teamBMapWins = $mapRows->where('status', 'DONE')->filter(static function ($row) {
            return $row->team_b_points > $row->team_a_points;
        })->count();
        $teamARecordCount = DB::table('war-records')->where('war_id', $warId)->where('team', $war->team_a)->count();
        $teamBRecordCount = DB::table('war-records')->where('war_id', $warId)->where('team', $war->team_b)->count();

        $playerPageCount = max(1, (int)ceil($players->count() / self::ROWS_PER_PAGE));
        $playerPage = max(1, min((int)(self::$playerPages[$player->Login] ?? 1), $playerPageCount));
        self::$playerPages[$player->Login] = $playerPage;
        $playerRows = $players->slice(($playerPage - 1) * self::ROWS_PER_PAGE, self::ROWS_PER_PAGE)->values();

        $mapPageCount = max(1, (int)ceil($mapRows->count() / self::ROWS_PER_PAGE));
        $mapPage = max(1, min((int)(self::$mapPages[$player->Login] ?? 1), $mapPageCount));
        self::$mapPages[$player->Login] = $mapPage;
        $visibleMapRows = $mapRows->slice(($mapPage - 1) * self::ROWS_PER_PAGE, self::ROWS_PER_PAGE)->values();
        $scoredMapCount = $state->scored_map_count;
        $mapCount = $state->map_count;
        $rotationNumber = $state->rotation_number;
        $rotationPosition = $state->rotation_position;
        $assignment = DB::table('war-players')->where('war_id', $warId)
            ->where('player_login', $player->Login)->first();
        $teamAMembers = DB::table('war-players')->where('war_id', $warId)->where('locked_team', $war->team_a)->count();
        $teamBMembers = DB::table('war-players')->where('war_id', $warId)->where('locked_team', $war->team_b)->count();
        $teamLimit = (int)($war->team_limit ?? 0);
        $teamAFull = $teamLimit > 0 && $teamAMembers >= $teamLimit;
        $teamBFull = $teamLimit > 0 && $teamBMembers >= $teamLimit;
        $teamACountLabel = $teamLimit > 0 ? $teamAMembers . ' / ' . $teamLimit : (string)$teamAMembers;
        $teamBCountLabel = $teamLimit > 0 ? $teamBMembers . ' / ' . $teamLimit : (string)$teamBMembers;
        $joinAvailable = !$assignment && $war->overlay_join_enabled && in_array($war->status, [WarState::DRAFT, WarState::ACTIVE], true);
        $confirmTeam = self::$confirmTeams[$player->Login] ?? '';
        $detailMap = null;
        $detailTeamARows = collect();
        $detailTeamBRows = collect();
        $detailTeamAPoints = 0;
        $detailTeamBPoints = 0;
        if ($tab === 'map-detail') {
            $selectedUid = self::$selectedMapUids[$player->Login] ?? '';
            $detailMap = $mapRows->firstWhere('uid', $selectedUid);
            if (!$detailMap) {
                $tab = 'maps';
            } else {
                $detailRecords = DB::table('war-records')->where('war_id', $warId)
                    ->where('map_uid', $selectedUid)->orderBy('rank')->orderBy('record_time')->get()
                    ->map(static function ($record) {
                        $record->formatted_time = self::formatTime((int)$record->record_time);
                        return $record;
                    });
                $detailTeamARows = $detailRecords->where('team', $war->team_a)->take(8)->values();
                $detailTeamBRows = $detailRecords->where('team', $war->team_b)->take(8)->values();
                $detailTeamAPoints = (int)$detailMap->team_a_points;
                $detailTeamBPoints = (int)$detailMap->team_b_points;
            }
        }
        self::$openTabs[$player->Login] = $tab;

        Template::hide($player, 'WarManagerPlayers');
        WarAdminOverlay::close($player);

        Template::show($player, 'WarManager.stats', compact(
            'tab', 'war', 'teamAPoints', 'teamBPoints', 'teamAColor', 'teamBColor', 'timeLeft',
            'playerRows', 'playerPage', 'playerPageCount', 'visibleMapRows', 'mapPage', 'mapPageCount',
            'scoredMapCount', 'mapCount', 'rotationNumber', 'rotationPosition', 'assignment', 'teamAMembers', 'teamBMembers', 'teamLimit',
            'teamAFull', 'teamBFull', 'joinAvailable', 'confirmTeam', 'teamARows', 'teamBRows',
            'currentMapName', 'currentMapValid', 'displayStatus', 'historyRows', 'teamABestPlayer',
            'teamBBestPlayer', 'teamAMapWins', 'teamBMapWins', 'teamARecordCount', 'teamBRecordCount',
            'teamACountLabel', 'teamBCountLabel', 'detailMap', 'detailTeamARows', 'detailTeamBRows',
            'detailTeamAPoints', 'detailTeamBPoints'
        ));
    }

    public static function refreshOpen(): void
    {
        foreach (self::$openTabs as $login => $tab) {
            $player = onlinePlayers()->where('Login', $login)->first();
            if ($player) {
                $tab === 'players-panel' ? self::players($player) : self::show($player, $tab);
            } else {
                unset(self::$openTabs[$login], self::$playerPages[$login], self::$mapPages[$login], self::$confirmTeams[$login], self::$selectedMapUids[$login]);
            }
        }
    }

    public static function close(Player $player): void
    {
        unset(self::$openTabs[$player->Login], self::$playerPages[$player->Login], self::$mapPages[$player->Login], self::$confirmTeams[$player->Login], self::$selectedMapUids[$player->Login]);
        Template::hide($player, 'WarManagerStats');
    }

    public static function overview(Player $player): void { self::show($player, 'overview'); }
    public static function teams(Player $player): void { self::show($player, 'teams'); }
    public static function playerTab(Player $player): void { self::show($player, 'players'); }
    public static function maps(Player $player): void { self::show($player, 'maps'); }
    public static function stats(Player $player): void { self::show($player, 'players'); }

    public static function players(Player $player): void
    {
        $state = WarViewState::latest();
        if (!$state) {
            infoMessage('There is no current war.')->send($player);
            return;
        }
        $war = $state->war;
        $rows = DB::table('war-players')->where('war_id', $war->id)
            ->orderByDesc('total_points')->orderBy('display_name')->get();
        $teamARows = $rows->where('locked_team', $war->team_a)->take(10)->values();
        $teamBRows = $rows->where('locked_team', $war->team_b)->take(10)->values();
        $teamAMembers = $rows->where('locked_team', $war->team_a)->count();
        $teamBMembers = $rows->where('locked_team', $war->team_b)->count();
        $assignment = $rows->where('player_login', $player->Login)->first();
        $teamLimit = (int)($war->team_limit ?? 0);
        $teamAFull = $teamLimit > 0 && $teamAMembers >= $teamLimit;
        $teamBFull = $teamLimit > 0 && $teamBMembers >= $teamLimit;
        $joinAvailable = !$assignment && $war->overlay_join_enabled && in_array($war->status, [WarState::DRAFT, WarState::ACTIVE], true);
        $confirmTeam = self::$confirmTeams[$player->Login] ?? '';
        $currentLogin = $player->Login;
        self::$openTabs[$player->Login] = 'players-panel';
        Template::hide($player, 'WarManagerStats');
        WarAdminOverlay::close($player);
        Template::show($player, 'WarManager.players', compact(
            'war', 'teamARows', 'teamBRows', 'teamAMembers', 'teamBMembers', 'assignment',
            'joinAvailable', 'teamAFull', 'teamBFull', 'confirmTeam', 'currentLogin'
        ));
    }

    public static function closePlayers(Player $player): void
    {
        unset(self::$openTabs[$player->Login], self::$confirmTeams[$player->Login]);
        Template::hide($player, 'WarManagerPlayers');
    }

    public static function openAdmin(Player $player): void
    {
        if (!$player->hasAccess('war_manage')) {
            dangerMessage('You are not allowed to open the WarManager admin panel.')->send($player);
            return;
        }
        self::close($player);
        self::closePlayers($player);
        WarAdminOverlay::show($player);
    }

    public static function joinTeamA(Player $player): void { self::confirmTeamA($player); }
    public static function joinTeamB(Player $player): void { self::confirmTeamB($player); }

    public static function confirmTeamA(Player $player): void
    {
        $war = WarRepository::current();
        self::$confirmTeams[$player->Login] = $war ? $war->team_a : '';
        self::show($player, 'teams');
    }

    public static function confirmTeamB(Player $player): void
    {
        $war = WarRepository::current();
        self::$confirmTeams[$player->Login] = $war ? $war->team_b : '';
        self::show($player, 'teams');
    }

    public static function cancelTeamChange(Player $player): void
    {
        unset(self::$confirmTeams[$player->Login]);
        self::show($player, 'teams');
    }

    public static function confirmTeamChange(Player $player): void
    {
        $team = self::$confirmTeams[$player->Login] ?? '';
        unset(self::$confirmTeams[$player->Login]);
        self::joinSelectedTeam($player, $team);
    }

    public static function previousPlayerPage(Player $player): void
    {
        self::$playerPages[$player->Login] = max(1, (int)(self::$playerPages[$player->Login] ?? 1) - 1);
        self::show($player, 'players');
    }

    public static function nextPlayerPage(Player $player): void
    {
        self::$playerPages[$player->Login] = (int)(self::$playerPages[$player->Login] ?? 1) + 1;
        self::show($player, 'players');
    }

    public static function previousMapPage(Player $player): void
    {
        self::$mapPages[$player->Login] = max(1, (int)(self::$mapPages[$player->Login] ?? 1) - 1);
        self::show($player, 'maps');
    }

    public static function nextMapPage(Player $player): void
    {
        self::$mapPages[$player->Login] = (int)(self::$mapPages[$player->Login] ?? 1) + 1;
        self::show($player, 'maps');
    }

    public static function mapDetails1(Player $player): void { self::openMapDetailsAt($player, 1); }
    public static function mapDetails2(Player $player): void { self::openMapDetailsAt($player, 2); }
    public static function mapDetails3(Player $player): void { self::openMapDetailsAt($player, 3); }
    public static function mapDetails4(Player $player): void { self::openMapDetailsAt($player, 4); }
    public static function mapDetails5(Player $player): void { self::openMapDetailsAt($player, 5); }
    public static function mapDetails6(Player $player): void { self::openMapDetailsAt($player, 6); }
    public static function mapDetails7(Player $player): void { self::openMapDetailsAt($player, 7); }
    public static function mapDetails8(Player $player): void { self::openMapDetailsAt($player, 8); }
    public static function mapDetails9(Player $player): void { self::openMapDetailsAt($player, 9); }
    public static function mapDetails10(Player $player): void { self::openMapDetailsAt($player, 10); }

    private static function openMapDetailsAt(Player $player, int $position): void
    {
        $state = WarViewState::latest();
        if (!$state) {
            return;
        }
        $offset = ((int)(self::$mapPages[$player->Login] ?? 1) - 1) * self::ROWS_PER_PAGE + $position - 1;
        $map = DB::table('war-maps')->where('war_id', $state->war->id)
            ->orderBy('position')->orderBy('id')->skip($offset)->first();
        if ($map) {
            self::$selectedMapUids[$player->Login] = $map->map_uid;
            self::show($player, 'map-detail');
        }
    }

    private static function formatTime(int $milliseconds): string
    {
        $minutes = intdiv($milliseconds, 60000);
        $seconds = intdiv($milliseconds % 60000, 1000);
        $millis = $milliseconds % 1000;
        return sprintf('%d:%02d.%03d', $minutes, $seconds, $millis);
    }

    private static function joinSelectedTeam(Player $player, string $team): void
    {
        try {
            $newName = PlayerNameService::joinWithWarName($player, $team);
            infoMessage('Joined team ', $team, '. Name changed to ', $newName, '.')->send($player);
        } catch (Throwable $error) {
            dangerMessage($error->getMessage())->send($player);
        }
        self::show($player, 'overview');
    }

}
