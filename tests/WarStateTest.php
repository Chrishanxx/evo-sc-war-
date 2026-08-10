<?php

use EvoSC\Modules\WarManager\Classes\WarState;
use PHPUnit\Framework\TestCase;

final class WarStateTest extends TestCase
{
    public function testValidTransitionIsAccepted(): void
    {
        WarState::assertTransition(WarState::DRAFT, WarState::ACTIVE);
        self::addToAssertionCount(1);
    }

    public function testFinishedWarCannotBecomeActiveAgain(): void
    {
        $this->expectException(\DomainException::class);
        WarState::assertTransition(WarState::FINISHED, WarState::ACTIVE);
    }

    public function testPausedWarCanResume(): void
    {
        WarState::assertTransition(WarState::PAUSED, WarState::ACTIVE);
        self::addToAssertionCount(1);
    }
}
