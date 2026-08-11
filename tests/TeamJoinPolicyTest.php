<?php

use EvoSC\Modules\WarManager\Classes\TeamJoinPolicy;
use EvoSC\Modules\WarManager\Classes\WarState;
use PHPUnit\Framework\TestCase;

final class TeamJoinPolicyTest extends TestCase
{
    public function testUnassignedPlayerCanJoinActiveWar(): void
    {
        TeamJoinPolicy::assertCanJoin(WarState::ACTIVE, null);
        self::assertTrue(true);
    }

    /** @dataProvider unavailableStates */
    public function testJoiningIsRejectedOutsideActiveWar(string $status): void
    {
        $this->expectException(RuntimeException::class);
        TeamJoinPolicy::assertCanJoin($status, null);
    }

    public function testExistingAssignmentCanNeverBeChanged(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Team switching is disabled');
        TeamJoinPolicy::assertCanJoin(WarState::ACTIVE, 'FAST');
    }

    public function unavailableStates(): array
    {
        return [
            [WarState::DRAFT],
            [WarState::PAUSED],
            [WarState::FINISHED],
            [WarState::CANCELLED],
        ];
    }
}
