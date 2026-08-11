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

        // Remove both historical IDs first so a module update cannot leave an
        // obsolete card competing with the dedicated live widget.
        Template::hide($player, 'WarManager');
        Template::show($player, 'WarManager.live-score-widget', compact(
            'war', 'teamAPoints', 'teamBPoints', 'teamAColor', 'teamBColor',
            'timeLeft', 'heading', 'liveStatus', 'statusColor'
        ));
    }

    public static function hide(Player $player): void
    {
        Template::hide($player, 'WarManager');
        Template::hide($player, self::MANIALINK_ID);
    }
}
