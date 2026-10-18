<?php

use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Transport\CurlTransport;

/**
 * Opt-in checks against a REAL collector. They are skipped unless you ask for them:
 *
 *   NIGHTGUARDIAN_E2E=1 NIGHTGUARDIAN_E2E_URL=http://collector.nightguardian.test NIGHTGUARDIAN_E2E_TOKEN=ng_... vendor/bin/pest tests/E2E
 *
 * They send a `test` event to the project of that token; use a throwaway project.
 */
function ngE2eEnabled(): bool
{
    return getenv('NIGHTGUARDIAN_E2E') === '1'
        && trim((string) getenv('NIGHTGUARDIAN_E2E_URL')) !== ''
        && trim((string) getenv('NIGHTGUARDIAN_E2E_TOKEN')) !== '';
}

it('delivers a test event to the collector named by NIGHTGUARDIAN_E2E_URL', function () {
    $url = rtrim((string) getenv('NIGHTGUARDIAN_E2E_URL'), '/').'/api/v1/ingest';
    $event = app(EventFactory::class)->test(new RuntimeException('NightGuardian SDK E2E test event'), ['request' => false]);

    $result = (new CurlTransport($url, (string) getenv('NIGHTGUARDIAN_E2E_TOKEN'), 5.0, 2.0))->send([$event]);

    expect($result->status)->toBe(202)->and(NightGuardian::SDK_NAME)->toBe('nightguardian-laravel');
})->skip(function () {
    return ! ngE2eEnabled();
}, 'Real-collector test: set NIGHTGUARDIAN_E2E=1, NIGHTGUARDIAN_E2E_URL and NIGHTGUARDIAN_E2E_TOKEN to run it.');
