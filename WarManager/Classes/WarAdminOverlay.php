<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Classes\Template;
use EvoSC\Models\Player;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class WarAdminOverlay
{
    private const MAPS_PER_PAGE = 8;

    private static array $openTabs = [];
    private static array $mapPages = [];

    public static function show(Player $player, string $tab = 'overview', string $confirmAction = ''): void
    {
        $allowedTabs = ['overview', 'create', 'maps', 'points', 'players', 'logs'];
        $tab = in_array($tab, $allowedTabs, true) ? $tab : 'overview';
        $war = WarRepository::latest();
        $current = WarRepository::current();
        $warId = $war ? (int)$war->id : 0;
        $maps = $warId ? DB::table('war-maps')->where('war_id', $warId)->orderBy('id')->get() : new Collection();
        $players = $warId ? DB::table('war-players')->where('war_id', $warId)
            ->orderBy('locked_team')->orderByDesc('total_points')->get() : new Collection();
        $points = $warId ? DB::table('war-points')->where('war_id', $warId)->orderBy('rank')->limit(16)->get() : new Collection();
        $logs = $warId ? DB::table('war-admin-log')->where('war_id', $warId)
            ->orderByDesc('id')->limit(8)->get() : new Collection();
        $teamScores = $warId ? DB::table('war-players')->where('war_id', $warId)
            ->selectRaw('locked_team, SUM(total_points) points')->groupBy('locked_team')->pluck('points', 'locked_team') : new Collection();
        $teamAPoints = $war ? (int)($teamScores[$war->team_a] ?? 0) : 0;
        $teamBPoints = $war ? (int)($teamScores[$war->team_b] ?? 0) : 0;
        $clockTime = $war && $war->status === WarState::PAUSED && $war->paused_at
            ? strtotime($war->paused_at . ' UTC')
            : time();
        $secondsLeft = $war && $war->end_at ? max(0, strtotime($war->end_at . ' UTC') - $clockTime) : 0;
        $timeLeft = floor($secondsLeft / 86400) . 'd ' . floor(($secondsLeft % 86400) / 3600) . 'h ' . floor(($secondsLeft % 3600) / 60) . 'm';
        $selectedUids = $maps->pluck('map_uid')->all();
        $serverMapCount = DB::table('maps')->count();
        $mapPageCount = max(1, (int)ceil($serverMapCount / self::MAPS_PER_PAGE));
        $mapPage = max(1, min((int)(self::$mapPages[$player->Login] ?? 1), $mapPageCount));
        self::$mapPages[$player->Login] = $mapPage;
        $serverMaps = DB::table('maps')->orderBy('name')
            ->offset(($mapPage - 1) * self::MAPS_PER_PAGE)->limit(self::MAPS_PER_PAGE)->get()
            ->map(static function ($map) use ($selectedUids) {
            $map->selected = in_array($map->uid, $selectedUids, true);
            return $map;
        });
        $unassigned = new Collection();
        if ($current) {
            foreach (onlinePlayers() as $online) {
                if (!TeamDetector::detect($online->NickName, $current->team_a, $current->team_b)) {
                    $unassigned->push($online);
                }
            }
        }
        $ready = $current && $current->status === WarState::DRAFT && $maps->isNotEmpty() && $points->count() === 16;
        self::$openTabs[$player->Login] = $tab;

        Template::show($player, 'WarManager.admin', compact(
            'tab', 'war', 'current', 'maps', 'players', 'points', 'logs', 'serverMaps', 'unassigned',
            'teamAPoints', 'teamBPoints', 'timeLeft', 'ready', 'confirmAction', 'serverMapCount',
            'mapPage', 'mapPageCount'
        ));
    }

    public static function refreshOpen(): void
    {
        foreach (self::$openTabs as $login => $tab) {
            $player = onlinePlayers()->where('Login', $login)->first();
            if ($player) {
                self::show($player, $tab);
            } else {
                unset(self::$openTabs[$login]);
            }
        }
    }

    public static function close(Player $player): void
    {
        unset(self::$openTabs[$player->Login]);
        unset(self::$mapPages[$player->Login]);
        Template::hide($player, 'WarManagerAdmin');
    }

    public static function overview(Player $player): void { self::show($player, 'overview'); }
    public static function createTab(Player $player): void { self::show($player, 'create'); }
    public static function maps(Player $player): void { self::show($player, 'maps'); }
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
    public static function points(Player $player): void { self::show($player, 'points'); }
    public static function players(Player $player): void { self::show($player, 'players'); }
    public static function logs(Player $player): void { self::show($player, 'logs'); }

    public static function createWar(Player $player, $values): void
    {
        self::run($player, 'create', static function () use ($player, $values): void {
            self::requireValues($values);
            WarRepository::create($player, (int)($values->duration ?? 0), (string)($values->team_a ?? ''),
                (string)($values->team_b ?? ''), (string)($values->war_name ?? ''));
        }, 'War created. Add at least one map before starting.');
    }

    public static function saveSettings(Player $player, $values): void
    {
        self::run($player, 'create', static function () use ($player, $values): void {
            self::requireValues($values);
            WarRepository::updateTeams($player, (string)($values->team_a ?? ''), (string)($values->team_b ?? ''),
                (string)($values->war_name ?? ''));
            WarRepository::updateDuration($player, (int)($values->duration ?? 0));
        }, 'War settings saved.');
    }

    public static function addMap(Player $player, $values): void
    {
        self::run($player, 'maps', static function () use ($player, $values): void {
            self::requireValues($values);
            WarRepository::addMap($player, (string)($values->map_uid ?? ''), '');
        }, 'Map added to the war.');
    }

    public static function removeMapAt(Player $player, int $position): void
    {
        self::run($player, 'maps', static function () use ($player, $position): void {
            $war = WarRepository::current();
            $map = $war ? DB::table('war-maps')->where('war_id', $war->id)
                ->orderBy('id')->offset($position - 1)->first() : null;
            if (!$map) {
                throw new RuntimeException('The selected war map no longer exists.');
            }
            WarRepository::removeMap($player, $map->map_uid);
        }, 'Map removed from the war.');
    }

    public static function addServerMapAt(Player $player, int $position): void
    {
        self::run($player, 'maps', static function () use ($player, $position): void {
            $page = max(1, (int)(self::$mapPages[$player->Login] ?? 1));
            $map = DB::table('maps')->orderBy('name')
                ->offset(($page - 1) * self::MAPS_PER_PAGE + $position - 1)->first();
            if (!$map) {
                throw new RuntimeException('The selected server map no longer exists.');
            }
            WarRepository::addMap($player, $map->uid, $map->name);
        }, 'Map added to the war.');
    }

    public static function addServerMap1(Player $player): void { self::addServerMapAt($player, 1); }
    public static function addServerMap2(Player $player): void { self::addServerMapAt($player, 2); }
    public static function addServerMap3(Player $player): void { self::addServerMapAt($player, 3); }
    public static function addServerMap4(Player $player): void { self::addServerMapAt($player, 4); }
    public static function addServerMap5(Player $player): void { self::addServerMapAt($player, 5); }
    public static function addServerMap6(Player $player): void { self::addServerMapAt($player, 6); }
    public static function addServerMap7(Player $player): void { self::addServerMapAt($player, 7); }
    public static function addServerMap8(Player $player): void { self::addServerMapAt($player, 8); }
    public static function removeMap1(Player $player): void { self::removeMapAt($player, 1); }
    public static function removeMap2(Player $player): void { self::removeMapAt($player, 2); }
    public static function removeMap3(Player $player): void { self::removeMapAt($player, 3); }
    public static function removeMap4(Player $player): void { self::removeMapAt($player, 4); }
    public static function removeMap5(Player $player): void { self::removeMapAt($player, 5); }
    public static function removeMap6(Player $player): void { self::removeMapAt($player, 6); }
    public static function removeMap7(Player $player): void { self::removeMapAt($player, 7); }

    public static function savePoints(Player $player, $values): void
    {
        self::run($player, 'points', static function () use ($player, $values): void {
            self::requireValues($values);
            $profile = [];
            for ($rank = 1; $rank <= 16; $rank++) {
                $profile[] = (int)($values->{'point_' . $rank} ?? -1);
            }
            WarRepository::setPointProfile($player, $profile);
        }, 'Point system saved.');
    }

    public static function linearPoints(Player $player): void
    {
        self::run($player, 'points', static function () use ($player): void {
            WarRepository::setPointProfile($player, range(20, 5));
        }, 'Linear point preset applied.');
    }

    public static function competitivePoints(Player $player): void
    {
        self::run($player, 'points', static function () use ($player): void {
            WarRepository::setPointProfile($player, [25, 20, 16, 13, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0]);
        }, 'Competitive point preset applied.');
    }

    public static function confirmStart(Player $player): void { self::show($player, 'overview', 'start'); }
    public static function confirmPause(Player $player): void { self::show($player, 'overview', 'pause'); }
    public static function confirmResume(Player $player): void { self::show($player, 'overview', 'resume'); }
    public static function confirmFinish(Player $player): void { self::show($player, 'overview', 'finish'); }
    public static function confirmCancel(Player $player): void { self::show($player, 'overview', 'cancel'); }
    public static function startWar(Player $player): void { self::transition($player, WarState::ACTIVE, 'War started.'); }
    public static function pauseWar(Player $player): void { self::transition($player, WarState::PAUSED, 'War paused.'); }
    public static function resumeWar(Player $player): void { self::transition($player, WarState::ACTIVE, 'War resumed.'); }
    public static function finishWar(Player $player): void { self::transition($player, WarState::FINISHED, 'War finished.'); }
    public static function cancelWar(Player $player): void { self::transition($player, WarState::CANCELLED, 'War cancelled.'); }

    private static function transition(Player $player, string $state, string $message): void
    {
        self::run($player, 'overview', static function () use ($player, $state): void {
            WarRepository::transition($player, $state);
        }, $message);
    }

    private static function run(Player $player, string $tab, callable $action, string $success): void
    {
        try {
            $action();
            infoMessage($success)->send($player);
        } catch (Throwable $error) {
            dangerMessage($error->getMessage())->send($player);
        }
        self::show($player, $tab);
    }

    private static function requireValues($values): void
    {
        if (!is_object($values)) {
            throw new RuntimeException('Form values are missing.');
        }
    }
}
