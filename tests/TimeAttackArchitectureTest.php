<?php

use EvoSC\Modules\WarManager\Classes\ScrimRotationService;
use PHPUnit\Framework\TestCase;

final class TimeAttackArchitectureTest extends TestCase
{
    public function testGeneratedWarPlaylistUsesOfficialTimeAttackScript(): void
    {
        self::assertSame(
            'Trackmania/TM_TimeAttack_Online.Script.txt',
            ScrimRotationService::BASE_SCRIPT
        );
    }
}
