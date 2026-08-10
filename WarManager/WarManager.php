<?php

namespace EvoSC\Modules\WarManager;

use EvoSC\Classes\ChatCommand;
use EvoSC\Classes\DB;
use EvoSC\Classes\Hook;
use EvoSC\Classes\ManiaLinkEvent;
use EvoSC\Classes\Module;
use EvoSC\Classes\Template;
use EvoSC\Classes\Timer;
use EvoSC\Controllers\ModeController;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Models\AccessRight;
use EvoSC\Models\Player;
use EvoSC\Modules\QuickButtons\QuickButtons;
use EvoSC\Modules\WarManager\Classes\RecordService;
use EvoSC\Modules\WarManager\Classes\WarAdminOverlay;
use EvoSC\Modules\WarManager\Classes\WarRepository;
use EvoSC\Modules\WarManager\Classes\WarState;
use Throwable;

class WarManager extends Module implements ModuleInterface
{
    private static array $overlayLogins = [];

    public static function start(string $mode, bool $isBoot = false)
    {
        if (isManiaPlanet() || !ModeController::isTimeAttackType()) {
            return;
        }
        foreach (['war_view', 'war_manage', 'war_start', 'war_pause', 'war_finish', 'war_cancel', 'war_maps', 'war_points', 'war_players'] as $right) {
            AccessRight::add($right, 'WarManager module: ' . $right);
        }
        ChatCommand::add('/war', [self::class, 'playerCommand'], 'Show war status.');
        ChatCommand::add('//war', [self::class, 'adminCommand'], 'Manage the current war.');
        Hook::add('PlayerLocalRecord', [self::class, 'localRecord']);
        Hook::add('BeginMap', [self::class, 'tick']);
        Hook::add('PlayerConnect', [self::class, 'playerConnect']);
        Hook::add('WarRecordUpdated', [self::class, 'refreshOverlays']);
        ManiaLinkEvent::add('war.show', [self::class, 'showOverlay']);
        ManiaLinkEvent::add('war.close', [self::class, 'closeOverlay']);
        ManiaLinkEvent::add('war.teams.save', [self::class, 'saveTeams'], 'war_manage');
        ManiaLinkEvent::add('war.admin.close', [WarAdminOverlay::class, 'close']);
        ManiaLinkEvent::add('war.admin.overview', [WarAdminOverlay::class, 'overview'], 'war_manage');
        ManiaLinkEvent::add('war.admin.create.tab', [WarAdminOverlay::class, 'createTab'], 'war_manage');
        ManiaLinkEvent::add('war.admin.maps', [WarAdminOverlay::class, 'maps'], 'war_maps');
        ManiaLinkEvent::add('war.admin.points', [WarAdminOverlay::class, 'points'], 'war_points');
        ManiaLinkEvent::add('war.admin.players', [WarAdminOverlay::class, 'players'], 'war_players');
        ManiaLinkEvent::add('war.admin.logs', [WarAdminOverlay::class, 'logs'], 'war_manage');
        ManiaLinkEvent::add('war.admin.create', [WarAdminOverlay::class, 'createWar'], 'war_manage');
        ManiaLinkEvent::add('war.admin.settings.save', [WarAdminOverlay::class, 'saveSettings'], 'war_manage');
        ManiaLinkEvent::add('war.admin.map.add', [WarAdminOverlay::class, 'addMap'], 'war_maps');
        ManiaLinkEvent::add('war.admin.map.add.server', [WarAdminOverlay::class, 'addServerMap'], 'war_maps');
        ManiaLinkEvent::add('war.admin.map.remove', [WarAdminOverlay::class, 'removeMap'], 'war_maps');
        ManiaLinkEvent::add('war.admin.maps.previous', [WarAdminOverlay::class, 'previousMapPage'], 'war_maps');
        ManiaLinkEvent::add('war.admin.maps.next', [WarAdminOverlay::class, 'nextMapPage'], 'war_maps');
        ManiaLinkEvent::add('war.admin.points.save', [WarAdminOverlay::class, 'savePoints'], 'war_points');
        ManiaLinkEvent::add('war.admin.points.linear', [WarAdminOverlay::class, 'linearPoints'], 'war_points');
        ManiaLinkEvent::add('war.admin.points.competitive', [WarAdminOverlay::class, 'competitivePoints'], 'war_points');
        ManiaLinkEvent::add('war.admin.control.start', [WarAdminOverlay::class, 'confirmStart'], 'war_start');
        ManiaLinkEvent::add('war.admin.control.pause', [WarAdminOverlay::class, 'confirmPause'], 'war_pause');
        ManiaLinkEvent::add('war.admin.control.resume', [WarAdminOverlay::class, 'confirmResume'], 'war_start');
        ManiaLinkEvent::add('war.admin.control.finish', [WarAdminOverlay::class, 'confirmFinish'], 'war_finish');
        ManiaLinkEvent::add('war.admin.control.cancel', [WarAdminOverlay::class, 'confirmCancel'], 'war_cancel');
        ManiaLinkEvent::add('war.admin.confirm.start', [WarAdminOverlay::class, 'startWar'], 'war_start');
        ManiaLinkEvent::add('war.admin.confirm.pause', [WarAdminOverlay::class, 'pauseWar'], 'war_pause');
        ManiaLinkEvent::add('war.admin.confirm.resume', [WarAdminOverlay::class, 'resumeWar'], 'war_start');
        ManiaLinkEvent::add('war.admin.confirm.finish', [WarAdminOverlay::class, 'finishWar'], 'war_finish');
        ManiaLinkEvent::add('war.admin.confirm.cancel', [WarAdminOverlay::class, 'cancelWar'], 'war_cancel');

        if (config('war-manager.show-quick-button', true) && config('quick-buttons.enabled', true)) {
            QuickButtons::addButton('⚔', 'WAR', 'war.show');
        }
        Timer::create('war-manager.check_expiration', [self::class, 'tick'], '30s', true);
        self::tick();
    }

