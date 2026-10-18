<?php

namespace NightGuardian\Laravel\Console;

use Illuminate\Console\Command;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\DispatchResult;
use RuntimeException;

class TestCommand extends Command
{
    protected $signature = 'nightguardian:test';

    protected $description = 'Send a test event to the NightGuardian collector and show its answer';

    public function handle(NightGuardian $client, Dispatcher $dispatcher): int
    {
        $reason = $client->inactiveReason();

        if ($reason !== null) {
            $this->error("NightGuardian is inactive: {$reason}");
            $this->line('Set NIGHTGUARDIAN_URL and NIGHTGUARDIAN_TOKEN (see `php artisan nightguardian:status`).');

            return self::FAILURE;
        }

        $warning = StatusCommand::insecureUrlWarning($client);

        if ($warning !== null) {
            $this->warn($warning);
        }

        $this->line("Sending a test event to {$client->ingestUrl()} ...");

        $event = $client->factory()->test(new RuntimeException('NightGuardian test event: if you can read this in your panel, the connection works.'), ['request' => false]);

        $result = $dispatcher->deliver([$event], true);

        return $this->report($result, $event['reference'] ?? null, $dispatcher);
    }

    private function report(DispatchResult $result, ?string $reference, Dispatcher $dispatcher): int
    {
        if ($result->delivered > 0 && $result->spooled === 0 && $result->dropped === 0) {
            $issue = $result->results[0]['issue'] ?? null;

            $this->info('OK  The collector accepted the test event (HTTP 202).');

            if (is_string($issue) && $issue !== '') {
                $this->line("    Issue: {$issue}");
            }

            if ($reference !== null) {
                $this->line("    Reference: {$reference}");
            }

            $this->line('    Open the project in your collector panel: the event should be listed as a test issue.');

            return self::SUCCESS;
        }

        if ($result->rejected > 0) {
            $this->error('The collector rejected the event as invalid:');

            foreach ($result->results as $item) {
                foreach ((array) ($item['errors'] ?? []) as $error) {
                    $this->line('    - '.$error);
                }
            }

            return self::FAILURE;
        }

        if ($result->status === 401 || $result->status === 403) {
            $this->error("The collector rejected the token (HTTP {$result->status}). Check NIGHTGUARDIAN_TOKEN: it must belong to an active project token.");
            $this->line('The event was kept in the spool and will be sent once the token works.');

            return self::FAILURE;
        }

        if ($result->status === 400) {
            $this->error('The collector refused the request as malformed (HTTP 400). The event was discarded.');

            return self::FAILURE;
        }

        if ($result->spooled > 0) {
            switch (true) {
                case $result->status === 0:
                    $why = 'The collector could not be reached'.($result->error !== null ? " ({$result->error})" : '').'.';
                    break;
                case $result->status === 429:
                    $why = 'The collector is rate limiting this project (HTTP 429).';
                    break;
                case $result->status !== null && $result->status >= 500:
                    $why = "The collector failed to process the request (HTTP {$result->status}).";
                    break;
                default:
                    $why = 'The collector did not accept the event'.($result->status ? " (HTTP {$result->status})" : '').'.';
                    break;
            }

            $this->warn($why);
            $this->line("The event was saved in the spool ({$dispatcher->spool()->count()} batch(es) waiting) and will be sent automatically when the collector answers again.");
            $this->line('Run `php artisan nightguardian:status` for details or `php artisan nightguardian:flush` to retry now.');

            return self::SUCCESS;
        }

        $this->error('The event could not be sent nor saved in the spool (is the storage directory writable?).');
        $this->line('Run `php artisan nightguardian:status` for details.');

        return self::FAILURE;
    }
}
