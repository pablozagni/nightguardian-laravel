<?php

namespace NightGuardian\Laravel\Transport;

use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Json;
use Throwable;

/**
 * gzip POST to `{collector}/api/v1/ingest` with ext-curl (protocol v1 §1, §5).
 */
class CurlTransport implements Transport
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private string $url,
        private string $token,
        private float $timeout = 2.0,
        private float $connectTimeout = 1.0,
    ) {}

    public function send(array $events): TransportResult
    {
        try {
            return $this->post($events);
        } catch (Throwable $e) {
            return TransportResult::failure('transport error: '.$e->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function post(array $events): TransportResult
    {
        if (! function_exists('curl_init')) {
            return TransportResult::failure('the curl extension is not available');
        }

        $body = Json::encode([
            'sent_at' => gmdate('Y-m-d\TH:i:s').sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)),
            'sdk' => ['name' => NightGuardian::SDK_NAME, 'version' => NightGuardian::VERSION],
            'events' => $events,
        ]);

        $headers = [
            'Authorization: Bearer '.$this->token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: '.NightGuardian::SDK_NAME.'/'.NightGuardian::VERSION,
        ];

        $gzipped = function_exists('gzencode') ? gzencode($body, 6) : false;

        if ($gzipped !== false) {
            $body = $gzipped;
            $headers[] = 'Content-Encoding: gzip';
        }

        $retryAfter = null;
        $response = '';

        $curl = curl_init($this->url);

        if ($curl === false) {
            return TransportResult::failure('curl_init failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($this->connectTimeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($this->timeout * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $value = trim(substr($line, 12));
                    $retryAfter = ctype_digit($value) ? (int) $value : null;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$response): int {
                $response .= $chunk;

                // Never buffer an unreasonable answer: returning a short count aborts the transfer.
                return strlen($response) > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        $error = curl_error($curl);

        if ($ok === false && $status === 0) {
            return TransportResult::failure(trim("curl error {$errno}: {$error}"));
        }

        $results = [];

        if ($status === 202) {
            $decoded = json_decode($response, true);
            $results = is_array($decoded) && isset($decoded['results']) && is_array($decoded['results']) ? array_values(array_filter($decoded['results'], 'is_array')) : [];
        }

        return new TransportResult($status, $status >= 400 ? $this->errorFrom($status, $response) : null, $retryAfter, $results);
    }

    private function errorFrom(int $status, string $response): string
    {
        $decoded = json_decode($response, true);
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : null;

        return "HTTP {$status}".($message !== null ? ": {$message}" : '');
    }
}