    public static function tick(): void
    {
        if (WarRepository::finishExpired()) {
            infoMessage('The war has finished. Final results are now frozen.')->sendAll();
        }
        self::refreshOverlays();
    }

    public static function playerConnect(Player $player): void
    {
        self::tick();
    }

    public static function localRecord(Player $player, int $score): void
    {
        RecordService::record($player, $score);
    }

    public static function playerCommand(Player $player, $cmd, ...$args): void
    {
        if (!$args || strtolower($args[0]) === 'overlay') {
            self::showOverlay($player);
            return;
        }
        self::show($player, strtolower($args[0]));
    }

    public static function showOverlay(Player $player): void
    {
        $war = WarRepository::latest();
        if (!$war) {
            infoMessage('There is no current war.')->send($player);
            return;
        }

        $teamScores = DB::table('war-players')->where('war_id', $war->id)
            ->selectRaw('locked_team, SUM(total_points) points')->groupBy('locked_team')->pluck('points', 'locked_team');
        $maps = DB::table('war-maps')->where('war_id', $war->id)->orderBy('id')->get();
        $mapScores = DB::table('war-records')->where('war_id', $war->id)
            ->selectRaw('map_uid, team, SUM(points) points')->groupBy('map_uid', 'team')->get();
        $players = DB::table('war-players')->where('war_id', $war->id)
            ->orderByDesc('total_points')->orderBy('display_name')
            ->limit((int)config('war-manager.overlay-player-limit', 5))->get();
        $secondsLeft = $war->end_at ? max(0, strtotime($war->end_at . ' UTC') - time()) : 0;
        $timeLeft = floor($secondsLeft / 86400) . 'd ' . floor(($secondsLeft % 86400) / 3600) . 'h';
        $canManage = $player->hasAccess('war_manage');
        self::$overlayLogins[$player->Login] = true;
        $teamAPoints = (int)($teamScores[$war->team_a] ?? 0);
        $teamBPoints = (int)($teamScores[$war->team_b] ?? 0);
        $mapRows = $maps->take((int)config('war-manager.overlay-map-limit', 5))->map(static function ($map) use ($mapScores, $war) {
            $scores = $mapScores->where('map_uid', $map->map_uid)->pluck('points', 'team');
            return (object)[
                'name' => $map->map_name,
                'team_a_points' => (int)($scores[$war->team_a] ?? 0),
                'team_b_points' => (int)($scores[$war->team_b] ?? 0),
            ];
        });

        Template::show($player, 'WarManager.overview', compact(
            'war', 'teamAPoints', 'teamBPoints', 'mapRows', 'players', 'timeLeft', 'canManage'
        ));
    }

    public static function closeOverlay(Player $player): void
    {
        unset(self::$overlayLogins[$player->Login]);
        Template::hide($player, 'WarManager');
    }

    public static function refreshOverlays(): void
    {
        foreach (array_keys(self::$overlayLogins) as $login) {
            $player = onlinePlayers()->where('Login', $login)->first();
            if ($player) {
                self::showOverlay($player);
            } else {
                unset(self::$overlayLogins[$login]);
            }
        }
        WarAdminOverlay::refreshOpen();
    }

    public static function saveTeams(Player $player, $values): void
    {
        try {
            if (!is_object($values)) {
                throw new \RuntimeException('Team form values are missing.');
            }
            WarRepository::updateTeams(
                $player,
                (string)($values->team_a ?? ''),
                (string)($values->team_b ?? ''),
                (string)($values->war_name ?? '')
            );
            infoMessage('War teams updated.')->send($player);
            self::showOverlay($player);
        } catch (Throwable $error) {
            dangerMessage($error->getMessage())->send($player);
        }
    }

