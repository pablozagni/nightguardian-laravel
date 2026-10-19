<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Event\Reference;
use NightGuardian\Laravel\Event\Release;
use NightGuardian\Laravel\Event\RequestContext;
use NightGuardian\Laravel\Event\RuntimeContext;
use NightGuardian\Laravel\Event\StackTrace;
use NightGuardian\Laravel\Event\UserContext;
use NightGuardian\Laravel\Privacy\Scrubber;
use NightGuardian\Laravel\Support\Json;

/**
 * @param  array<string, mixed>  $config
 */
function ngFactory(array $config = [], ?Closure $request = null, mixed $resolver = null, bool $sendEmail = false, ?Closure $guardUser = null): EventFactory
{
    $config += [
        'environment' => 'testing',
        'header_allowlist' => ['user-agent', 'accept', 'cookie', 'authorization'],
        'tags' => [],
    ];

    $scrubber = new Scrubber($config['scrub_keys'] ?? []);

    return new EventFactory(
        $config,
        $scrubber,
        new StackTrace(dirname(__DIR__, 2)),
        new RequestContext($scrubber, (bool) ($config['send_ip'] ?? false), (bool) ($config['send_request_body'] ?? false)),
        new UserContext($guardUser, $resolver, $sendEmail),
        new RuntimeContext('12.4.0'),
        new Release($config['release'] ?? '1.2.3', dirname(__DIR__, 2)),
        $request,
        fn () => new DateTimeImmutable('2026-10-17 10:45:11.902123', new DateTimeZone('America/Argentina/Buenos_Aires')),
    );
}

function ngRequest(string $uri = 'https://app.test/invoices/12?page=2', string $method = 'POST', array $parameters = [], array $server = []): Request
{
    return Request::create($uri, $method, $parameters, [], [], $server);
}

function ngException(): Throwable
{
    return new RuntimeException('Order 42 failed');
}

// --- envelope --------------------------------------------------------------------------------------

it('builds an exception event that matches the protocol', function () {
    $event = ngFactory()->exception(ngException(), ['tags' => ['tenant' => 'acme'], 'context' => ['order' => 42]]);

    expect(ngSchemaErrors($event))->toBe([])
        ->and($event['type'])->toBe('exception')
        ->and($event['level'])->toBe('error')
        ->and($event['environment'])->toBe('testing')
        ->and($event['release'])->toBe('1.2.3')
        ->and($event['platform'])->toBe('php')
        ->and($event['tags'])->toBe(['tenant' => 'acme'])
        ->and($event['context'])->toBe(['order' => 42])
        ->and($event['runtime'])->toMatchArray(['name' => 'php', 'framework' => 'laravel', 'framework_version' => '12.4.0', 'version' => PHP_VERSION])
        ->and($event['payload']['handled'])->toBeFalse()
        ->and($event['payload']['exceptions'][0]['class'])->toBe(RuntimeException::class)
        ->and(Reference::isValid($event['reference']))->toBeTrue();
});

