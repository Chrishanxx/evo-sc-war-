<?php

namespace EvoSC\Modules\WarManager;

use EvoSC\Classes\ChatCommand;
use EvoSC\Classes\DB;
use EvoSC\Classes\Hook;
use EvoSC\Classes\ManiaLinkEvent;
use EvoSC\Classes\Module;
use EvoSC\Classes\Timer;
use EvoSC\Controllers\ModeController;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Models\AccessRight;
use EvoSC\Models\Player;
use EvoSC\Modules\QuickButtons\QuickButtons;
use EvoSC\Modules\WarManager\Classes\RecordService;
use EvoSC\Modules\WarManager\Classes\WarRepository;
use EvoSC\Modules\WarManager\Classes\WarState;
use Throwable;

class WarManager extends Module implements ModuleInterface
{
    public static function start(string $mode, bool $isBoot = false)
    {
        if (isManiaPlanet() || !ModeController::isTimeAttackType()) {
            return;
        }
        foreach (['war_view', 'war_manage', 'war_start', 'war_maps', 'war_points', 'war_players'] as $right) {
            AccessRight::add($right, 'WarManager module: ' . $right);
        }
        ChatCommand::add('/war', [self::class, 'playerCommand'], 'Show war status.');
        ChatCommand::add('//war', [self::class, 'adminCommand'], 'Manage the current war.');
        Hook::add('PlayerLocalRecord', [self::class, 'localRecord']);
        Hook::add('BeginMap', [self::class, 'tick']);
        Hook::add('PlayerConnect', [self::class, 'playerConnect']);
        ManiaLinkEvent::add('war.show', [self::class, 'show']);

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
        self::show($player, strtolower($args[0] ?? 'overall'));
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
            if ($action === 'create') {
                self::requireAccess($player, 'war_manage');
                $days = (int)array_shift($args); $teamA = (string)array_shift($args); $teamB = (string)array_shift($args);
                WarRepository::create($player, $days, $teamA, $teamB, trim(implode(' ', $args)));
            } elseif (in_array($action, ['start', 'resume', 'pause', 'finish', 'cancel'], true)) {
                self::requireAccess($player, 'war_start');
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
            } elseif ($action === 'status') {
                self::requireAccess($player, 'war_manage');
            } else {
                throw new \RuntimeException('Unknown war command. Use create, start, pause, resume, finish, cancel, map, points or status.');
            }
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
