<?php

use EvoSC\Modules\Scrim\Classes\ScoreCalculator;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    public function testItKeepsBestTimeAndReranksWholeMap(): void
    {
        $ranked = ScoreCalculator::rank([
            ['login' => 'fast', 'time' => 35000], ['login' => 'zoop', 'time' => 34000],
            ['login' => 'fast', 'time' => 33000],
        ], [1 => 16, 2 => 15]);
        self::assertSame(['fast', 'zoop'], array_column($ranked, 'login'));
        self::assertSame([16, 15], array_column($ranked, 'points'));
    }
}
