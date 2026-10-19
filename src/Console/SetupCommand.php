<?php

namespace NightGuardian\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\EnvFile;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Transport;
use RuntimeException;
use Throwable;

/**
 * One command to connect an installation: validates the URL and the token, writes them to the app's .env (nothing else
 * in the file changes), refreshes the cached configuration and proves the connection with a test event.
 * Safe to run again; the token is never printed.
 */
class SetupCommand extends Command
{
    protected $signature = 'nightguardian:setup
        {--url= : Collector base URL, e.g. https://ng.example.com}
        {--token= : Project token (ng_...)}
        {--no-test : Do not send a test event afterwards}';

    protected $description = 'Write NIGHTGUARDIAN_URL and NIGHTGUARDIAN_TOKEN to the .env file and check the connection';

    /**
     * @return int
     */
    public function handle()
    {
        $url = $this->resolveUrl();
        $token = $url === null ? null : $this->resolveToken();

        if ($url === null || $token === null) {
            return 1;
        }

        if (stripos($url, 'http://') === 0) {
            $this->warn('The collector URL uses http://: the token and the events travel unencrypted. Use https:// unless this is a local test.');
        }

        $envPath = $this->application()->environmentFilePath();

        try {
            $changed = EnvFile::set($envPath, ['NIGHTGUARDIAN_URL' => $url, 'NIGHTGUARDIAN_TOKEN' => $token]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info($changed ? "OK  NIGHTGUARDIAN_URL and NIGHTGUARDIAN_TOKEN written to {$envPath}." : "OK  {$envPath} already has this URL and token.");

        if (! $this->refreshConfiguration()) {
            return 1;
        }

        $this->useNewSettings($url, $token);

        if ($this->option('no-test')) {
            return 0;
        }

        return $this->sendTest();
    }

    /**
     * @return string|null
     */
    private function resolveUrl()
    {
        $url = $this->option('url');

        if (! is_string($url) || trim($url) === '') {
            $default = config('nightguardian.url');
            $url = $this->input->isInteractive() ? $this->ask('Collector URL (e.g. https://ng.example.com)', is_string($default) && $default !== '' ? $default : null) : null;
        }

        $url = rtrim(trim((string) $url), '/');

        if ($url === '') {
            $this->error('The collector URL is required: pass --url=https://ng.example.com.');

            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            $this->error('The collector URL must be a full http(s) address, e.g. https://ng.example.com.');

            return null;
        }

        return $url;
    }

    /**
     * @return string|null
     */
    private function resolveToken()
    {
        $token = $this->option('token');

        if (! is_string($token) || trim($token) === '') {
            $token = $this->input->isInteractive() ? $this->secret('Project token (ng_..., input is hidden)') : null;
        }

        $token = trim((string) $token);

        if ($token === '') {
            $this->error('The project token is required: pass --token=ng_... or run the command in a terminal.');

            return null;
        }

        if (strpos($token, 'ng_') !== 0) {
            $this->error('That does not look like a NightGuardian token: project tokens start with "ng_".');

            return null;
        }

        return $token;
    }

    /**
     * With a cached configuration the new .env values would be ignored: cache it again.
     */
    /**
     * @return bool
     */
    private function refreshConfiguration()
    {
        if (! is_file($this->application()->getCachedConfigPath())) {
            return true;
        }

        try {
            $code = $this->callSilent('config:cache');
        } catch (Throwable $e) {
            $code = 1;
        }

        if ($code !== 0) {
            $this->error('The configuration is cached and could not be cached again. Run `php artisan config:cache` yourself.');

            return false;
        }

        $this->info('OK  Cached configuration refreshed.');

        return true;
    }

    /**
     * The command runs in the process that loaded the old values: swap them in so the test event uses the new ones.
     */
    /**
     * @param  string  $url
     * @param  string  $token
     * @return void
     */
    private function useNewSettings($url, $token)
    {
        config(['nightguardian.url' => $url, 'nightguardian.token' => $token]);

        foreach ([NightGuardian::class, EventFactory::class, Dispatcher::class, Transport::class] as $abstract) {
            $this->application()->forgetInstance($abstract);
        }
    }

    /**
     * @return int
     */
    private function sendTest()
    {
        $spooled = $this->application()->make(Dispatcher::class)->spool()->count();

        $code = $this->call('nightguardian:test');

        // nightguardian:test succeeds when the event was only saved in the spool: here that means "not confirmed".
        if ($code === 0 && $this->application()->make(Dispatcher::class)->spool()->count() > $spooled) {
            $this->error('The settings were saved, but the collector did not confirm the test event. Check the URL and your network.');

            return 1;
        }

        return $code === 0 ? 0 : 1;
    }

    /**
     * @return Application
     */
    private function application()
    {
        if (! $this->laravel instanceof Application) {
            throw new RuntimeException('NightGuardian needs a Laravel application to run this command.');
        }

        return $this->laravel;
    }
}