    public static function show(Player $player, string $view = 'overall'): void
    {
        $scrim = WarRepository::latest();
        if (!$scrim) {
            infoMessage('There is no current war.')->send($player);
            return;
        }
        if ($view === 'maps') {
            self::showMaps($player, $scrim);
            return;
        }
        if ($view === 'me') {
            self::showPlayer($player, $scrim);
            return;
        }
        $scores = DB::table('war-players')->where('war_id', $scrim->id)
            ->selectRaw('locked_team, SUM(total_points) points')->groupBy('locked_team')->pluck('points', 'locked_team');
        $a = (int)($scores[$scrim->team_a] ?? 0);
        $b = (int)($scores[$scrim->team_b] ?? 0);
        $left = $scrim->end_at ? max(0, strtotime($scrim->end_at . ' UTC') - time()) : 0;
        infoMessage($scrim->name, ': ', $scrim->team_a, " {$a} : {$b} ", $scrim->team_b,
            ' | ', $scrim->status, $left ? ' | ' . floor($left / 86400) . 'd ' . floor(($left % 86400) / 3600) . 'h left' : '')->send($player);
    }

    private static function showMaps(Player $player, $scrim): void
    {
        $maps = DB::table('war-maps')->where('war_id', $scrim->id)->orderBy('id')->get();
        if ($maps->isEmpty()) {
            infoMessage('The war map pool is empty.')->send($player);
            return;
        }
        $scores = DB::table('war-records')->where('war_id', $scrim->id)
            ->selectRaw('map_uid, team, SUM(points) points')->groupBy('map_uid', 'team')->get();
        foreach ($maps as $map) {
            $mapScores = $scores->where('map_uid', $map->map_uid)->pluck('points', 'team');
            infoMessage($map->map_name, ': ', $scrim->team_a, ' ', (int)($mapScores[$scrim->team_a] ?? 0),
                ' : ', (int)($mapScores[$scrim->team_b] ?? 0), ' ', $scrim->team_b)->send($player);
        }
    }

    private static function showPlayer(Player $player, $scrim): void
    {
        $entry = DB::table('war-players')->where('war_id', $scrim->id)
            ->where('player_login', $player->Login)->first();
        if (!$entry) {
            infoMessage('You do not have a scored record in this war yet.')->send($player);
            return;
        }
        infoMessage($entry->display_name, ' | Team ', $entry->locked_team, ' | ', (int)$entry->total_points, ' points')->send($player);
    }

    public static function adminCommand(Player $player, $cmd, ...$args): void
    {
        try {
            $action = strtolower(array_shift($args) ?? 'status');
            if ($action === 'admin' || $action === 'overlay' || ($action === 'status' && !$args)) {
                self::requireAccess($player, 'war_manage');
                WarAdminOverlay::show($player);
                return;
            }
            if ($action === 'create') {
                self::requireAccess($player, 'war_manage');
                $days = (int)array_shift($args); $teamA = (string)array_shift($args); $teamB = (string)array_shift($args);
                WarRepository::create($player, $days, $teamA, $teamB, trim(implode(' ', $args)));
            } elseif (in_array($action, ['start', 'resume', 'pause', 'finish', 'cancel'], true)) {
                $rights = ['start' => 'war_start', 'resume' => 'war_start', 'pause' => 'war_pause',
                    'finish' => 'war_finish', 'cancel' => 'war_cancel'];
                self::requireAccess($player, $rights[$action]);
                $states = ['start' => WarState::ACTIVE, 'resume' => WarState::ACTIVE, 'pause' => WarState::PAUSED,
                    'finish' => WarState::FINISHED, 'cancel' => WarState::CANCELLED];
                WarRepository::transition($player, $states[$action]);
            } elseif ($action === 'map') {
                self::requireAccess($player, 'war_maps');
                $operation = strtolower(array_shift($args) ?? ''); $uid = (string)array_shift($args);
                if ($operation === 'add') {
                    WarRepository::addMap($player, $uid, trim(implode(' ', $args)));
                } elseif ($operation === 'remove') {
                    WarRepository::removeMap($player, $uid);
                } else {
                    throw new \RuntimeException('Usage: //war map add|remove <MapUID>');
                }
            } elseif ($action === 'points') {
                self::requireAccess($player, 'war_points');
                WarRepository::setPoints($player, (int)($args[0] ?? 0), (int)($args[1] ?? -1));
            } elseif ($action === 'teams') {
                self::requireAccess($player, 'war_manage');
                $teamA = (string)array_shift($args); $teamB = (string)array_shift($args);
                WarRepository::updateTeams($player, $teamA, $teamB, trim(implode(' ', $args)));
            } elseif ($action === 'status') {
                self::requireAccess($player, 'war_manage');
            } else {
                throw new \RuntimeException('Unknown war command. Use create, teams, start, pause, resume, finish, cancel, map, points or status.');
            }
            self::refreshOverlays();
            self::show($player);
        } catch (Throwable $error) {
            dangerMessage($error->getMessage())->send($player);
        }
    }

    private static function requireAccess(Player $player, string $right): void
    {
        if (!$player->hasAccess($right)) {
            throw new \RuntimeException('You are not allowed to perform this war action.');
        }
    }
}
