<?php

namespace NightGuardian\Laravel\Event;

/**
 * Describes where the code runs: PHP, Laravel and operating system versions.
 */
class RuntimeContext
{
    /**
     * @var string
     */
    private $frameworkVersion;

    public function __construct(string $frameworkVersion)
    {
        $this->frameworkVersion = $frameworkVersion;
    }

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
            'os' => defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : (stripos(PHP_OS, 'WIN') === 0 ? 'Windows' : PHP_OS),
        ];
    }

    public function hostname(): ?string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? mb_substr($host, 0, 255) : null;
    }
}
