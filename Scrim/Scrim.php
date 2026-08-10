<?php

namespace EvoSC\Modules\Scrim;

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
use EvoSC\Modules\Scrim\Classes\RecordService;
use EvoSC\Modules\Scrim\Classes\ScrimRepository;
use EvoSC\Modules\Scrim\Classes\ScrimState;
use Throwable;

class Scrim extends Module implements ModuleInterface
{
    public static function start(string $mode, bool $isBoot = false)
    {
        if (isManiaPlanet() || !ModeController::isTimeAttackType()) {
            return;
        }
        foreach (['scrim_view', 'scrim_manage', 'scrim_start', 'scrim_maps', 'scrim_points', 'scrim_players'] as $right) {
            AccessRight::add($right, 'Scrim module: ' . $right);
        }
        ChatCommand::add('/scrim', [self::class, 'playerCommand'], 'Show scrim status.');
        ChatCommand::add('/score', [self::class, 'playerCommand'], 'Show scrim status.');
        ChatCommand::add('//scrim', [self::class, 'adminCommand'], 'Manage the current scrim.');
        Hook::add('PlayerLocalRecord', [self::class, 'localRecord']);
        Hook::add('BeginMap', [self::class, 'tick']);
        Hook::add('PlayerConnect', [self::class, 'playerConnect']);
        ManiaLinkEvent::add('scrim.show', [self::class, 'show']);

        if (config('scrim.show-quick-button', true) && config('quick-buttons.enabled', true)) {
            QuickButtons::addButton('⚔', 'SCRIM', 'scrim.show');
        }
        Timer::create('scrim.check_expiration', [self::class, 'tick'], '30s', true);
        self::tick();
    }

    public static function tick(): void
    {
        if (ScrimRepository::finishExpired()) {
            infoMessage('The scrim has finished. Final results are now frozen.')->sendAll();
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
        $scrim = ScrimRepository::latest();
        if (!$scrim) {
            infoMessage('There is no current scrim.')->send($player);
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
        $scores = DB::table('scrim-players')->where('scrim_id', $scrim->id)
            ->selectRaw('locked_team, SUM(total_points) points')->groupBy('locked_team')->pluck('points', 'locked_team');
        $a = (int)($scores[$scrim->team_a] ?? 0);
        $b = (int)($scores[$scrim->team_b] ?? 0);
        $left = $scrim->end_at ? max(0, strtotime($scrim->end_at . ' UTC') - time()) : 0;
        infoMessage($scrim->name, ': ', $scrim->team_a, " {$a} : {$b} ", $scrim->team_b,
            ' | ', $scrim->status, $left ? ' | ' . floor($left / 86400) . 'd ' . floor(($left % 86400) / 3600) . 'h left' : '')->send($player);
    }

    private static function showMaps(Player $player, $scrim): void
    {
        $maps = DB::table('scrim-maps')->where('scrim_id', $scrim->id)->orderBy('id')->get();
        if ($maps->isEmpty()) {
            infoMessage('The scrim map pool is empty.')->send($player);
            return;
        }
        $scores = DB::table('scrim-records')->where('scrim_id', $scrim->id)
            ->selectRaw('map_uid, team, SUM(points) points')->groupBy('map_uid', 'team')->get();
        foreach ($maps as $map) {
            $mapScores = $scores->where('map_uid', $map->map_uid)->pluck('points', 'team');
            infoMessage($map->map_name, ': ', $scrim->team_a, ' ', (int)($mapScores[$scrim->team_a] ?? 0),
                ' : ', (int)($mapScores[$scrim->team_b] ?? 0), ' ', $scrim->team_b)->send($player);
        }
    }

    private static function showPlayer(Player $player, $scrim): void
    {
        $entry = DB::table('scrim-players')->where('scrim_id', $scrim->id)
            ->where('player_login', $player->Login)->first();
        if (!$entry) {
            infoMessage('You do not have a scored record in this scrim yet.')->send($player);
            return;
        }
        infoMessage($entry->display_name, ' | Team ', $entry->locked_team, ' | ', (int)$entry->total_points, ' points')->send($player);
    }

    public static function adminCommand(Player $player, $cmd, ...$args): void
    {
        try {
            $action = strtolower(array_shift($args) ?? 'status');
            if ($action === 'create') {
                self::requireAccess($player, 'scrim_manage');
                $days = (int)array_shift($args); $teamA = (string)array_shift($args); $teamB = (string)array_shift($args);
                ScrimRepository::create($player, $days, $teamA, $teamB, trim(implode(' ', $args)));
            } elseif (in_array($action, ['start', 'resume', 'pause', 'finish', 'cancel'], true)) {
                self::requireAccess($player, 'scrim_start');
                $states = ['start' => ScrimState::ACTIVE, 'resume' => ScrimState::ACTIVE, 'pause' => ScrimState::PAUSED,
                    'finish' => ScrimState::FINISHED, 'cancel' => ScrimState::CANCELLED];
                ScrimRepository::transition($player, $states[$action]);
            } elseif ($action === 'map') {
                self::requireAccess($player, 'scrim_maps');
                $operation = strtolower(array_shift($args) ?? ''); $uid = (string)array_shift($args);
                if ($operation === 'add') {
                    ScrimRepository::addMap($player, $uid, trim(implode(' ', $args)));
                } elseif ($operation === 'remove') {
                    ScrimRepository::removeMap($player, $uid);
                } else {
                    throw new \RuntimeException('Usage: //scrim map add|remove <MapUID>');
                }
            } elseif ($action === 'points') {
                self::requireAccess($player, 'scrim_points');
                ScrimRepository::setPoints($player, (int)($args[0] ?? 0), (int)($args[1] ?? -1));
            } elseif ($action === 'status') {
                self::requireAccess($player, 'scrim_manage');
            } else {
                throw new \RuntimeException('Unknown scrim command. Use create, start, pause, resume, finish, cancel, map, points or status.');
            }
            self::show($player);
        } catch (Throwable $error) {
            dangerMessage($error->getMessage())->send($player);
        }
    }

    private static function requireAccess(Player $player, string $right): void
    {
        if (!$player->hasAccess($right)) {
            throw new \RuntimeException('You are not allowed to perform this scrim action.');
        }
    }
}
