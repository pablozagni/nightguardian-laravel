<?php

use NightGuardian\Laravel\Support\Json;
use NightGuardian\Laravel\Tests\ActiveTestCase;
use NightGuardian\Laravel\Tests\TestCase;
use NightGuardian\Laravel\Tests\WebContextTestCase;
use NightGuardian\Laravel\Transport\Buffer;
use NightGuardian\Laravel\Transport\Transport;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

uses(TestCase::class)->in('Feature', 'Unit', 'E2E');
uses(ActiveTestCase::class)->in('Capture', 'Transport');
uses(WebContextTestCase::class)->in('Web');

/**
 * Events captured so far in the current app (not yet flushed).
 *
 * @return list<array<string, mixed>>
 */
function ngCaptured(?string $type = null): array
{
    // Events still buffered plus events already handed to the (fake) transport by a flush point.
    $events = array_merge(app(Transport::class)->events(), app(Buffer::class)->all());

    return $type === null ? $events : array_values(array_filter($events, fn (array $e) => $e['type'] === $type));
}

/**
 * Validates events against the protocol v1 JSON schema (a copy lives in tests/fixtures; a test checks it
 * is identical to the collector's when both repositories are checked out side by side).
 *
 * @param  array<string, mixed>  ...$events
 * @return list<string> validation errors, empty when valid
 */
function ngSchemaErrors(array ...$events): array
{
    $schema = json_decode((string) file_get_contents(__DIR__.'/fixtures/event.v1.schema.json'));
    $batch = json_decode(Json::encode([
        'sent_at' => '2026-10-17T13:45:12.345Z',
        'sdk' => ['name' => 'nightguardian-laravel', 'version' => '2.0.0'],
        'events' => $events,
    ]));

    $result = (new Validator)->validate($batch, $schema);

    if ($result->isValid()) {
        return [];
    }

    return array_map(
        static fn ($messages, $path) => $path.': '.implode('; ', (array) $messages),
        (new ErrorFormatter)->format($result->error()),
        array_keys((new ErrorFormatter)->format($result->error())),
    );
}
