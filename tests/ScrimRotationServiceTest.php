<?php

use EvoSC\Modules\WarManager\Classes\ScrimRotationService;
use PHPUnit\Framework\TestCase;

final class ScrimRotationServiceTest extends TestCase
{
    public function testSelectionMustMatchMapUidsAndOrder(): void
    {
        self::assertTrue(ScrimRotationService::selectionMatches(['A', 'B', 'C'], ['A', 'B', 'C']));
        self::assertFalse(ScrimRotationService::selectionMatches(['B', 'C', 'A'], ['A', 'B', 'C']));
        self::assertFalse(ScrimRotationService::selectionMatches(['A', 'B', 'C', 'D'], ['A', 'B', 'C']));
    }

    public function testNextMapWrapsAfterLastPosition(): void
    {
        self::assertSame('A', ScrimRotationService::nextWarMapUid(['A', 'B', 'C'], 0));
        self::assertSame('B', ScrimRotationService::nextWarMapUid(['A', 'B', 'C'], 1));
        self::assertSame('C', ScrimRotationService::nextWarMapUid(['A', 'B', 'C'], 2));
        self::assertSame('A', ScrimRotationService::nextWarMapUid(['A', 'B', 'C'], 3));
    }

    public function testNextMapRejectsEmptyPool(): void
    {
        $this->expectException(RuntimeException::class);
        ScrimRotationService::nextWarMapUid([], 0);
    }
}
