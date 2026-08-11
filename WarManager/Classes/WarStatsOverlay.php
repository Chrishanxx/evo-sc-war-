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
    private static array $confirmTeams = [];

    public static function showWidget(Player $player): void
    {
        $state = WarViewState::latest();
        if (!$state) {
            Template::hide($player, 'WarManager');
            return;
        }
        $war = $state->war;
        $teamAPoints = $state->team_a_points;
        $teamBPoints = $state->team_b_points;
        $teamAColor = $state->team_a_color;
        $teamBColor = $state->team_b_color;
        $teamAScoreColor = $teamAPoints > $teamBPoints ? 'D8D184FF' : 'FFFFFFFF';
        $teamBScoreColor = $teamBPoints > $teamAPoints ? 'D8D184FF' : 'FFFFFFFF';
        $widgetTitle = $war->status === WarState::ACTIVE ? 'WAR' : 'WAR · ' . $war->status;
        Template::show($player, 'WarManager.overview', compact(
            'war', 'teamAPoints', 'teamBPoints', 'teamAColor', 'teamBColor',
            'teamAScoreColor', 'teamBScoreColor', 'widgetTitle'
        ));
    }

    public static function show(Player $player, string $tab = 'overview'): void
    {
        $tab = in_array($tab, ['overview', 'players', 'maps'], true) ? $tab : 'overview';
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
        $mapRows = $maps->map(static function ($map) use ($mapScores, $scoredMapUids, $currentMapUid, $war) {
            $scores = $mapScores->where('map_uid', $map->map_uid)->pluck('points', 'team');
            return (object)[
                'name' => $map->map_name,
                'team_a_points' => (int)($scores[$war->team_a] ?? 0),
                'team_b_points' => (int)($scores[$war->team_b] ?? 0),
                'status' => $map->map_uid === $currentMapUid ? '[>]' :
                    (in_array($map->map_uid, $scoredMapUids, true) ? '[x]' : '[ ]'),
            ];
        });
        $players = DB::table('war-players')->where('war_id', $warId)
            ->orderByDesc('total_points')->orderBy('display_name')->get()
            ->map(static function ($entry) use ($war, $teamAColor, $teamBColor) {
                $entry->team_color = $entry->locked_team === $war->team_a ? $teamAColor : $teamBColor;
                return $entry;
            });

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
        $joinAvailable = !$assignment && $war->overlay_join_enabled && $war->status === WarState::ACTIVE;
        $confirmTeam = self::$confirmTeams[$player->Login] ?? '';
        self::$openTabs[$player->Login] = $tab;

        Template::show($player, 'WarManager.stats', compact(
            'tab', 'war', 'teamAPoints', 'teamBPoints', 'teamAColor', 'teamBColor', 'timeLeft',
            'playerRows', 'playerPage', 'playerPageCount', 'visibleMapRows', 'mapPage', 'mapPageCount',
            'scoredMapCount', 'mapCount', 'rotationNumber', 'rotationPosition', 'assignment', 'teamAMembers', 'teamBMembers', 'teamLimit',
            'teamAFull', 'teamBFull', 'joinAvailable', 'confirmTeam'
        ));
    }

    public static function refreshOpen(): void
    {
        foreach (self::$openTabs as $login => $tab) {
            $player = onlinePlayers()->where('Login', $login)->first();
            if ($player) {
                self::show($player, $tab);
            } else {
                unset(self::$openTabs[$login], self::$playerPages[$login], self::$mapPages[$login], self::$confirmTeams[$login]);
            }
        }
    }

    public static function close(Player $player): void
    {
        unset(self::$openTabs[$player->Login], self::$playerPages[$player->Login], self::$mapPages[$player->Login], self::$confirmTeams[$player->Login]);
        Template::hide($player, 'WarManagerStats');
    }

    public static function overview(Player $player): void { self::show($player, 'overview'); }
    public static function players(Player $player): void { self::show($player, 'players'); }
    public static function maps(Player $player): void { self::show($player, 'maps'); }

    public static function joinTeamA(Player $player): void { self::confirmTeamA($player); }
    public static function joinTeamB(Player $player): void { self::confirmTeamB($player); }

    public static function confirmTeamA(Player $player): void
    {
        $war = WarRepository::current();
        self::$confirmTeams[$player->Login] = $war ? $war->team_a : '';
        self::show($player, 'overview');
    }

    public static function confirmTeamB(Player $player): void
    {
        $war = WarRepository::current();
        self::$confirmTeams[$player->Login] = $war ? $war->team_b : '';
        self::show($player, 'overview');
    }

    public static function cancelTeamChange(Player $player): void
    {
        unset(self::$confirmTeams[$player->Login]);
        self::show($player, 'overview');
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
