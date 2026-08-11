<?php

namespace EvoSC\Modules\WarManager\Classes;

use EvoSC\Classes\DB;
use EvoSC\Classes\Log;
use EvoSC\Classes\Server;
use EvoSC\Controllers\MapController;
use EvoSC\Models\Player;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class ScrimRotationService
{
    public const MODE_NAME = 'TM_War_Online';
    public const BASE_SCRIPT = 'Trackmania/TM_TimeAttack_Online.Script.txt';

    private static bool $correctingMap = false;

    public static function validate($war): array
    {
        $maps = self::warMaps((int)$war->id);
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
        $relative = self::generateForWar($war);
        WarRepository::audit((int)$war->id, $admin->Login, 'scrim.playlist.generated', ['file' => $relative]);
        return 'MatchSettings/' . $relative;
    }

    public static function activate($war, string $actor = 'system'): array
    {
        self::assertReady($war);
        $relative = self::generateForWar($war);
        $backup = self::backupOriginalPlaylist($war, $actor);

        try {
            self::loadAndVerifyWarPlaylist($war, $relative);
            DB::table('war-rotation-backups')->where('war_id', $war->id)->update([
                'status' => 'ACTIVE',
                'applied_at' => gmdate('Y-m-d H:i:s'),
                'last_verified_at' => gmdate('Y-m-d H:i:s'),
                'last_error' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            $message = $error->getMessage();
            try {
                self::restore($war, 'system-activation-rollback');
                WarRepository::audit((int)$war->id, $actor, 'rotation.activation.rolled_back', [
                    'error' => $message,
                ]);
            } catch (Throwable $rollbackError) {
                $message .= ' Automatic rollback also failed: ' . $rollbackError->getMessage();
                self::rememberError((int)$war->id, $message);
            }
            throw new RuntimeException('Exclusive WAR rotation could not be activated: ' . $message, 0, $error);
        }

        $warCount = self::warMaps((int)$war->id)->count();
        $normalCount = count(self::decodePlaylist($backup->original_playlist));
        WarRepository::audit((int)$war->id, $actor, 'rotation.activated', [
            'war_maps' => $warCount,
            'normal_maps_backed_up' => $normalCount,
        ]);
        Log::info('[WarManager][Rotation] Exclusive rotation activated for War #' . $war->id
            . ': ' . $warCount . ' WAR maps, ' . $normalCount . ' normal maps backed up.');
        return ['war_maps' => $warCount, 'normal_maps' => $normalCount];
    }

    public static function restore($war, string $actor = 'system'): bool
    {
        $backup = DB::table('war-rotation-backups')->where('war_id', $war->id)->first();
        if (!$backup || $backup->restored_at) {
            return false;
        }

        $expected = self::decodePlaylist($backup->original_playlist);
        if (!$expected) {
            throw new RuntimeException('The original server rotation backup is empty.');
        }

        try {
            $loaded = Server::loadMatchSettings('MatchSettings/' . $backup->matchsettings_file);
            if ((int)$loaded < 1) {
                throw new RuntimeException('Dedicated Server did not load the original MatchSettings.');
            }
            self::assertSelection(self::uids($expected), self::currentSelectionUids(), 'restored server');
            DB::table('war-rotation-backups')->where('war_id', $war->id)->update([
                'status' => 'RESTORED',
                'restored_at' => gmdate('Y-m-d H:i:s'),
                'last_verified_at' => gmdate('Y-m-d H:i:s'),
                'last_error' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            self::rememberError((int)$war->id, $error->getMessage());
            throw new RuntimeException('Original server rotation could not be restored: ' . $error->getMessage(), 0, $error);
        }

        WarRepository::audit((int)$war->id, $actor, 'rotation.restored', ['maps' => count($expected)]);
        Log::info('[WarManager][Rotation] Original rotation restored for War #' . $war->id
            . ': ' . count($expected) . ' maps.');
        return true;
    }

    public static function recover(): void
    {
        foreach (DB::table('war-rotation-backups')->whereNull('restored_at')->orderBy('id')->get() as $backup) {
            $war = DB::table('wars')->where('id', $backup->war_id)->first();
            if ($war && in_array($war->status, [WarState::FINISHED, WarState::CANCELLED], true)) {
                try {
                    self::restore($war, 'system-recovery');
                } catch (Throwable $error) {
                    Log::error('[WarManager][Rotation] Deferred restore failed: ' . $error->getMessage());
                }
            }
        }

        $war = WarRepository::current();
        if ($war && self::requiresExclusiveRotation($war)) {
            self::ensureActiveRotation($war, true);
        }
    }

    public static function ensureActiveRotation($war = null, bool $recovery = false): bool
    {
        $war = $war ?: WarRepository::current();
        if (!$war || !self::requiresExclusiveRotation($war)) {
            return false;
        }

        try {
            self::assertReady($war);
            $expected = self::warMapUids((int)$war->id);
            $current = self::currentSelectionUids();
            $backup = DB::table('war-rotation-backups')->where('war_id', $war->id)->first();
            if ($expected === $current && !$backup) {
                throw new RuntimeException('Exclusive rotation is active, but the original playlist backup is missing.');
            }
            if ($expected === $current) {
                DB::table('war-rotation-backups')->where('war_id', $war->id)->update([
                    'last_verified_at' => gmdate('Y-m-d H:i:s'),
                    'last_error' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                return true;
            }

            self::activate($war, $recovery ? 'system-recovery' : 'rotation-guard');
            if ($recovery) {
                Log::info('[WarManager][Rotation] Recovered exclusive rotation after controller/server restart.');
            }
            return true;
        } catch (Throwable $error) {
            self::failSafe($war, $error->getMessage());
            return false;
        }
    }

    public static function guardNextMap(): void
    {
        $war = WarRepository::current();
        if (!$war || !self::requiresExclusiveRotation($war)) {
            return;
        }
        if (!self::ensureActiveRotation($war)) {
            return;
        }

        $next = Server::getNextMapInfo();
        $allowed = self::warMapUids((int)$war->id);
        if ($next && in_array((string)$next->uId, $allowed, true)) {
            return;
        }

        $uid = self::nextWarMapUid($allowed, (int)$war->rotation_position);
        $map = DB::table('maps')->where('uid', $uid)->first();
        if (!$map || !Server::chooseNextMap($map->filename)) {
            self::failSafe($war, 'Unable to choose the next valid WAR map ' . $uid . '.');
            return;
        }
        self::logBlockedMap($war, $next ? (string)$next->uId : 'unknown', 'next-map');
    }

    public static function observeCurrentMap(): void
    {
        $war = WarRepository::current();
        if (!$war || !self::requiresExclusiveRotation($war) || self::$correctingMap) {
            return;
        }
        $current = MapController::getCurrentMap();
        if (!$current) {
            return;
        }
        $map = DB::table('war-maps')->where('war_id', $war->id)->where('map_uid', $current->uid)
            ->where('enabled', 1)->first();
        if (!$map) {
            self::logBlockedMap($war, $current->uid, 'current-map');
            if (!self::ensureActiveRotation($war)) {
                return;
            }
            $allowed = self::warMapUids((int)$war->id);
            $uid = self::nextWarMapUid($allowed, (int)$war->rotation_position);
            try {
                self::$correctingMap = true;
                if (!Server::jumpToMapIdent($uid)) {
                    throw new RuntimeException('Dedicated Server rejected JumpToMapIdent for ' . $uid . '.');
                }
                dangerMessage('WAR ROTATION: Foreign map blocked. Loading the next WAR map.')->sendAll();
            } catch (Throwable $error) {
                self::failSafe($war, $error->getMessage());
            } finally {
                self::$correctingMap = false;
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
        WarRepository::audit((int)$war->id, 'system', 'rotation.map.changed', [
            'uid' => $current->uid, 'position' => $position, 'rotation' => $rotation,
        ]);
    }

    public static function selectionMatches(array $currentUids, array $expectedUids): bool
    {
        return array_values($currentUids) === array_values($expectedUids);
    }

    public static function nextWarMapUid(array $uids, int $currentPosition): string
    {
        if (!$uids) {
            throw new RuntimeException('No valid WAR maps are available.');
        }
        $index = $currentPosition > 0 ? $currentPosition % count($uids) : 0;
        return (string)$uids[$index];
    }

    private static function backupOriginalPlaylist($war, string $actor)
    {
        $existing = DB::table('war-rotation-backups')->where('war_id', $war->id)->first();
        if ($existing && !$existing->restored_at) {
            return $existing;
        }

        $original = self::normalizeSelection(Server::getMapList());
        if (!$original) {
            throw new RuntimeException('The current server playlist is empty and cannot be backed up.');
        }
        self::ensureDirectory();
        $relative = 'WarManager/original_war_' . (int)$war->id . '_' . gmdate('Ymd_His') . '.txt';
        $saved = Server::saveMatchSettings('MatchSettings/' . $relative);
        if ((int)$saved < 1) {
            throw new RuntimeException('Dedicated Server did not save the original MatchSettings.');
        }
        $now = gmdate('Y-m-d H:i:s');
        DB::table('war-rotation-backups')->updateOrInsert(['war_id' => $war->id], [
            'matchsettings_file' => $relative,
            'original_playlist' => json_encode($original),
            'war_playlist' => json_encode(self::normalizeWarMaps((int)$war->id)),
            'status' => 'BACKED_UP',
            'applied_at' => null,
            'restored_at' => null,
            'last_verified_at' => null,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        WarRepository::audit((int)$war->id, $actor, 'rotation.backed_up', ['maps' => count($original), 'file' => $relative]);
        Log::info('[WarManager][Rotation] Original playlist backed up: ' . count($original) . ' maps.');
        return DB::table('war-rotation-backups')->where('war_id', $war->id)->first();
    }

    private static function loadAndVerifyWarPlaylist($war, string $relative): void
    {
        $loaded = Server::loadMatchSettings('MatchSettings/' . $relative);
        if ((int)$loaded < 1) {
            throw new RuntimeException('Dedicated Server did not load the WAR MatchSettings.');
        }
        $expected = self::warMapUids((int)$war->id);
        self::assertSelection($expected, self::currentSelectionUids(), 'WAR');

        $current = Server::getCurrentMapInfo();
        if (!$current || !in_array((string)$current->uId, $expected, true)) {
            if (!Server::jumpToMapIdent($expected[0])) {
                throw new RuntimeException('Unable to load the first WAR map.');
            }
        }
    }

    private static function generateForWar($war): string
    {
        self::assertReady($war);
        $maps = self::warMaps((int)$war->id);
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

        self::ensureDirectory();
        $relative = 'WarManager/war_' . (int)$war->id . '.txt';
        $target = rtrim(Server::getMapsDirectory(), '/\\') . '/MatchSettings/' . $relative;
        $temporary = $target . '.tmp';
        $xml = $playlist->asXML();
        if ($xml === false || simplexml_load_string($xml) === false || file_put_contents($temporary, $xml) === false) {
            throw new RuntimeException('The scrim MatchSettings could not be generated safely.');
        }
        if (is_file($target) && !copy($target, $target . '.backup_' . gmdate('Ymd_His'))) {
            @unlink($temporary);
            throw new RuntimeException('The previous scrim MatchSettings could not be backed up.');
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('The generated scrim MatchSettings could not be activated.');
        }
        DB::table('wars')->where('id', $war->id)->update([
            'matchsettings_file' => $relative,
            'strict_scrim_maps' => 1,
            'repeat_playlist' => 1,
            'exclusive_rotation' => 1,
            'auto_load_matchsettings' => 1,
            'auto_restore_matchsettings' => 1,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $relative;
    }

    private static function ensureDirectory(): void
    {
        $directory = rtrim(Server::getMapsDirectory(), '/\\') . '/MatchSettings/WarManager';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create MatchSettings/WarManager.');
        }
    }

    private static function requiresExclusiveRotation($war): bool
    {
        return in_array($war->status, [WarState::ACTIVE, WarState::PAUSED], true);
    }

    private static function warMaps(int $warId)
    {
        return DB::table('war-maps')->where('war_id', $warId)->where('enabled', 1)
            ->orderBy('position')->orderBy('id')->get();
    }

    private static function warMapUids(int $warId): array
    {
        return self::warMaps($warId)->pluck('map_uid')->map(static function ($uid) {
            return (string)$uid;
        })->values()->all();
    }

    private static function currentSelectionUids(): array
    {
        return self::uids(self::normalizeSelection(Server::getMapList()));
    }

    private static function normalizeSelection(array $maps): array
    {
        $result = [];
        foreach ($maps as $map) {
            $result[] = [
                'uid' => (string)($map->uId ?? ''),
                'filename' => (string)($map->fileName ?? ''),
            ];
        }
        return array_values(array_filter($result, static function ($map) {
            return $map['uid'] !== '' && $map['filename'] !== '';
        }));
    }

    private static function normalizeWarMaps(int $warId): array
    {
        return self::warMaps($warId)->map(static function ($map) {
            return ['uid' => (string)$map->map_uid, 'filename' => (string)$map->map_file];
        })->values()->all();
    }

    private static function decodePlaylist(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function uids(array $playlist): array
    {
        return array_values(array_map(static function ($map) {
            return (string)($map['uid'] ?? '');
        }, $playlist));
    }

    private static function assertSelection(array $expected, array $actual, string $name): void
    {
        if (!self::selectionMatches($actual, $expected)) {
            throw new RuntimeException('The ' . $name . ' playlist verification failed. Expected '
                . count($expected) . ' maps, received ' . count($actual) . '.');
        }
    }

    private static function logBlockedMap($war, string $uid, string $source): void
    {
        WarRepository::audit((int)$war->id, 'system', 'rotation.foreign_map.blocked', [
            'uid' => $uid, 'source' => $source,
        ]);
        Log::info('[WarManager][Rotation] Blocked non-WAR map UID ' . $uid . ' (' . $source . ').');
    }

    private static function failSafe($war, string $reason): void
    {
        DB::table('wars')->where('id', $war->id)->update([
            'scoring_paused' => 1,
            'scoring_pause_reason' => 'ROTATION ERROR: ' . $reason,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::rememberError((int)$war->id, $reason);
        WarRepository::audit((int)$war->id, 'system', 'rotation.error', ['reason' => $reason]);
        Log::error('[WarManager][Rotation] ' . $reason);
        dangerMessage('WAR ROTATION ERROR: ', $reason, ' Scoring paused. Admin intervention required.')->sendAll();
    }

    private static function rememberError(int $warId, string $error): void
    {
        DB::table('war-rotation-backups')->where('war_id', $warId)->update([
            'status' => 'ERROR',
            'last_error' => $error,
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
