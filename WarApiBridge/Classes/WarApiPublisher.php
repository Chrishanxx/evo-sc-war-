<?php

namespace EvoSC\Modules\WarApiBridge\Classes;

use EvoSC\Classes\DB;
use RuntimeException;
use Throwable;

final class WarApiPublisher
{
    private static $multi;
    private static $handle;
    private static $activeRow;
    private static $dirty = true;

    public static function markDirty(): void
    {
        self::$dirty = true;
    }

    public static function capture(): bool
    {
        if (!config('war-api-bridge.enabled', false)) {
            return false;
        }
        if (!self::$dirty) {
            return false;
        }
        self::$dirty = false;

        try {
            $snapshot = WarSnapshotExporter::export((int)config('war-api-bridge.history-limit', 20));
            $hashInput = $snapshot;
            unset($hashInput['generatedAt']);
            $hashJson = json_encode($hashInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($hashJson === false || $json === false) {
                throw new RuntimeException('Could not encode the war snapshot.');
            }

            $hash = hash('sha256', $hashJson);
            $now = gmdate('Y-m-d H:i:s');
            $exists = DB::table('war-api-outbox')->where('payload_hash', $hash)->exists();
            if ($exists) {
                return false;
            }

            DB::table('war-api-outbox')->insert([
                'payload_hash' => $hash,
                'payload' => $json,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'sent_at' => null,
                'last_error' => null,
            ]);

            // A website snapshot contains the complete state, so only the newest
            // unsent entry is useful. This prevents a long outage from growing a backlog.
            $newestId = (int)DB::table('war-api-outbox')->whereNull('sent_at')->max('id');
            if ($newestId > 0) {
                DB::table('war-api-outbox')->whereNull('sent_at')->where('id', '<', $newestId)->delete();
            }
            self::prune();
            return true;
        } catch (Throwable $error) {
            // Queue creation must never interrupt WarManager or the game loop.
            self::$dirty = true;
            return false;
        }
    }

    public static function pump(): void
    {
        if (!config('war-api-bridge.enabled', false)) {
            return;
        }

        try {
            if (self::$handle) {
                self::advanceRequest();
                return;
            }
            self::startNextRequest();
        } catch (Throwable $error) {
            self::failActive($error->getMessage());
        }
    }

    public static function signature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function retryDelay(int $attempts, int $baseSeconds = 5): int
    {
        return min(300, max(1, $baseSeconds) * (2 ** min(6, max(0, $attempts))));
    }

    private static function startNextRequest(): void
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('The PHP cURL extension is required for non-blocking website sync.');
        }

        $endpoint = trim((string)config('war-api-bridge.endpoint', ''));
        $secret = trim((string)(getenv('WAR_MANAGER_SYNC_SECRET') ?: config('war-api-bridge.secret', '')));
        if ($endpoint === '' || $secret === '') {
            return;
        }
        if (stripos($endpoint, 'https://') !== 0) {
            throw new RuntimeException('War API endpoint must use HTTPS.');
        }

        $row = DB::table('war-api-outbox')->whereNull('sent_at')
            ->where('available_at', '<=', gmdate('Y-m-d H:i:s'))->orderByDesc('id')->first();
        if (!$row) {
            return;
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: EvoSC-WarApiBridge/0.1',
            'X-War-Signature: sha256=' . self::signature((string)$row->payload, $secret),
        ];
        $handle = curl_init($endpoint);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string)$row->payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, max(100, (int)config('war-api-bridge.request-timeout-ms', 5000))),
            CURLOPT_TIMEOUT_MS => min(15000, max(500, (int)config('war-api-bridge.request-timeout-ms', 5000))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
        ];
        if (config('war-api-bridge.force-ipv4', true) && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($handle, $options);
        self::$multi = curl_multi_init();
        self::$handle = $handle;
        self::$activeRow = $row;
        curl_multi_add_handle(self::$multi, self::$handle);
        self::advanceRequest();
    }

    private static function advanceRequest(): void
    {
        do {
            $result = curl_multi_exec(self::$multi, $running);
        } while ($result === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            return;
        }

        $status = (int)curl_getinfo(self::$handle, CURLINFO_HTTP_CODE);
        $error = curl_error(self::$handle);
        if ($result !== CURLM_OK || $error !== '' || $status < 200 || $status >= 300) {
            self::failActive($error !== '' ? $error : 'HTTP status ' . $status);
            return;
        }

        DB::table('war-api-outbox')->where('id', self::$activeRow->id)->update([
            'sent_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => null,
        ]);
        self::closeRequest();
        self::prune();
    }

    private static function failActive(string $message): void
    {
        if (self::$activeRow) {
            $attempts = (int)self::$activeRow->attempts + 1;
            $delay = self::retryDelay($attempts, (int)config('war-api-bridge.retry-base-seconds', 5));
            DB::table('war-api-outbox')->where('id', self::$activeRow->id)->update([
                'attempts' => $attempts,
                'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'last_error' => substr($message, 0, 1000),
            ]);
        }
        self::closeRequest();
    }

    private static function closeRequest(): void
    {
        if (self::$multi && self::$handle) {
            curl_multi_remove_handle(self::$multi, self::$handle);
        }
        if (self::$handle) {
            curl_close(self::$handle);
        }
        if (self::$multi) {
            curl_multi_close(self::$multi);
        }
        self::$handle = null;
        self::$multi = null;
        self::$activeRow = null;
    }

    private static function prune(): void
    {
        $limit = min(100, max(5, (int)config('war-api-bridge.queue-limit', 20)));
        $keep = DB::table('war-api-outbox')->orderByDesc('id')->limit($limit)->pluck('id')->all();
        if ($keep) {
            DB::table('war-api-outbox')->whereNotIn('id', $keep)->delete();
        }
    }
}
