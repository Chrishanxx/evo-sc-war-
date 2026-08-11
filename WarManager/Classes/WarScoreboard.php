<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\Template;
use EvoSC\Modules\ScoreTable\ScoreTable;
use ReflectionClass;
use RuntimeException;

final class WarScoreboard
{
    public static function register(): void
    {
        if (!class_exists(ScoreTable::class)) {
            return;
        }
        $scoreTableDirectory = dirname((new ReflectionClass(ScoreTable::class))->getFileName());
        $baseFile = $scoreTableDirectory . '/TableLayouts/default.xml';
        $extensionFile = dirname(__DIR__) . '/TableLayouts/timeattack-war.xml';
        $base = file_get_contents($baseFile);
        $extension = file_get_contents($extensionFile);
        if ($base === false || $extension === false) {
            throw new RuntimeException('Unable to read the EvoSC ScoreTable layout.');
        }

        $frame = self::between($extension, '<!-- WAR_FRAME_START -->', '<!-- WAR_FRAME_END -->');
        $script = self::between($extension, '<!-- WAR_SCRIPT_START -->', '<!-- WAR_SCRIPT_END -->');
        $base = self::insertBefore($base, '    <frame id="fillable_slots"', $frame . "\n\n    <frame id=\"fillable_slots\"");
        $base = self::insertBefore($base, '*** SB_Slot_Declarations ***', $script . "\n\n*** SB_Slot_Declarations ***");

        $generatedFile = sys_get_temp_dir() . '/evosc-war-scoreboard-' . sha1($base) . '.xml';
        if (file_put_contents($generatedFile, $base) === false) {
            throw new RuntimeException('Unable to create the WarManager ScoreTable layout.');
        }
        ScoreTable::addLayout(
            'Trackmania/TM_TimeAttack_Online.Script.txt',
            $generatedFile
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

    private static function between(string $source, string $start, string $end): string
    {
        $startAt = strpos($source, $start);
        $endAt = strpos($source, $end);
        if ($startAt === false || $endAt === false || $endAt <= $startAt) {
            throw new RuntimeException('The WarManager ScoreTable extension is incomplete.');
        }
        $startAt += strlen($start);
        return trim(substr($source, $startAt, $endAt - $startAt));
    }

    private static function insertBefore(string $source, string $needle, string $replacement): string
    {
        $position = strpos($source, $needle);
        if ($position === false) {
            throw new RuntimeException('The installed EvoSC ScoreTable layout is not compatible.');
        }
        return substr($source, 0, $position) . $replacement . substr($source, $position + strlen($needle));
    }
}
