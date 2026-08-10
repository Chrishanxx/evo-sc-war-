<?php

namespace EvoSC\Modules\Scrim\Classes;

final class TeamDetector
{
    /** @return array{team:string,name:string}|null */
    public static function detect(string $nickname, string $teamA, string $teamB): ?array
    {
        $nickname = trim((string)preg_replace('/\$[0-9a-fA-F]{1,3}|\$[g-zG-Z]/', '', $nickname));

        foreach ([$teamA, $teamB] as $team) {
            $tag = preg_quote(trim($team), '/');
            $pattern = '/^\s*(?:\[' . $tag . '\]|' . $tag . ')\s*(?:\||\.|-|\s)\s*(.+)$/iu';
            if (preg_match($pattern, $nickname, $match)) {
                return ['team' => $team, 'name' => trim($match[1])];
            }
        }

        return null;
    }
}
