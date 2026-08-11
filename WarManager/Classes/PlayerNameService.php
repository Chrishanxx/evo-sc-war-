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
        if (!$war || !in_array($team, [$war->team_a, $war->team_b], true)) {
            throw new RuntimeException('The selected war team is unavailable.');
        }
        if ($war->status !== WarState::DRAFT && !$war->allow_team_switch) {
            throw new RuntimeException('Team selection is locked because the war has started.');
        }
        if ($player->isSetnameBlacklisted()) {
            throw new RuntimeException('You are not allowed to change your player name.');
        }

        $existing = DB::table('war-players')->where('war_id', $war->id)
            ->where('player_login', $player->Login)->first();
        $original = $existing && !empty($existing->original_name)
            ? trim($existing->original_name)
            : TeamDetector::stripWarTag($player->NickName, $war->team_a, $war->team_b);
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

        TeamAssignmentService::join($player, $team, 'overlay', false, $original, $newName);
        return $newName;
    }
}
