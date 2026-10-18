<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use NightGuardian\Laravel\NightGuardian as Client;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Transport;

// The request context (and with it the status code) only exists in a web context: see WebContextTestCase.

beforeEach(function () {
    config(['app.debug' => false]);

    Route::get('/boom', fn () => throw new RuntimeException('Kaboom'));
});

function ngSentEvents(): array
{
    /** @var FakeTransport $transport */
    $transport = app(Transport::class);

    return $transport->events();
}

function ngExceptionEvents(): array
{
    return array_values(array_filter(ngSentEvents(), fn (array $e) => $e['type'] === 'exception'));
}

function ngStatusOf(array $event): ?int
{
    return $event['request']['status_code'] ?? null;
}

it('records 500 as the status code of an unhandled exception', function () {
    $this->get('/boom', ['Accept' => 'text/html'])->assertStatus(500);

    $events = ngExceptionEvents();

    expect($events)->toHaveCount(1)
        ->and(ngStatusOf($events[0]))->toBe(500)
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

class NgRenderedElsewhere extends RuntimeException
{
    public function render(): Response
    {
        return response('rendered by the exception itself', 507);
    }
}

it('records the status of the response that was actually sent, even when the exception renders another one', function () {
    Route::get('/insufficient', fn () => throw new NgRenderedElsewhere('Custom render'));

    $this->get('/insufficient', ['Accept' => 'text/html'])->assertStatus(507);

    expect(ngStatusOf(ngExceptionEvents()[0]))->toBe(507);
});

it('records the status for JSON requests too', function () {
    $this->getJson('/boom')->assertStatus(500);

    expect(ngStatusOf(ngExceptionEvents()[0]))->toBe(500);
});

it('does not invent a status code for exceptions handled by the application', function () {
    app(Client::class)->report(new RuntimeException('caught'));
    app(Client::class)->flush();

    $reported = array_values(array_filter(ngSentEvents(), fn (array $e) => ($e['payload']['handled'] ?? false) === true));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]['request'] ?? [])->not->toHaveKey('status_code');
});
