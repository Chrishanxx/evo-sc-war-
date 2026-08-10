<?php

namespace EvoSC\Modules\Scrim;

use EvoSC\Classes\ChatCommand;
use EvoSC\Classes\DB;
use EvoSC\Classes\Hook;
use EvoSC\Classes\ManiaLinkEvent;
use EvoSC\Classes\Module;
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
        foreach (['scrim_view', 'scrim_manage', 'scrim_start', 'scrim_maps', 'scrim_points', 'scrim_players'] as $right) {
            AccessRight::add($right, 'Scrim module: ' . $right);
        }
        ChatCommand::add('/scrim', [self::class, 'playerCommand'], 'Show scrim status.');
        ChatCommand::add('/score', [self::class, 'playerCommand'], 'Show scrim status.');
        ChatCommand::add('//scrim', [self::class, 'adminCommand'], 'Manage the current scrim.', 'scrim_manage');
        Hook::add('PlayerLocalRecord', [self::class, 'localRecord']);
        Hook::add('BeginMap', [self::class, 'tick']);
        Hook::add('PlayerConnect', [self::class, 'playerConnect']);
        ManiaLinkEvent::add('scrim.show', [self::class, 'show']);

        if (config('scrim.show-quick-button', true)) {
            QuickButtons::addButton('⚔', 'SCRIM', 'scrim.show');
        }
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
        $scrim = ScrimRepository::current();
        if (!$scrim) {
            infoMessage('There is no current scrim.')->send($player);
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

    public static function adminCommand(Player $player, $cmd, ...$args): void
    {
        try {
            $action = strtolower(array_shift($args) ?? 'status');
            if ($action === 'create') {
                $days = (int)array_shift($args); $teamA = (string)array_shift($args); $teamB = (string)array_shift($args);
                ScrimRepository::create($player, $days, $teamA, $teamB, trim(implode(' ', $args)));
            } elseif (in_array($action, ['start', 'resume', 'pause', 'finish', 'cancel'], true)) {
                $states = ['start' => ScrimState::ACTIVE, 'resume' => ScrimState::ACTIVE, 'pause' => ScrimState::PAUSED,
                    'finish' => ScrimState::FINISHED, 'cancel' => ScrimState::CANCELLED];
                ScrimRepository::transition($player, $states[$action]);
            } elseif ($action === 'map') {
                $operation = strtolower(array_shift($args) ?? ''); $uid = (string)array_shift($args);
                $operation === 'add' ? ScrimRepository::addMap($player, $uid, trim(implode(' ', $args))) : ScrimRepository::removeMap($player, $uid);
            } elseif ($action === 'points') {
                ScrimRepository::setPoints($player, (int)($args[0] ?? 0), (int)($args[1] ?? -1));
            }
            self::show($player);
        } catch (Throwable $error) {
            dangerMessage($error->getMessage())->send($player);
        }
    }
}
