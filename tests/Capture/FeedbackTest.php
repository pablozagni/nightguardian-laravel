<?php

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Route;
use NightGuardian\Laravel\Http\FeedbackSigner;
use NightGuardian\Laravel\Support\Clock;
use NightGuardian\Laravel\Tests\Fakes\FakeClock;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\CircuitBreaker;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Spool;
use NightGuardian\Laravel\Transport\Stats;
use NightGuardian\Laravel\Transport\Transport;
use NightGuardian\Laravel\Transport\TransportResult;

beforeEach(function () {
    config(['app.debug' => false]);
    Route::get('/boom', fn () => throw new RuntimeException('Kaboom'));
});

/**
 * Triggers an error and returns the hidden fields of the form on the error page.
 *
 * @return array{event_id: string, reference: string, expires: string, signature: string}
 */
function ngFormFields($test): array
{
    $html = $test->get('/boom', ['Accept' => 'text/html'])->getContent();
    $fields = [];

    foreach (['event_id', 'reference', 'expires', 'signature'] as $name) {
        preg_match('/<input type="hidden" name="'.$name.'" value="([^"]*)"/', $html, $m);
        $fields[$name] = $m[1] ?? '';
    }

    return $fields;
}

function ngPostFeedback($test, array $fields, string $comment = 'I was saving invoice 12 and clicked Confirm')
{
    return $test->post('/nightguardian/feedback', $fields + ['comment' => $comment], ['Accept' => 'text/html']);
}

function ngFeedbackEvents(): array
{
    return array_values(array_filter(app(Transport::class)->events(), fn (array $e) => $e['type'] === 'feedback'));
}

// --- the happy path ---------------------------------------------------------------------------------------

it('sends the comment as a feedback event linked to the original event and says it was sent', function () {
    $fields = ngFormFields($this);

    $response = ngPostFeedback($this, $fields);

    $response->assertOk()->assertSee('Thanks, your message was sent ✓');

    $feedback = ngFeedbackEvents();

    expect($feedback)->toHaveCount(1)
        ->and($feedback[0]['payload'])->toBe([
            'event_id' => $fields['event_id'],
            'reference' => $fields['reference'],
            'comment' => 'I was saving invoice 12 and clicked Confirm',
        ])
        ->and($feedback[0])->not->toHaveKey('request')
        ->and(ngSchemaErrors($feedback[0]))->toBe([])
        ->and(app(Transport::class)->events()[0]['id'])->toBe($fields['event_id']);
});

it('shows the thanks page in the application locale', function () {
    $fields = ngFormFields($this);
    app()->setLocale('es');

    ngPostFeedback($this, $fields)->assertSee('Gracias, tu mensaje fue enviado ✓');
});

it('shows the reference code again on the thanks page', function () {
    $fields = ngFormFields($this);

    ngPostFeedback($this, $fields)->assertSee($fields['reference']);
});

it('scrubs sensitive data in the comment and cuts it at 2,000 characters', function () {
    $fields = ngFormFields($this);

    ngPostFeedback($this, $fields, 'my card 4111 1111 1111 1111 '.str_repeat('x', 3000));

    $comment = ngFeedbackEvents()[0]['payload']['comment'];

    expect($comment)->toStartWith('my card [Filtered] ')->and(mb_strlen($comment))->toBeLessThanOrEqual(2000);
});

it('works without a session, cookies or CSRF token', function () {
    $fields = ngFormFields($this);
    $response = ngPostFeedback($this, $fields);

    $route = app('router')->getRoutes()->getByName('nightguardian.feedback');

    expect($route->gatherMiddleware())->toBe([])
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
});

// --- honesty about delivery ------------------------------------------------------------------------------

it('says the message was recorded, not sent, when the collector is down', function () {
    $fields = ngFormFields($this);
    app(Transport::class)->script[] = new TransportResult(0, 'curl error 7');

    $response = ngPostFeedback($this, $fields);

    $response->assertOk()
        ->assertSee('your message was recorded and will be delivered shortly')
        ->assertDontSee('was sent');

    expect(app(Dispatcher::class)->spool()->count())->toBe(1);
});

it('says it recorded the message when the breaker is open, without touching the network', function () {
    $fields = ngFormFields($this);
    app(Dispatcher::class)->breaker()->recordFailure('down');
    $batches = count(app(Transport::class)->batches);

    ngPostFeedback($this, $fields)->assertSee('was recorded');

    expect(app(Transport::class)->batches)->toHaveCount($batches);
});

it('admits it when the message could be neither sent nor stored', function () {
    $fields = ngFormFields($this);
    $blocker = tempnam(sys_get_temp_dir(), 'ngfile');
    $clock = app(Clock::class);

    app()->singleton(Dispatcher::class, fn () => new Dispatcher(
        app(Transport::class),
        new Spool($blocker.'/spool', 1048576, 7, $clock),
        new CircuitBreaker($blocker.'/b.json', $clock),
        new Stats($blocker.'/s.json'),
        $clock,
        $blocker.'/l',
    ));
    app(Transport::class)->script[] = new TransportResult(503, 'HTTP 503');

    $response = ngPostFeedback($this, $fields);
    unlink($blocker);

    $response->assertStatus(500)->assertSee('We could not save your message')->assertDontSee('was sent');
});