it('generates a UUID v4 per event and a UTC millisecond timestamp', function () {
    $factory = ngFactory();
    $a = $factory->exception(ngException());
    $b = $factory->exception(ngException());

    expect($a['id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($a['id'])->not->toBe($b['id'])
        ->and($a['timestamp'])->toBe('2026-10-17T13:45:11.902Z');
});

it('honours an explicit timestamp, level, reference and fingerprint', function () {
    $event = ngFactory()->exception(ngException(), [
        'timestamp' => new DateTime('2026-01-02 03:04:05.678', new DateTimeZone('UTC')),
        'level' => 'CRITICAL',
        'reference' => 'NG-7F3K-92QD',
        'fingerprint' => ['{{ default }}', 'tenant-acme', 12],
    ]);

    expect($event['timestamp'])->toBe('2026-01-02T03:04:05.678Z')
        ->and($event['level'])->toBe('critical')
        ->and($event['reference'])->toBe('NG-7F3K-92QD')
        ->and($event['fingerprint'])->toBe(['{{ default }}', 'tenant-acme', '12'])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('falls back to error for unknown levels and ignores malformed references', function () {
    $event = ngFactory()->exception(ngException(), ['level' => 'catastrophic', 'reference' => 'not-a-reference']);

    expect($event['level'])->toBe('error')
        ->and(Reference::isValid($event['reference'] ?? ''))->toBeFalse()
        ->and($event)->not->toHaveKey('reference');
});

it('omits optional fields that are empty', function () {
    $event = ngFactory(['release' => ' '])->exception(ngException(), ['tags' => [], 'context' => [], 'fingerprint' => []]);

    expect($event)->not->toHaveKeys(['tags', 'context', 'fingerprint', 'user', 'request']);
});

it('merges configured and per-event tags within the protocol limits', function () {
    $factory = ngFactory(['tags' => ['region' => 'ar', 'tenant' => 'default']]);
    $many = [];

    for ($i = 0; $i < 40; $i++) {
        $many["tag{$i}"] = 'v';
    }

    $event = $factory->exception(ngException(), ['tags' => ['tenant' => 'acme', 'long' => str_repeat('x', 500), 'on' => true, 'nested' => ['no'], str_repeat('k', 100) => 'v'] + $many]);

    expect($event['tags']['region'])->toBe('ar')
        ->and($event['tags']['tenant'])->toBe('acme')
        ->and($event['tags']['on'])->toBe('true')
        ->and(strlen($event['tags']['long']))->toBe(200)
        ->and($event['tags'])->not->toHaveKey('nested')
        ->and(count($event['tags']))->toBeLessThanOrEqual(20)
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('uses a custom release instead of git', function () {
    expect(ngFactory(['release' => 'deploy-77'])->exception(ngException())['release'])->toBe('deploy-77');
});

// --- request -----------------------------------------------------------------------------------------

it('describes the current HTTP request with scrubbed URL and allowlisted headers', function () {
    $request = ngRequest('https://app.test/invoices/12?page=2&token=SECRET', 'post', [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_COOKIE' => 'laravel_session=abc',
        'HTTP_AUTHORIZATION' => 'Bearer abc',
        'REMOTE_ADDR' => '203.0.113.9',
    ]);

    $event = ngFactory(request: fn () => $request)->exception(ngException(), ['status_code' => 500, 'duration_ms' => 312]);

    expect($event['request'])->toBe([
        'method' => 'POST',
        'url' => 'https://app.test/invoices/12?page=2&token=[Filtered]',
        'status_code' => 500,
        'headers' => ['user-agent' => 'Mozilla/5.0', 'accept' => 'text/html'],
        'ip' => null,
        'duration_ms' => 312,
    ])->and(ngSchemaErrors($event))->toBe([]);
});

it('uses the route name, or the URI pattern when the route has no name', function () {
    $named = ngRequest();
    $named->setRouteResolver(fn () => (new Route('POST', 'invoices/{invoice}', fn () => null))->name('invoices.update'));
    $unnamed = ngRequest();
    $unnamed->setRouteResolver(fn () => new Route('POST', 'invoices/{invoice}', fn () => null));

    expect(ngFactory()->exception(ngException(), ['request' => $named])['request']['route'])->toBe('invoices.update')
        ->and(ngFactory()->exception(ngException(), ['request' => $unnamed])['request']['route'])->toBe('invoices/{invoice}');
});

it('only sends the IP when the app opts in', function () {
    $request = ngRequest(server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect(ngFactory(['send_ip' => false])->exception(ngException(), ['request' => $request])['request']['ip'])->toBeNull()
        ->and(ngFactory(['send_ip' => true])->exception(ngException(), ['request' => $request])['request']['ip'])->toBe('203.0.113.9');
});

it('only sends the request body when the app opts in, scrubbed', function () {
    $request = ngRequest(parameters: ['name' => 'Ada', 'password' => 'hunter2', '_token' => 'csrf', 'note' => 'card 4111 1111 1111 1111']);

    expect(ngFactory()->exception(ngException(), ['request' => $request])['request'])->not->toHaveKey('body');

    $body = ngFactory(['send_request_body' => true])->exception(ngException(), ['request' => $request])['request']['body'];

    expect($body)->toBe(['name' => 'Ada', 'password' => '[Filtered]', 'note' => 'card [Filtered]']);
});

it('replaces an oversized request body with its keys', function () {
    $request = ngRequest(parameters: ['blob' => str_repeat('x', 20000), 'other' => 'y']);

    $body = ngFactory(['send_request_body' => true])->exception(ngException(), ['request' => $request])['request']['body'];

    expect($body)->toBe(['_truncated' => true, 'keys' => ['blob', 'other']]);
});

it('has no request object in the console', function () {
    expect(ngFactory(request: fn () => null)->exception(ngException()))->not->toHaveKey('request');
});

it('can be told explicitly to skip the request', function () {
    $factory = ngFactory(request: fn () => ngRequest());

    expect($factory->exception(ngException(), ['request' => false]))->not->toHaveKey('request');
});

it('survives a failing request resolver', function () {
    $factory = ngFactory(request: function () {
        throw new RuntimeException('no request');
    });

    expect($factory->exception(ngException()))->not->toHaveKey('request');
});

// --- user --------------------------------------------------------------------------------------------

it('sends only the user id by default', function () {
    $user = new class implements Authenticatable
    {
        public string $email = 'ada@example.com';

        public string $name = 'Ada';

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return 42;
        }

        public function getAuthPassword(): string
        {
            return 'hash';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    expect(ngFactory(guardUser: fn () => $user)->exception(ngException())['user'])->toBe(['id' => '42'])
        ->and(ngFactory(sendEmail: true, guardUser: fn () => $user)->exception(ngException())['user'])
        ->toBe(['id' => '42', 'email' => 'ada@example.com', 'name' => 'Ada']);
});

it('uses the configured user resolver and only keeps email and name when allowed', function () {
    $resolver = fn () => ['id' => 7, 'email' => 'bob@example.com', 'name' => 'Bob', 'password' => 'x'];

    expect(ngFactory(resolver: $resolver)->exception(ngException())['user'])->toBe(['id' => '7'])
        ->and(ngFactory(resolver: $resolver, sendEmail: true)->exception(ngException())['user'])
        ->toBe(['id' => '7', 'email' => 'bob@example.com', 'name' => 'Bob']);
});

it('omits the user when nobody is logged in, or the resolver misbehaves', function (string $case) {
    $resolver = match ($case) {
        'no id' => fn () => ['email' => 'x@y.z'],
        'null' => fn () => null,
        'array id' => fn () => ['id' => ['no']],
        default => function () {
            throw new RuntimeException('db down');
        },
    };

    expect(ngFactory(resolver: $resolver)->exception(ngException()))->not->toHaveKey('user');
})->with(['no id', 'null', 'array id', 'throws']);

it('does not mangle a numeric user id that looks like a card number', function () {
    $event = ngFactory(resolver: fn () => ['id' => '4111111111111111'])->exception(ngException());

    expect($event['user']['id'])->toBe('4111111111111111');
});

// --- privacy -----------------------------------------------------------------------------------------

it('scrubs messages, context, payload and tags', function () {
    $exception = new RuntimeException('Gateway rejected card 4111 1111 1111 1111 with Bearer abc123token');

    $event = ngFactory()->exception($exception, [
        'context' => ['api_key' => 'k', 'order' => ['password' => 'p', 'id' => 5]],
        'tags' => ['token' => 'zzz', 'tenant' => 'acme'],
    ]);

    expect($event['payload']['exceptions'][0]['message'])->toBe('Gateway rejected card [Filtered] with Bearer [Filtered]')
        ->and($event['context'])->toBe(['api_key' => '[Filtered]', 'order' => ['password' => '[Filtered]', 'id' => 5]])
        ->and($event['tags'])->toBe(['token' => '[Filtered]', 'tenant' => 'acme']);
});

it('scrubs with the extra keys from the configuration', function () {
    $event = ngFactory(['scrub_keys' => ['cuit']])->exception(ngException(), ['context' => ['cuit' => '20-1-9', 'name' => 'Ada']]);

    expect($event['context'])->toBe(['cuit' => '[Filtered]', 'name' => 'Ada']);
});

it('does not touch identity fields when scrubbing', function () {
    $event = ngFactory()->exception(ngException(), ['reference' => 'NG-7F3K-92QD']);

    expect($event['reference'])->toBe('NG-7F3K-92QD')->and(ngSchemaErrors($event))->toBe([]);
});

// --- size limits -------------------------------------------------------------------------------------

it('replaces a free-form context over 32 KB', function () {
    $event = ngFactory()->exception(ngException(), ['context' => ['blob' => str_repeat('x', 40000), 'other' => 1]]);

    expect($event['context'])->toBe(['_truncated' => true, 'keys' => ['blob', 'other']]);
});

it('keeps events under 256 KB by dropping code context first', function () {
    $factory = ngFactory();
    $exceptions = [];

    for ($i = 0; $i < 30; $i++) {
        $exceptions[] = new RuntimeException(str_repeat('é', 3000).$i);
    }

    $big = array_reduce(array_reverse($exceptions), fn (?Throwable $previous, Throwable $e) => new RuntimeException($e->getMessage(), 0, $previous), null);
    $event = $factory->exception($big, ['context' => ['a' => str_repeat('b', 30000)]]);

    expect(Json::size($event))->toBeLessThanOrEqual(EventFactory::MAX_EVENT_BYTES);
});

it('truncates stacks as a last resort so the event still fits', function () {
    $factory = ngFactory();
    $e = new RuntimeException(str_repeat('m', 8000));

    for ($i = 0; $i < 9; $i++) {
        $e = new RuntimeException(str_repeat('m', 8000), 0, $e);
    }

    $event = $factory->exception($e);

    expect(Json::size($event))->toBeLessThanOrEqual(EventFactory::MAX_EVENT_BYTES)
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('encodes to JSON even when a message contains invalid UTF-8', function () {
    $event = ngFactory()->exception(new RuntimeException("bad \xB1\x31 bytes"));

    expect(json_decode(Json::encode($event), true)['payload']['exceptions'][0]['message'])->toContain('bad');
});

// --- the other event types ---------------------------------------------------------------------------

it('builds a test event with exception payload and info level', function () {
    $event = ngFactory()->test(new RuntimeException('NightGuardian test event'));

    expect($event['type'])->toBe('test')
        ->and($event['level'])->toBe('info')
        ->and($event['payload']['exceptions'])->toHaveCount(1)
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds log events with the message template and without an exception', function () {
    $event = ngFactory()->log('warning', 'Payment gateway timeout for order {order}', ['order' => 42], null, 'stack');

    expect($event['type'])->toBe('log')
        ->and($event['level'])->toBe('warning')
        ->and($event)->not->toHaveKey('reference')
        ->and($event['payload'])->toBe([
            'message' => 'Payment gateway timeout for order {order}',
            'channel' => 'stack',
            'context' => ['order' => 42],
            'exception' => null,
        ])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds log events that carry an exception and a reference', function () {
    $event = ngFactory()->log('error', 'Failed', [], ngException());

    expect($event['payload']['exception']['class'])->toBe(RuntimeException::class)
        ->and($event['payload'])->not->toHaveKey('context')
        ->and(Reference::isValid($event['reference']))->toBeTrue()
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds job_failed events', function () {
    $event = ngFactory()->jobFailed('App\\Jobs\\SendInvoice', 'default', 'database', 3, ngException());

    expect($event['type'])->toBe('job_failed')
        ->and($event['payload'])->toMatchArray(['job' => 'App\\Jobs\\SendInvoice', 'queue' => 'default', 'connection' => 'database', 'attempts' => 3])
        ->and($event['payload']['exceptions'])->toHaveCount(1)
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds schedule_failed events with the output tail', function () {
    $output = str_repeat('x', 5000).'THE END';
    $event = ngFactory()->scheduleFailed('php artisan invoices:sync', '*/5 * * * *', 1, $output, null);

    expect($event['type'])->toBe('schedule_failed')
        ->and($event['payload']['exit_code'])->toBe(1)
        ->and(strlen($event['payload']['output_tail']))->toBe(2048)
        ->and($event['payload']['output_tail'])->toEndWith('THE END')
        ->and($event['payload']['exceptions'])->toBe([])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds slow_request events with the request duration', function () {
    $request = ngRequest('https://app.test/reports', 'GET');
    $event = ngFactory()->slowRequest(4810, 2000, 64.24, 133, ['request' => $request, 'status_code' => 200]);

    expect($event['type'])->toBe('slow_request')
        ->and($event['level'])->toBe('warning')
        ->and($event['payload'])->toBe(['duration_ms' => 4810, 'threshold_ms' => 2000, 'memory_mb' => 64.2, 'queries' => 133])
        ->and($event['request']['duration_ms'])->toBe(4810)
        ->and($event['request']['status_code'])->toBe(200)
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds slow_query events with a relative location', function () {
    $event = ngFactory()->slowQuery('select * from invoices where customer_id = ?', 2350.456, 1000, 'mysql', ['file' => dirname(__DIR__, 2).'/app/Reports/Sales.php', 'line' => 40]);

    expect($event['type'])->toBe('slow_query')
        ->and($event['payload'])->toBe([
            'sql' => 'select * from invoices where customer_id = ?',
            'duration_ms' => 2350.5,
            'threshold_ms' => 1000,
            'connection' => 'mysql',
            'location' => ['file' => 'app/Reports/Sales.php', 'line' => 40],
        ])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds deploy events without a level and with the deployed release', function () {
    $event = ngFactory()->deploy(['release' => 'a1b2c3d', 'commit' => 'a1b2c3d4e5f6', 'branch' => 'main', 'deployer' => 'pull.sh', 'url' => null]);

    expect($event['type'])->toBe('deploy')
        ->and($event)->not->toHaveKey('level')
        ->and($event['release'])->toBe('a1b2c3d')
        ->and($event['payload'])->toBe(['release' => 'a1b2c3d', 'commit' => 'a1b2c3d4e5f6', 'branch' => 'main', 'deployer' => 'pull.sh'])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('builds feedback events without request, level or reference of their own', function () {
    $event = ngFactory(request: fn () => ngRequest())->feedback('6f1c1d1e-8a3b-4c55-9b2f-1d2e3f4a5b6c', 'NG-7F3K-92QD', str_repeat('c', 3000), 'ada@example.com');

    expect($event['type'])->toBe('feedback')
        ->and($event)->not->toHaveKeys(['level', 'request', 'reference'])
        ->and($event['payload']['event_id'])->toBe('6f1c1d1e-8a3b-4c55-9b2f-1d2e3f4a5b6c')
        ->and($event['payload']['reference'])->toBe('NG-7F3K-92QD')
        ->and(strlen($event['payload']['comment']))->toBe(2000)
        ->and($event['payload']['email'])->toBe('ada@example.com')
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('scrubs feedback comments', function () {
    $event = ngFactory()->feedback('6f1c1d1e-8a3b-4c55-9b2f-1d2e3f4a5b6c', null, 'My card 4111 1111 1111 1111 was charged twice');

    expect($event['payload']['comment'])->toBe('My card [Filtered] was charged twice')
        ->and($event['payload'])->not->toHaveKey('reference');
});

// --- protocol conformance ----------------------------------------------------------------------------

it('produces events accepted by the schema for a request with every field', function () {
    $request = ngRequest('https://app.test/invoices/12?page=2', 'POST', ['x' => 'y'], ['HTTP_USER_AGENT' => 'Mozilla/5.0']);
    $request->setRouteResolver(fn () => (new Route('POST', 'invoices/{invoice}', fn () => null))->name('invoices.update'));

    $factory = ngFactory(['send_ip' => true, 'send_request_body' => true], resolver: fn () => ['id' => 42, 'email' => 'a@b.c'], sendEmail: true);
    $inner = new PDOException('SQLSTATE[23000]: Integrity constraint violation');
    $event = $factory->exception(new RuntimeException('Wrapped', 0, $inner), [
        'request' => $request, 'status_code' => 500, 'duration_ms' => 312, 'tags' => ['tenant' => 'acme'],
        'context' => ['invoice' => 12], 'fingerprint' => ['{{ default }}', 'x'], 'handled' => true,
    ]);

    expect(ngSchemaErrors($event))->toBe([]);
});

it('keeps the schema copy identical to the collector\'s when both repos are side by side', function () {
    $collector = dirname(__DIR__, 3).'/collector/docs/protocol/event.v1.schema.json';

    if (! is_file($collector)) {
        $this->markTestSkipped('The collector repository is not next to this one.');
    }

    expect(json_decode((string) file_get_contents(__DIR__.'/../fixtures/event.v1.schema.json'), true))
        ->toBe(json_decode((string) file_get_contents($collector), true));
});

it('produces the same shape as the protocol example', function () {
    $example = json_decode((string) file_get_contents(__DIR__.'/../fixtures/example-exception.json'), true)['events'][0];
    $event = ngFactory()->exception(ngException());

    // Every top-level key the SDK sends must be a key the protocol example documents.
    expect(array_diff(array_keys($event), array_keys($example)))->toBe([]);
});

// --- container wiring ----------------------------------------------------------------------------------

it('is wired in the container using the application configuration', function () {
    config([
        'nightguardian.environment' => 'staging',
        'nightguardian.release' => 'wired-1',
        'nightguardian.tags' => ['team' => 'billing'],
        'nightguardian.scrub_keys' => ['cuit'],
    ]);

    $event = app(EventFactory::class)->exception(ngException(), ['context' => ['cuit' => '20-1-9']]);

    expect($event['environment'])->toBe('staging')
        ->and($event['release'])->toBe('wired-1')
        ->and($event['tags'])->toBe(['host' => 'localhost', 'team' => 'billing'])
        ->and($event['context'])->toBe(['cuit' => '[Filtered]'])
        ->and($event['runtime']['framework_version'])->toBe(app()->version())
        ->and($event)->not->toHaveKey('request')
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('does not query the database to find the user', function () {
    config(['auth.defaults.guard' => 'web', 'auth.guards.web' => ['driver' => 'session', 'provider' => 'users']]);
    config(['auth.providers.users' => ['driver' => 'eloquent', 'model' => stdClass::class]]);

    $event = app(EventFactory::class)->exception(ngException());

    expect($event)->not->toHaveKey('user');
});

// --- the automatic `host` tag ----------------------------------------------------------------------------------

it('tags every event with the host of APP_URL', function (string $appUrl, string $host) {
    $factory = ngFactory(['app_url' => $appUrl]);

    $event = $factory->exception(ngException());

    expect($event['tags'])->toBe(['host' => $host])
        ->and($factory->log('error', 'boom')['tags'])->toBe(['host' => $host])
        ->and($factory->jobFailed('App\Jobs\Pay', 'default', 'database', 1, ngException())['tags'])->toBe(['host' => $host])
        ->and($factory->scheduleFailed('inspire', '* * * * *', 1, null, null)['tags'])->toBe(['host' => $host])
        ->and(ngSchemaErrors($event))->toBe([]);
})->with([
    'https' => ['https://yapai.head-office.example.com', 'yapai.head-office.example.com'],
    'port and path' => ['http://Shop.Example.COM:8080/app', 'shop.example.com'],
    'no scheme' => ['tenant.example.org', 'tenant.example.org'],
]);

it('keeps the host tag the app already sends, in the configuration or per event', function () {
    $configured = ngFactory(['app_url' => 'https://a.example.com', 'tags' => ['host' => 'custom-host', 'team' => 'x']]);
    $perEvent = ngFactory(['app_url' => 'https://a.example.com']);

    expect($configured->exception(ngException())['tags'])->toBe(['host' => 'custom-host', 'team' => 'x'])
        ->and($perEvent->exception(ngException(), ['tags' => ['host' => 'per-event']])['tags'])->toBe(['host' => 'per-event']);
});

it('adds no host tag when it is disabled or APP_URL is unusable', function (array $config) {
    expect(ngFactory($config)->exception(ngException()))->not->toHaveKey('tags');
})->with([
    'disabled' => [['app_url' => 'https://a.example.com', 'host_tag' => false]],
    'no url' => [[]],
    'empty url' => [['app_url' => '']],
    'not a string' => [['app_url' => null]],
]);

it('reads the host tag settings from the application configuration', function () {
    config(['app.url' => 'https://client-7.saas.test', 'nightguardian.tags' => []]);
    app()->forgetInstance(EventFactory::class);

    expect(app(EventFactory::class)->exception(ngException())['tags'])->toBe(['host' => 'client-7.saas.test']);

    config(['nightguardian.host_tag' => false]);
    app()->forgetInstance(EventFactory::class);

    expect(app(EventFactory::class)->exception(ngException()))->not->toHaveKey('tags');
});
