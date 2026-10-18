<?php

namespace NightGuardian\Laravel\Console;

use Illuminate\Console\Command;
use NightGuardian\Laravel\Event\Release;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Transport\Dispatcher;

class DeployCommand extends Command
{
    protected $signature = 'nightguardian:deploy
        {--release= : Release name (defaults to NIGHTGUARDIAN_RELEASE or the short git SHA)}
        {--commit= : Full commit SHA (defaults to the current git commit)}
        {--branch= : Branch (defaults to the current git branch)}
        {--deployer= : Who or what deployed (for example the script name)}
        {--url= : Link to the commit or the deploy}';

    protected $description = 'Tell NightGuardian a deploy happened, so issues can be marked as resolved in a release';

    public function handle(NightGuardian $client, Dispatcher $dispatcher, Release $release): int
    {
        $reason = $client->inactiveReason();

        if ($reason !== null) {
            $this->error("NightGuardian is inactive: {$reason}");

            return 1;
        }

        $git = $release->gitInfo();
        $name = $this->text('release') ?? $release->resolve();

        if ($name === null) {
            $this->error('No release name: pass --release=... or set NIGHTGUARDIAN_RELEASE (no git repository found).');

            return 1;
        }

        $event = $client->factory()->deploy([
            'release' => $name,
            'commit' => $this->text('commit') ?? $git['sha'],
            'branch' => $this->text('branch') ?? $git['branch'],
            'deployer' => $this->text('deployer'),
            'url' => $this->text('url'),
        ], ['request' => false]);

        $result = $dispatcher->deliver([$event], true);

        if ($result->delivered > 0 && $result->spooled === 0) {
            $this->info("OK  Deploy marker sent for release {$name}.");

            return 0;
        }

        if ($result->spooled > 0) {
            $this->warn('The collector could not take the deploy marker now'.($result->error !== null ? " ({$result->error})" : '').'. It was saved in the spool and will be sent later.');

            return 0;
        }

        $this->error('The deploy marker was not delivered'.($result->error !== null ? ": {$result->error}" : '.'));

        return 1;
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
