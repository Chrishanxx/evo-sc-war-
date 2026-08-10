<?php

namespace EvoSC\Modules\WarManager\Classes;

final class TeamDetector
{
    /** @return array{team:string,name:string}|null */
    public static function detect(string $nickname, string $teamA, string $teamB): ?array
    {
        $nickname = trim((string)preg_replace(
            '/(?<![$])\${1}(([lh])(\[.+?])|[iwngosz<>]{1}|[a-f0-9]{1,3})/i',
            '',
            $nickname
        ));

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
