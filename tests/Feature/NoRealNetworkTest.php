<?php

use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Transport;

/**
 * The test suite must never reach a real collector. Real-collector checks are opt-in (tests/E2E) and need
 * NIGHTGUARDIAN_E2E=1 plus NIGHTGUARDIAN_E2E_URL and NIGHTGUARDIAN_E2E_TOKEN.
 */
function ngEnvValue(string $name): string
{
    foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

it('finds no real collector configuration in the environment unless E2E is explicitly requested', function () {
    if (ngEnvValue('NIGHTGUARDIAN_E2E') === '1') {
        $this->markTestSkipped('NIGHTGUARDIAN_E2E=1: real-collector tests were requested explicitly.');
    }

    $found = array_values(array_filter(['NIGHTGUARDIAN_URL', 'NIGHTGUARDIAN_TOKEN', 'NIGHTGUARDIAN_E2E_URL', 'NIGHTGUARDIAN_E2E_TOKEN'], function (string $name) {
        return ngEnvValue($name) !== '';
    }));

    expect($found)->toBe([], implode(', ', $found).' is set. The suite must not see a real collector: unset it, '
        .'or set NIGHTGUARDIAN_E2E=1 to run tests/E2E on purpose.');
});

it('finds no real collector configuration in .env files the app under test could load', function () {
    $files = array_filter([
        dirname(__DIR__, 2).'/.env',
        dirname(__DIR__, 2).'/workbench/.env',
        dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/.env',
    ], 'is_file');

    $offending = [];

    foreach ($files as $file) {
        if (preg_match('/^\s*NIGHTGUARDIAN_(URL|TOKEN)\s*=\s*[^\s#]/m', (string) file_get_contents($file))) {
            $offending[] = $file;
        }
    }

    expect($offending)->toBe([], 'These .env files configure a real collector: '.implode(', ', $offending));
});

it('gives every app under test no collector URL or token and a fake transport', function () {
    expect(config('nightguardian.url'))->toBe('')
        ->and(config('nightguardian.token'))->toBe('')
        ->and(app(Transport::class))->toBeInstanceOf(FakeTransport::class);
});

it('keeps the storage (spool, breaker) of the app under test out of the real vendor directory', function () {
    expect(storage_path())->toStartWith(sys_get_temp_dir());
});

it('only lets the suite open sockets to loopback addresses', function () {
    $offending = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if (substr_compare($path, '.php', -strlen('.php')) !== 0 || strpos($path, DIRECTORY_SEPARATOR.'E2E'.DIRECTORY_SEPARATOR) !== false || basename($path) === 'NoRealNetworkTest.php') {
            continue;
        }

        $code = (string) file_get_contents($path);

        // Literal URLs in tests must be fake hosts (".test", ".invalid") or loopback.
        preg_match_all('#https?://(?:[^@/\s\'"]*@)?([a-z0-9.\-]+)#i', $code, $matches);

        foreach (array_unique($matches[1]) as $host) {
            if (! preg_match('/(^|\.)(test|invalid|localhost)$|(^|\.)example\.(com|org|net)$|^127\.0\.0\.1$/i', $host) && ! in_array($host, ['github.com', 'www.w3.org', 'json-schema.org'], true)) {
                $offending[] = basename($path).' → '.$host;
            }
        }
    }

    expect($offending)->toBe([]);
});
