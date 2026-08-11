<?php

namespace EvoSC\Modules\WarManager\Classes;

use RuntimeException;

final class TeamJoinPolicy
{
    public static function assertCanJoin(string $status, ?string $existingTeam): void
    {
        if (!in_array($status, [WarState::DRAFT, WarState::ACTIVE], true)) {
            throw new RuntimeException('Team joining is only available before or during an active war.');
        }
        if ($existingTeam !== null) {
            throw new RuntimeException(
                'You are already assigned to ' . $existingTeam . '. Team switching is disabled for this scrim.'
            );
        }
    }
}
