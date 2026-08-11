<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Classes\Log;
use EvoSC\Classes\Server;
use EvoSC\Controllers\MapController;
use EvoSC\Models\Player;
use RuntimeException;
use SimpleXMLElement;

final class ScrimRotationService
{
    public const MODE_NAME = 'TM_War_Online';
    public const BASE_SCRIPT = 'Trackmania/TM_TimeAttack_Online.Script.txt';

    public static function validate($war): array
    {
        $maps = DB::table('war-maps')->where('war_id', $war->id)->where('enabled', 1)
            ->orderBy('position')->orderBy('id')->get();
        $missing = [];
        foreach ($maps as $map) {
            $serverMap = DB::table('maps')->where('uid', $map->map_uid)->first();
            if (!$serverMap || empty($serverMap->filename)) {
                $missing[] = $map->map_name . ' (' . $map->map_uid . ')';
            }
        }
        return ['count' => $maps->count(), 'missing' => $missing, 'valid' => $maps->count() - count($missing)];
    }

    public static function assertReady($war): void
    {
        $result = self::validate($war);
        if ($result['count'] < 1) {
            throw new RuntimeException('Cannot start TM_War_Online: no scrim maps configured.');
        }
        if ($result['missing']) {
            throw new RuntimeException('Scrim cannot start. Missing server maps: ' . implode(', ', $result['missing']));
        }
    }

    public static function generate(Player $admin): string
    {
        $war = WarRepository::requireCurrent();
        if ($war->status !== WarState::DRAFT) {
            throw new RuntimeException('The scrim playlist can only be generated while the war is a draft.');
        }
        self::assertReady($war);
        $maps = DB::table('war-maps')->where('war_id', $war->id)->where('enabled', 1)
            ->orderBy('position')->orderBy('id')->get();

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
            $map = $playlist->addChild('map');
            $map->addChild('file', htmlspecialchars((string)$serverMap->filename, ENT_XML1));
            $map->addChild('ident', htmlspecialchars((string)$warMap->map_uid, ENT_XML1));
        }

        $directory = rtrim(Server::getMapsDirectory(), '/\\') . '/MatchSettings/WarManager';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create MatchSettings/WarManager.');
        }
        $relative = 'WarManager/war_' . (int)$war->id . '.txt';
        $target = $directory . '/war_' . (int)$war->id . '.txt';
        $temporary = $target . '.tmp';
        $xml = $playlist->asXML();
        if ($xml === false || simplexml_load_string($xml) === false || file_put_contents($temporary, $xml) === false) {
            throw new RuntimeException('The scrim matchsettings could not be generated safely.');
        }
        if (is_file($target) && !copy($target, $target . '.backup.txt')) {
            @unlink($temporary);
            throw new RuntimeException('The previous scrim matchsettings could not be backed up.');
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('The generated scrim matchsettings could not be activated.');
        }
        DB::table('wars')->where('id', $war->id)->update([
            'matchsettings_file' => $relative,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        WarRepository::audit((int)$war->id, $admin->Login, 'scrim.playlist.generated', ['file' => $relative]);
        return 'MatchSettings/' . $relative;
    }

    public static function observeCurrentMap(): void
    {
        $war = WarRepository::current();
        if (!$war || $war->status !== WarState::ACTIVE) {
            return;
        }
        $current = MapController::getCurrentMap();
        if (!$current) {
            return;
        }
        $map = DB::table('war-maps')->where('war_id', $war->id)->where('map_uid', $current->uid)
            ->where('enabled', 1)->first();
        if (!$map) {
            if ($war->strict_scrim_maps) {
                dangerMessage('WAR MAP RESTRICTION: ', $current->name, ' is not part of the active scrim. No war points will be counted.')->sendAll();
                Log::info('[WarManager] Foreign map detected during War #' . $war->id . ': ' . $current->uid);
            }
            return;
        }
        $position = (int)$map->position ?: (int)DB::table('war-maps')->where('war_id', $war->id)
            ->where('id', '<=', $map->id)->count();
        $previousPosition = (int)$war->rotation_position;
        $rotation = (int)$war->rotation_number;
        if ($previousPosition > 0 && $position <= $previousPosition) {
            $rotation++;
        }
        DB::table('wars')->where('id', $war->id)->update([
            'rotation_position' => $position,
            'rotation_number' => $rotation,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private static function setting(SimpleXMLElement $settings, string $name, string $type, string $value): void
    {
        $setting = $settings->addChild('setting');
        $setting->addAttribute('name', $name);
        $setting->addAttribute('type', $type);
        $setting->addAttribute('value', $value);
    }
}
