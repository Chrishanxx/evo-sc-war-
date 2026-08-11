<?php

use EvoSC\Modules\WarManager\Classes\TeamDetector;
use PHPUnit\Framework\TestCase;

final class TeamDetectorTest extends TestCase
{
    /** @dataProvider supportedNames */
    public function testItDetectsSupportedTagFormats(string $nickname): void
    {
        self::assertSame(
            ['team' => 'FAST', 'name' => 'Chrishan'],
            TeamDetector::detect($nickname, 'FAST', 'ZOOP')
        );
    }

    public static function supportedNames(): array
    {
        return [
            ['FAST Chrishan'],
            ['Fast Chrishan'],
            ['[FAST] Chrishan'],
            ['FAST | Chrishan'],
            ['FAST.Chrishan'],
            ['$fffFAST $zChrishan'],
            ['$<FAST$> Chrishan'],
        ];
    }

    public function testItRejectsPlayersWithoutEitherTag(): void
    {
        self::assertNull(TeamDetector::detect('RandomPlayer', 'FAST', 'ZOOP'));
    }

    /** @dataProvider taggedNames */
    public function testItStripsExistingWarTags(string $nickname, string $expected): void
    {
        self::assertSame($expected, TeamDetector::stripWarTag($nickname, 'FAST', 'ZOOP'));
    }

    public static function taggedNames(): array
    {
        return [
            ['FAST Chrishan', 'Chrishan'],
            ['[ZOOP] Propanoia', 'Propanoia'],
            ['$fffFAST $zChrishan', 'Chrishan'],
            ['RandomPlayer', 'RandomPlayer'],
        ];
    }
}
