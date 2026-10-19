<?php

namespace NightGuardian\Laravel\Console;

use Illuminate\Console\Command;
use NightGuardian\Laravel\Event\Release;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Transport\Dispatcher;

class StatusCommand extends Command
{
    protected $signature = 'nightguardian:status';

    protected $description = 'Show the NightGuardian configuration check, spool, circuit breaker and counters';

    public function handle(NightGuardian $client, Dispatcher $dispatcher, Release $release): int
    {
        $config = $client->config();
        $reason = $client->inactiveReason();

        $this->line('NightGuardian SDK '.NightGuardian::VERSION);
        $this->newLine();

        $this->row('Status', $reason === null ? 'active' : "INACTIVE - {$reason}");
        $this->row('Collector', $client->ingestUrl() ?? '(not set)');
        $this->row('Token', $this->maskToken($config['token'] ?? null));
        $this->row('Environment', (string) ($config['environment'] ?? ''));
        $this->row('Host tag', ($config['host_tag'] ?? true) ? 'on (host of APP_URL)' : 'off');
        $this->row('Release', $release->resolve() ?? '(none)');
        $this->row('Log level', (string) ($config['log_level'] ?? 'error'));
        $this->row('Slow request', $this->threshold($config['slow_request_ms'] ?? 0));
        $this->row('Slow query', $this->threshold($config['slow_query_ms'] ?? 0));
        $this->row('Timeouts', sprintf('connect %ss, total %ss', $config['connect_timeout'] ?? 1, $config['timeout'] ?? 2));

        $this->newLine();

        $breaker = $dispatcher->breaker()->status();
        $this->row('Circuit breaker', match ($breaker['state']) {
            'open' => "OPEN for {$breaker['seconds_left']}s - {$breaker['reason']}",
            'half-open' => 'half-open: the next request probes the collector'.($breaker['reason'] ? " (last: {$breaker['reason']})" : ''),
            default => 'closed',
        });

        $spool = $dispatcher->spool();
        $age = $spool->oldestAge();
        $this->row('Spool', sprintf(
            '%d batch(es), %s%s',
            $spool->count(),
            $this->bytes($spool->bytes()),
            $age !== null ? ", oldest {$this->age($age)} old" : '',
        ));
        $this->row('Spool directory', $spool->directory().($spool->isWritable() ? '' : '  (NOT WRITABLE)'));

        $this->newLine();

        $stats = $dispatcher->stats()->all();
        $this->row('Events sent', (string) ($stats['sent_events'] ?? 0));
        $this->row('Events rejected', (string) ($stats['rejected_events'] ?? 0));
        $this->row('Events spooled', (string) ($stats['spooled_events'] ?? 0));
        $this->row('Events dropped', (string) ($stats['dropped_events'] ?? 0));
        $this->row('Failed batches', (string) ($stats['failed_batches'] ?? 0));
        $this->row('Last success', $this->time($stats['last_success_at'] ?? null));
        $this->row('Last failure', $this->time($stats['last_failure_at'] ?? null));
        $this->row('Last error', (string) ($stats['last_error'] ?? '-'));
        $this->row('SDK internal errors', (string) (($stats['internal_errors'] ?? 0) + $client->internalErrors()));

        if (isset($stats['last_internal_error'])) {
            $this->row('Last internal error', (string) $stats['last_internal_error']);
        }

        $warnings = array_filter([
            self::insecureUrlWarning($client),
            ! $spool->isWritable() ? 'The spool directory is not writable: events will be dropped while the collector is unreachable.' : null,
            ! extension_loaded('zlib') ? 'ext-zlib is missing.' : null,
        ]);

        foreach ($warnings as $warning) {
            $this->newLine();
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    /**
     * Protocol v1 §1: SDKs must warn about plain http:// in production.
     */
    public static function insecureUrlWarning(NightGuardian $client): ?string
    {
        $config = $client->config();
        $url = (string) ($config['url'] ?? '');
        $environment = strtolower((string) ($config['environment'] ?? ''));

        if (stripos($url, 'http://') === 0 && in_array($environment, ['production', 'prod'], true)) {
            return 'The collector URL uses http:// in a production environment: tokens and events travel unencrypted. Use https://.';
        }

        return null;
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  %-22s %s', $label, $value));
    }

    private function maskToken(mixed $token): string
    {
        if (! is_string($token) || $token === '') {
            return '(not set)';
        }

        return substr($token, 0, 3).str_repeat('*', 6).substr($token, -4);
    }

    private function threshold(mixed $ms): string
    {
        return (int) $ms > 0 ? "{$ms} ms" : 'off';
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%.1f KB', $bytes / 1024);
    }

    private function age(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400).' d',
            $seconds >= 3600 => intdiv($seconds, 3600).' h',
            $seconds >= 60 => intdiv($seconds, 60).' min',
            default => $seconds.' s',
        };
    }

    private function time(mixed $timestamp): string
    {
        return is_numeric($timestamp) ? gmdate('Y-m-d H:i:s', (int) $timestamp).' UTC' : '-';
    }
}
