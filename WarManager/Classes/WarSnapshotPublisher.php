<?php

namespace EvoSC\Modules\WarManager\Classes;

use RuntimeException;

final class WarSnapshotPublisher
{
    private static $lastPayloadHash;

    public static function publish(): bool
    {
        if (!config('war-manager.website-sync.enabled', false)) {
            return false;
        }

        $endpoint = trim((string)config('war-manager.website-sync.endpoint', ''));
        $secret = trim((string)(getenv('WAR_MANAGER_SYNC_SECRET') ?: config('war-manager.website-sync.secret', '')));
        if ($endpoint === '' || $secret === '') {
            throw new RuntimeException('War website sync requires an endpoint and secret.');
        }
        if (stripos($endpoint, 'https://') !== 0) {
            throw new RuntimeException('War website sync endpoint must use HTTPS.');
        }

        $snapshot = WarSnapshotExporter::export((int)config('war-manager.website-sync.history-limit', 20));
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode the war snapshot.');
        }
        $hash = hash('sha256', $json);
        if (self::$lastPayloadHash === $hash) {
            return false;
        }

        self::send($endpoint, $json, self::signature($json, $secret));
        self::$lastPayloadHash = $hash;
        return true;
    }

    public static function signature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private static function send(string $endpoint, string $payload, string $signature): void
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: EvoSC-WarManager/0.13',
            'X-War-Signature: sha256=' . $signature,
        ];

        if (function_exists('curl_init')) {
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($error !== '' || $status < 200 || $status >= 300) {
                throw new RuntimeException('War website sync failed with HTTP status ' . $status . '.');
            }
            return;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($endpoint, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($result === false || !preg_match('/\\s2\\d{2}\\s/', $statusLine)) {
            throw new RuntimeException('War website sync request failed.');
        }
    }
}
