<?php

namespace EvoSC\Modules\WarManager\Classes;

use RuntimeException;

final class TeamJoinPolicy
{
    public static function assertCanJoin(string $status, ?string $existingTeam): void
    {
        if ($status !== WarState::ACTIVE) {
            throw new RuntimeException('Team joining is only available while the war is active.');
        }
        if ($existingTeam !== null) {
            throw new RuntimeException(
                'You are already assigned to ' . $existingTeam . '. Team switching is disabled for this scrim.'
            );
        }
    }
}
