<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Facades\NightGuardian;
use NightGuardian\Laravel\NightGuardian as Client;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Tests\Fakes\ArraySender;
use NightGuardian\Laravel\Transport\Buffer;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('captures a logged exception as an exception event', function () {
    Log::error('Payment failed', ['exception' => new RuntimeException('Gateway down'), 'order' => 42]);

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('exception')
        ->and($events[0]['level'])->toBe('error')
        ->and($events[0]['payload']['handled'])->toBeFalse()
        ->and($events[0]['payload']['exceptions'][0]['message'])->toBe('Gateway down')
        ->and($events[0]['context'])->toBe(['order' => 42])
        ->and($events[0]['reference'])->toMatch('/^NG-/')
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

it('captures a plain log call as a log event keeping the message template', function () {
    Log::error('Payment gateway timeout for order {order}', ['order' => 42]);

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('log')
        ->and($events[0]['payload']['message'])->toBe('Payment gateway timeout for order {order}')
        ->and($events[0]['payload']['context'])->toBe(['order' => 42])
        ->and($events[0]['payload']['exception'])->toBeNull()
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

it('respects the log level threshold', function () {
    Log::debug('d');
    Log::info('i');
    Log::warning('w');

    expect(ngCaptured())->toBe([]);

    Log::critical('c');
    Log::emergency('e');

    expect(array_column(ngCaptured(), 'level'))->toBe(['critical', 'emergency']);
});

it('uses a lower threshold when configured', function () {
    config(['nightguardian.log_level' => 'warning']);
    app()->forgetInstance(Client::class);

    Log::info('i');
    Log::warning('w');

    expect(array_column(ngCaptured(), 'level'))->toBe(['warning']);
});

it('falls back to error for an invalid configured level', function () {
    config(['nightguardian.log_level' => 'loud']);
    app()->forgetInstance(Client::class);

    Log::warning('w');
    Log::error('e');

    expect(array_column(ngCaptured(), 'level'))->toBe(['error']);
});

it('ignores exceptions on the ignore list and client-side HTTP errors', function (string $case) {
    switch ($case) {
        case 'validation':
            $exception = ValidationException::withMessages(['a' => 'b']);
            break;
        case 'authentication':
            $exception = new AuthenticationException;
            break;
        case 'authorization':
            $exception = new AuthorizationException;
            break;
        case 'model not found':
            $exception = new ModelNotFoundException;
            break;
        case 'not found http':
            $exception = new NotFoundHttpException;
            break;
        case 'http 403':
            $exception = new HttpException(403);
            break;
        default:
            $exception = new HttpException(429);
            break;
    }

    Log::error('x', ['exception' => $exception]);

    expect(ngCaptured())->toBe([]);
})->with(['validation', 'authentication', 'authorization', 'model not found', 'not found http', 'http 403', 'http 429']);

it('captures HTTP exceptions with a 5xx status', function () {
    Log::error('x', ['exception' => new HttpException(503, 'Maintenance')]);

    expect(ngCaptured('exception'))->toHaveCount(1);
});

it('honours a custom ignore list', function () {
    config(['nightguardian.ignore_exceptions' => [DomainException::class]]);
    app()->forgetInstance(Client::class);

    Log::error('x', ['exception' => new DomainException('skip me')]);
    Log::error('y', ['exception' => new RuntimeException('keep me')]);

    expect(array_column(ngCaptured(), 'payload'))->toHaveCount(1)
        ->and(ngCaptured()[0]['payload']['exceptions'][0]['class'])->toBe(RuntimeException::class);
});

it('captures the same exception instance only once', function () {
    $exception = new RuntimeException('once');

    Log::error('first', ['exception' => $exception]);
    Log::critical('second', ['exception' => $exception]);
    NightGuardian::report($exception);

    expect(ngCaptured())->toHaveCount(1);
});

it('does not capture anything logged while the SDK itself is running', function () {
    Guard::run(function () {
        Log::error('emitted by the sdk', ['exception' => new RuntimeException('internal')]);
    });

    expect(ngCaptured())->toBe([]);
});

it('makes log contexts JSON safe', function () {
    $resource = fopen('php://memory', 'r');

    Log::error('weird context', [
        'closure' => function () {
            return 1;
        },
        'object' => new stdClass,
        'resource' => $resource,
        'nested' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep']]]]]],
        'long' => str_repeat('x', 5000),
        'password' => 'hunter2',
    ]);

    $context = ngCaptured()[0]['payload']['context'];

    expect($context['closure'])->toBe('[closure]')
        ->and($context['object'])->toBe('[object stdClass]')
        ->and($context['resource'])->toStartWith('[resource')
        ->and($context['nested']['a']['b']['c']['d'])->toBe('[array]')
        ->and(mb_strlen($context['long']))->toBe(1001)
        ->and($context['password'])->toBe('[Filtered]')
        ->and(json_encode(ngCaptured()[0]))->not->toBeFalse();

    fclose($resource);
});

it('accepts stringable and non-string log messages', function () {
    Log::error(new class
    {
        public function __toString(): string
        {
            return 'stringable message';
        }
    });
    Log::error(['not' => 'a string']);

    expect(ngCaptured()[0]['payload']['message'])->toBe('stringable message')
        ->and(ngCaptured()[1]['payload']['message'])->toContain("'not' => 'a string'");
});

it('captures exceptions that bubble out of a request, with the request attached', function () {
    Route::get('/boom', function () {
        throw new RuntimeException('Kaboom');
    });

    $this->get('/boom')->assertStatus(500);

    $events = ngCaptured('exception');

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['exceptions'][0]['message'])->toBe('Kaboom');
});

it('does not capture client errors that bubble out of a request', function () {
    $this->get('/definitely-not-a-route')->assertStatus(404);

    expect(ngCaptured())->toBe([]);
});

// --- public API ---------------------------------------------------------------------------------------

it('reports handled exceptions with context through the facade', function () {
    NightGuardian::report(new RuntimeException('Handled'), ['invoice' => 12, 'api_key' => 'secret']);

    $event = ngCaptured()[0];

    expect($event['payload']['handled'])->toBeTrue()
        ->and($event['context'])->toBe(['invoice' => 12, 'api_key' => '[Filtered]'])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('reports exceptions on the ignore list when asked explicitly', function () {
    NightGuardian::report(new AuthenticationException);

    expect(ngCaptured())->toHaveCount(1);
});

it('reports messages with a level and context', function () {
    NightGuardian::message('Gateway answered late', 'warning', ['gateway' => 'mp']);
    NightGuardian::message('Default level');
    NightGuardian::message('Weird level', 'catastrophic');

    $events = ngCaptured('log');

    expect(array_column($events, 'level'))->toBe(['warning', 'error', 'error'])
        ->and($events[0]['payload'])->toMatchArray(['message' => 'Gateway answered late', 'context' => ['gateway' => 'mp']]);
});

it('flushes the buffer to the sender in one batch and empties it', function () {
    $sender = new ArraySender;
    app()->singleton(Client::class, function ($app) use ($sender) {
        return new Client(config('nightguardian'), $app->make(Buffer::class), $app->make(EventFactory::class), $sender);
    });

    NightGuardian::message('one');
    NightGuardian::message('two');
    NightGuardian::flush();
    NightGuardian::flush();

    expect($sender->batches)->toHaveCount(1)
        ->and($sender->batches[0])->toHaveCount(2)
        ->and(ngCaptured())->toBe([]);
});

it('never lets a failure escape to the application', function () {
    $broken = new class extends Buffer
    {
        /**
         * @param  mixed[]  $event
         */
        public function add($event): bool
        {
            throw new RuntimeException('buffer exploded');
        }

        public function drain(): array
        {
            throw new RuntimeException('drain exploded');
        }

        public function isEmpty(): bool
        {
            return false;
        }
    };

    $client = new Client(config('nightguardian'), $broken, app(EventFactory::class), new ArraySender);

    $client->report(new RuntimeException('x'));
    $client->message('y');
    $client->flush();

    expect(Guard::errors())->toBe(3)
        ->and(Guard::lastError())->toContain('exploded');
});

it('keeps at most 100 events per request and counts the rest', function () {
    for ($i = 0; $i < 120; $i++) {
        NightGuardian::message("event {$i}");
    }

    expect(ngCaptured())->toHaveCount(100)
        ->and(app(Buffer::class)->takeDropped())->toBe(20);
});

it('remembers the reference of the last captured exception for the error page', function () {
    NightGuardian::report(new RuntimeException('shown to the user'));

    $last = app(Buffer::class)->lastReference();

    expect($last['reference'])->toBe(ngCaptured()[0]['reference'])
        ->and($last['id'])->toBe(ngCaptured()[0]['id']);
});