it('does not claim delivery when the collector rejected the token', function () {
    $fields = ngFormFields($this);
    app(Transport::class)->script[] = new TransportResult(401, 'HTTP 401');

    ngPostFeedback($this, $fields)->assertSee('was recorded')->assertDontSee('was sent');
});

// --- protection ------------------------------------------------------------------------------------------

it('rejects a form without a valid signature', function (string $field, string $value) {
    $fields = ngFormFields($this);
    $fields[$field] = $value;

    ngPostFeedback($this, $fields)->assertStatus(403)->assertSee('not valid');

    expect(ngFeedbackEvents())->toBe([]);
})->with([
    'tampered signature' => ['signature', 'deadbeef'],
    'other event' => ['event_id', '00000000-0000-4000-8000-000000000000'],
    'other reference' => ['reference', 'NG-AAAA-BBBB'],
    'extended expiry' => ['expires', '4102444800'],
]);

it('rejects incomplete or malformed forms', function (array $override) {
    $fields = array_merge(ngFormFields($this), $override);

    ngPostFeedback($this, $fields)->assertStatus(422);

    expect(ngFeedbackEvents())->toBe([]);
})->with([
    'no event id' => [['event_id' => '']],
    'bad reference' => [['reference' => 'not-a-reference']],
    'no signature' => [['signature' => '']],
    'text expiry' => [['expires' => 'tomorrow']],
]);

it('rejects an expired form after 15 minutes', function () {
    $fields = ngFormFields($this);
    app(Clock::class)->advance(FeedbackSigner::TTL_SECONDS + 1);

    ngPostFeedback($this, $fields)->assertStatus(403)->assertSee('has expired');

    expect(ngFeedbackEvents())->toBe([]);
});

it('still accepts the form just before it expires', function () {
    $fields = ngFormFields($this);
    app(Clock::class)->advance(FeedbackSigner::TTL_SECONDS - 5);

    ngPostFeedback($this, $fields)->assertOk()->assertSee('was sent');
});

it('asks for a message when the comment is empty', function () {
    $fields = ngFormFields($this);

    ngPostFeedback($this, $fields, "   \n ")->assertStatus(422)->assertSee('Please write a message first.');

    expect(ngFeedbackEvents())->toBe([]);
});

it('allows 5 messages per minute per IP and then answers 429', function () {
    $fields = ngFormFields($this);

    for ($i = 1; $i <= 5; $i++) {
        ngPostFeedback($this, $fields, "message {$i}")->assertOk();
    }

    ngPostFeedback($this, $fields, 'message 6')->assertStatus(429)->assertSee('Too many messages');

    expect(ngFeedbackEvents())->toHaveCount(5);
});

it('does not block feedback when the cache is broken', function () {
    $fields = ngFormFields($this);

    $broken = Mockery::mock(CacheFactory::class);
    $broken->shouldReceive('store')->andThrow(new RuntimeException('redis is down'));
    $this->app->instance(CacheFactory::class, $broken);

    ngPostFeedback($this, $fields)->assertOk()->assertSee('was sent');
});

it('does not exist when feedback is switched off', function () {
    config(['nightguardian.feedback' => false]);
    $html = $this->get('/boom', ['Accept' => 'text/html'])->getContent();

    expect($html)->not->toContain('<form')->and($html)->toContain('Reference code');

    $this->post('/nightguardian/feedback', ['comment' => 'x'])->assertStatus(404);
});

it('never lets an internal failure escape as an exception page', function () {
    $fields = ngFormFields($this);
    $this->app->instance(Transport::class, new class extends FakeTransport
    {
        public function send(array $events): TransportResult
        {
            throw new RuntimeException('transport exploded');
        }
    });
    app()->forgetInstance(Dispatcher::class);

    $response = ngPostFeedback($this, $fields);

    expect($response->getStatusCode())->toBeLessThan(600)->and($response->getContent())->toContain('Reference code');
});

// --- the signer ------------------------------------------------------------------------------------------

it('signs and verifies', function () {
    $clock = new FakeClock;
    $signer = new FeedbackSigner('secret-key', $clock);
    $signed = $signer->sign('event-1', 'NG-7F3K-92QD');

    expect($signer->verify('event-1', 'NG-7F3K-92QD', $signed['expires'], $signed['signature']))->toBe(FeedbackSigner::VALID)
        ->and($signer->verify('event-2', 'NG-7F3K-92QD', $signed['expires'], $signed['signature']))->toBe(FeedbackSigner::INVALID)
        ->and((new FeedbackSigner('other-key', $clock))->verify('event-1', 'NG-7F3K-92QD', $signed['expires'], $signed['signature']))->toBe(FeedbackSigner::INVALID);

    $clock->advance(901);

    expect($signer->verify('event-1', 'NG-7F3K-92QD', $signed['expires'], $signed['signature']))->toBe(FeedbackSigner::EXPIRED);
});

it('refuses to sign without an application key', function () {
    $signer = new FeedbackSigner('', new FakeClock);

    expect($signer->isAvailable())->toBeFalse()
        ->and($signer->verify('e', 'NG-7F3K-92QD', time() + 100, hash_hmac('sha256', 'e|NG-7F3K-92QD|'.(time() + 100), '')))->toBe(FeedbackSigner::INVALID);
});
