<?php

namespace NightGuardian\Laravel;

use Throwable;

/**
 * Main client (facade root).
 *
 * Capture and transport arrive in later stages; for now it answers the one question every other
 * part of the SDK asks first: "am I active?". It never throws.
 */
class NightGuardian
{
    public const VERSION = '2.0.0-dev';

    public const SDK_NAME = 'nightguardian-laravel';

    private int $internalErrors = 0;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private array $config) {}

    public function isActive(): bool
    {
        return $this->inactiveReason() === null;
    }

    /**
     * Why the SDK is doing nothing, or null when it is active.
     */
    public function inactiveReason(): ?string
    {
        try {
            if (! extension_loaded('curl')) {
                return 'The PHP curl extension is not installed.';
            }

            if (! ($this->config['enabled'] ?? true)) {
                return 'Disabled (NIGHTGUARDIAN_ENABLED=false).';
            }

            $url = $this->config['url'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                return 'NIGHTGUARDIAN_URL is not set.';
            }

            if (! preg_match('#^https?://[^\s/]+#i', trim($url))) {
                return 'NIGHTGUARDIAN_URL must be an absolute http(s) URL.';
            }

            $token = $this->config['token'] ?? null;

            if (! is_string($token) || trim($token) === '') {
                return 'NIGHTGUARDIAN_TOKEN is not set.';
            }

            return null;
        } catch (Throwable $e) {
            $this->countInternalError();

            return 'Configuration could not be read.';
        }
    }

    /**
     * The collector's ingest endpoint, or null when no URL is configured.
     */
    public function ingestUrl(): ?string
    {
        $url = $this->config['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim(trim($url), '/').'/api/v1/ingest';
    }

    /**
     * Number of failures swallowed by the SDK itself (shown by `nightguardian:status`).
     */
    public function internalErrors(): int
    {
        return $this->internalErrors;
    }

    public function countInternalError(): void
    {
        $this->internalErrors++;
    }
}
