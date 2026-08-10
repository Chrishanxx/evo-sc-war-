<?php

namespace EvoSC\Modules\Scrim\Classes;

final class ScoreCalculator
{
    /**
     * @param array<int,array{login:string,time:int}> $records
     * @param array<int,int> $points rank => points
     * @return array<int,array{login:string,time:int,rank:int,points:int}>
     */
    public static function rank(array $records, array $points): array
    {
        $best = [];
        foreach ($records as $record) {
            if (!isset($best[$record['login']]) || $record['time'] < $best[$record['login']]['time']) {
                $best[$record['login']] = $record;
            }
        }

        usort($best, static function (array $left, array $right): int {
            return [$left['time'], $left['login']] <=> [$right['time'], $right['login']];
        });

        foreach ($best as $index => &$record) {
            $record['rank'] = $index + 1;
            $record['points'] = $points[$record['rank']] ?? 0;
        }
        unset($record);

        return array_values($best);
    }
}
