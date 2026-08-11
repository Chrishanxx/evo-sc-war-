<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Classes\Log;
use EvoSC\Classes\Server;
use EvoSC\Models\Player;
use RuntimeException;
use SimpleXMLElement;

final class WarMatchSettingsService
{
    public const MODE_NAME = 'TM_War_Online';
    public const BASE_SCRIPT = 'Trackmania/TM_TimeAttack_Online.Script.txt';

    public static function generate(Player $admin): string
    {
        $war = WarRepository::requireCurrent();
        if ($war->status !== WarState::DRAFT) {
            throw new RuntimeException('War matchsettings can only be generated while the war is a draft.');
        }
        $maps = DB::table('war-maps')->where('war_id', $war->id)->orderBy('id')->get();
        if ($maps->isEmpty()) {
            throw new RuntimeException('Add at least one server map before generating matchsettings.');
        }

        $playlist = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><playlist/>');
        $game = $playlist->addChild('gameinfos');
        $game->addChild('game_mode', '0');
        $game->addChild('chat_time', (string)((int)$war->chat_time * 1000));
        $game->addChild('finishtimeout', '1');
        $game->addChild('allwarmupduration', '0');
        $game->addChild('disablerespawn', '0');
        $game->addChild('forceshowallopponents', '0');
        $game->addChild('script_name', self::BASE_SCRIPT);

        $settings = $playlist->addChild('script_settings');
        self::setting($settings, 'S_TimeLimit', 'integer', (string)(int)$war->map_time_limit);
        self::setting($settings, 'S_ChatTime', 'integer', (string)(int)$war->chat_time);
        self::setting($settings, 'S_WarmUpNb', 'integer', '0');

        $filter = $playlist->addChild('filter');
        $filter->addChild('is_lan', '1');
        $filter->addChild('is_internet', '1');
        $filter->addChild('is_solo', '0');
        $filter->addChild('is_hotseat', '0');
        $filter->addChild('sort_index', '1000');
        $filter->addChild('random_map_order', '0');
        $playlist->addChild('startindex', '0');

        foreach ($maps as $warMap) {
            $serverMap = DB::table('maps')->where('uid', $warMap->map_uid)->first();
            if (!$serverMap || empty($serverMap->filename)) {
                throw new RuntimeException('War map is missing from the server: ' . $warMap->map_name);
            }
            $map = $playlist->addChild('map');
            $map->addChild('file', htmlspecialchars((string)$serverMap->filename, ENT_XML1));
            $map->addChild('ident', htmlspecialchars((string)$warMap->map_uid, ENT_XML1));
        }

        $directory = rtrim(Server::getMapsDirectory(), '/\\') . '/MatchSettings/WarManager';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the WarManager matchsettings directory.');
        }
        $relative = 'WarManager/war_' . (int)$war->id . '.txt';
        $target = $directory . '/war_' . (int)$war->id . '.txt';
        $temporary = $target . '.tmp';
        $backup = $directory . '/war_' . (int)$war->id . '.backup.txt';
        $xml = $playlist->asXML();
        if ($xml === false || simplexml_load_string($xml) === false) {
            throw new RuntimeException('Generated matchsettings failed XML validation.');
        }
        if (file_put_contents($temporary, $xml) === false) {
            throw new RuntimeException('Unable to write temporary war matchsettings.');
        }
        if (is_file($target) && !copy($target, $backup)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to back up the previous war matchsettings.');
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to activate the generated war matchsettings.');
        }

        DB::table('wars')->where('id', $war->id)->update([
            'mode_type' => 'WAR',
            'trackmania_script' => self::BASE_SCRIPT,
            'matchsettings_file' => $relative,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        WarRepository::audit($war->id, $admin->Login, 'matchsettings.generated', ['file' => $relative]);
        if (config('war-manager.debug', false)) {
            Log::info('[WarManager] Generated TM_War_Online profile for War #' . $war->id
                . ' using ' . self::BASE_SCRIPT . ' at MatchSettings/' . $relative);
        }
        return 'MatchSettings/' . $relative;
    }

    private static function setting(SimpleXMLElement $settings, string $name, string $type, string $value): void
    {
        $setting = $settings->addChild('setting');
        $setting->addAttribute('name', $name);
        $setting->addAttribute('type', $type);
        $setting->addAttribute('value', $value);
    }
}
