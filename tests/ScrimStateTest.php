<?php

use EvoSC\Modules\Scrim\Classes\ScrimState;
use PHPUnit\Framework\TestCase;

final class ScrimStateTest extends TestCase
{
    public function testValidTransitionIsAccepted(): void
    {
        ScrimState::assertTransition(ScrimState::DRAFT, ScrimState::ACTIVE);
        self::addToAssertionCount(1);
    }

    public function testFinishedScrimCannotBecomeActiveAgain(): void
    {
        $this->expectException(\DomainException::class);
        ScrimState::assertTransition(ScrimState::FINISHED, ScrimState::ACTIVE);
    }
}
