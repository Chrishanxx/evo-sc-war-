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
}
