<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Controllers\PlayerController;
use EvoSC\Models\Player;
use RuntimeException;

final class PlayerNameService
{
    public static function joinWithWarName(Player $player, string $team): string
    {
        $war = WarRepository::current();
        if (!$war) {
            throw new RuntimeException('There is no current war.');
        }
        if (strcasecmp($team, $war->team_a) === 0) {
            $team = $war->team_a;
        } elseif (strcasecmp($team, $war->team_b) === 0) {
            $team = $war->team_b;
        } else {
            throw new RuntimeException('The selected war team is unavailable.');
        }

        $existing = DB::table('war-players')->where('war_id', $war->id)
            ->where('player_login', $player->Login)->first();
        TeamJoinPolicy::assertCanJoin($war->status, $existing ? $existing->locked_team : null);
        if ($player->isSetnameBlacklisted()) {
            throw new RuntimeException('You are not allowed to change your player name.');
        }
        $original = TeamDetector::stripWarTag($player->NickName, $war->team_a, $war->team_b);
        if ($original === '') {
            throw new RuntimeException('Your original player name could not be determined.');
        }

        $newName = trim($team . ' ' . $original);
        if (strlen(stripAll($newName)) > 38) {
            throw new RuntimeException('Team tag and player name exceed the 39 character limit.');
        }

        PlayerController::setName($player, $newName, true);
        if (trim(stripAll($player->NickName)) !== trim(stripAll($newName))) {
            throw new RuntimeException('Could not change your player name. Please try again.');
        }

        TeamAssignmentService::join($player, $team, 'overlay', $original, $newName);
        return $newName;
    }
}
