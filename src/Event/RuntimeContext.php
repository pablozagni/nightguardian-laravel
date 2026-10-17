<?php

namespace NightGuardian\Laravel\Event;

/**
 * Describes where the code runs: PHP, Laravel and operating system versions.
 */
class RuntimeContext
{
    public function __construct(private string $frameworkVersion) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => 'php',
            'version' => PHP_VERSION,
            'framework' => 'laravel',
            'framework_version' => $this->frameworkVersion,
            'os' => PHP_OS_FAMILY,
        ];
    }

    public function hostname(): ?string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? mb_substr($host, 0, 255) : null;
    }
}
