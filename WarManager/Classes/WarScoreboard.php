<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\Template;
use EvoSC\Controllers\TemplateController;
use EvoSC\Modules\ScoreTable\ScoreTable;
use ReflectionClass;

final class WarScoreboard
{
    public static function register(): void
    {
        if (!class_exists(ScoreTable::class)) {
            return;
        }
        $scoreTableDirectory = dirname((new ReflectionClass(ScoreTable::class))->getFileName());
        TemplateController::overrideTemplate(
            'WarManager.ScoreTableBase',
            $scoreTableDirectory . '/TableLayouts/default.xml'
        );
        ScoreTable::addLayout(
            'Trackmania/TM_TimeAttack_Online.Script.txt',
            dirname(__DIR__) . '/TableLayouts/timeattack-war.xml'
        );
        ScoreTable::sendScoreTable();
        self::refresh();
    }

    public static function refresh(): void
    {
        $state = WarViewState::latest();
        $visible = $state !== null;
        $teamA = $visible ? json_encode($state->war->team_a) : '""';
        $teamB = $visible ? json_encode($state->war->team_b) : '""';
        $status = $visible ? json_encode($state->war->status) : '""';
        $timeLeft = $visible ? json_encode($state->time_left) : '""';
        $teamAPoints = $visible ? $state->team_a_points : 0;
        $teamBPoints = $visible ? $state->team_b_points : 0;
        $scoredMapCount = $visible ? $state->scored_map_count : 0;
        $mapCount = $visible ? $state->map_count : 0;
        $teamAColor = $visible ? json_encode(self::trackmaniaColor($state->team_a_color)) : '"FFF"';
        $teamBColor = $visible ? json_encode(self::trackmaniaColor($state->team_b_color)) : '"FFF"';

        Template::showAll('WarManager.scoreboard-update', compact(
            'visible', 'teamA', 'teamB', 'status', 'timeLeft', 'teamAPoints', 'teamBPoints',
            'scoredMapCount', 'mapCount', 'teamAColor', 'teamBColor'
        ), 70);
    }

    private static function trackmaniaColor(string $color): string
    {
        $rgb = str_pad(substr($color, 0, 6), 6, 'F');
        return $rgb[0] . $rgb[2] . $rgb[4];
    }
}
