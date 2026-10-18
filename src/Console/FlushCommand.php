<?php

namespace NightGuardian\Laravel\Console;

use Illuminate\Console\Command;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Transport\Dispatcher;

class FlushCommand extends Command
{
    protected $signature = 'nightguardian:flush';

    protected $description = 'Send everything waiting in the NightGuardian spool now';

    public function handle(NightGuardian $client, Dispatcher $dispatcher): int
    {
        $reason = $client->inactiveReason();

        if ($reason !== null) {
            $this->error("NightGuardian is inactive: {$reason}");

            return 1;
        }

        $before = $dispatcher->spool()->count();

        if ($before === 0) {
            $this->info('The spool is empty: nothing to send.');

            return 0;
        }

        $result = $dispatcher->drain(true, true);
        $remaining = $dispatcher->spool()->count();

        $this->line("Sent {$result->drained} of {$before} spooled batch(es) ({$result->delivered} event(s) delivered, {$result->rejected} rejected).");

        if ($remaining > 0) {
            $this->warn("{$remaining} batch(es) still waiting".($result->error !== null ? ": {$result->error}" : '.'));

            return 0;
        }

        $this->info('The spool is empty.');

        return 0;
    }
}
