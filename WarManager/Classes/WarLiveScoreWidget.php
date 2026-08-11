<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Classes\Template;
use EvoSC\Controllers\MapController;
use EvoSC\Models\Player;

/**
 * Permanent compact HUD for a running war.
 *
 * This is deliberately independent from the large player and admin overlays.
 * Extending Components.widget-base in the template makes the widget participate
 * in EvoSC's shared grid, scale and Hide HUD while driving behaviour.
 */
final class WarLiveScoreWidget
{
    private const MANIALINK_ID = 'WarLiveScoreWidget';
    private static array $rendered = [];

    public static function mount(Player $player): void
    {
        // A reconnect creates a fresh Trackmania UI context even when the PHP
        // process still remembers this login from the previous connection.
        unset(self::$rendered[$player->Login]);
        self::show($player);
    }

    public static function show(Player $player): void
    {
        $state = WarViewState::latest();
        if (!$state || !in_array($state->war->status, [WarState::ACTIVE, WarState::PAUSED], true)) {
            self::hide($player);
            return;
        }

        $war = $state->war;
        $teamAPoints = $state->team_a_points;
        $teamBPoints = $state->team_b_points;
        $teamAColor = $state->team_a_color;
        $teamBColor = $state->team_b_color;
        $timeLeft = $state->time_left;
        $currentMap = MapController::getCurrentMap();
        $currentMapIsWarMap = $currentMap && DB::table('war-maps')
            ->where('war_id', $war->id)
            ->where('map_uid', $currentMap->uid)
            ->exists();
        $scoringPaused = $war->status === WarState::ACTIVE
            && (!empty($war->scoring_paused) || !$currentMapIsWarMap);

        if ($war->status === WarState::PAUSED) {
            $heading = 'SCRIM';
            $liveStatus = 'PAUSED';
            $statusColor = 'A6A6A6FF';
        } elseif ($scoringPaused) {
            $heading = 'SCRIM';
            $liveStatus = 'SCORING PAUSED';
            $statusColor = 'F59E0BFF';
        } else {
            $heading = 'ACTIVE SCRIM';
            $liveStatus = 'LIVE';
            $statusColor = 'E4DA72FF';
        }

        $data = compact(
            'war', 'teamAPoints', 'teamBPoints', 'teamAColor', 'teamBColor',
            'timeLeft', 'heading', 'liveStatus', 'statusColor'
        );

        if (!isset(self::$rendered[$player->Login])) {
            // Create the persistent widget exactly once. Components.widget-base
            // owns visibility from this point on, including Hide HUD while driving.
            Template::hide($player, 'WarManager');
            Template::show($player, 'WarManager.live-score-widget', $data);
            self::$rendered[$player->Login] = true;
            return;
        }

        // Push data through UI variables without replacing the widget. Replacing
        // the ManiaLink would restart widget-base and replay its hide/show animation.
        Template::show($player, 'WarManager.live-score-update', $data);
    }

    public static function hide(Player $player): void
    {
        unset(self::$rendered[$player->Login]);
        Template::hide($player, 'WarManager');
        Template::hide($player, self::MANIALINK_ID);
        Template::hide($player, 'WarLiveScoreWidgetUpdate');
    }
}
