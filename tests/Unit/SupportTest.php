<?php

use NightGuardian\Laravel\Support\ContextSanitizer;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Transport\Buffer;

// --- Guard ------------------------------------------------------------------------------------------------

it('returns the callback result', function () {
    expect(Guard::run(fn () => 42))->toBe(42);
});

it('swallows exceptions, counts them and returns the default', function () {
    $result = Guard::run(function () {
        throw new RuntimeException('nope');
    }, 'fallback');

    expect($result)->toBe('fallback')
        ->and(Guard::errors())->toBe(1)
        ->and(Guard::lastError())->toContain('RuntimeException: nope');
});

it('swallows errors too, not only exceptions', function () {
    $result = Guard::run(fn () => intdiv(1, 0), 'safe');

    expect($result)->toBe('safe')->and(Guard::errors())->toBe(1);
});

it('skips nested calls so the SDK can never recurse', function () {
    $inner = 0;

    Guard::run(function () use (&$inner) {
        Guard::run(function () use (&$inner) {
            $inner++;
        });
    });

    expect($inner)->toBe(0)->and(Guard::busy())->toBeFalse();
});

it('is usable again after a failure', function () {
    Guard::run(function () {
        throw new RuntimeException('x');
    });

    expect(Guard::run(fn () => 'ok'))->toBe('ok');
});

it('hands the error counters over once', function () {
    Guard::run(function () {
        throw new RuntimeException('x');
    });

    $taken = Guard::take();

    expect($taken['count'])->toBe(1)
        ->and($taken['last'])->toContain('RuntimeException: x')
        ->and(Guard::errors())->toBe(0);
});

// --- ContextSanitizer --------------------------------------------------------------------------------------

enum NgSuit: string
{
    case Hearts = 'h';
}

enum NgPlain
{
    case Spade;
}

it('keeps JSON friendly values', function () {
    $context = ['a' => 1, 'b' => 1.5, 'c' => true, 'd' => null, 'e' => 'text', 'list' => [1, 2, 3]];

    expect(ContextSanitizer::sanitize($context))->toBe($context);
});

it('converts objects, enums, throwables and non-finite numbers', function () {
    $result = ContextSanitizer::sanitize([
        'enum' => NgSuit::Hearts,
        'plain' => NgPlain::Spade,
        'exception' => new RuntimeException('boom'),
        'stringable' => new class
        {
            public function __toString(): string
            {
                return 'as text';
            }
        },
        'json' => new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['x' => 1];
            }
        },
        'inf' => INF,
        'object' => new stdClass,
    ]);

    expect($result['enum'])->toBe('h')
        ->and($result['plain'])->toBe('Spade')
        ->and($result['exception'])->toBe(['class' => RuntimeException::class, 'message' => 'boom'])
        ->and($result['stringable'])->toBe('as text')
        ->and($result['json'])->toBe(['x' => 1])
        ->and($result['inf'])->toBe('INF')
        ->and($result['object'])->toBe('[object stdClass]');
});

it('limits the number of items per array', function () {
    $result = ContextSanitizer::sanitize(range(1, 80));

    expect($result)->toHaveCount(51)->and($result['…'])->toBe('30 more');
});

it('survives a failing JsonSerializable', function () {
    $result = ContextSanitizer::sanitize(['bad' => new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('no');
        }
    }]);

    expect($result['bad'])->toStartWith('[object');
});

// --- Buffer -----------------------------------------------------------------------------------------------

it('collects, drains and counts', function () {
    $buffer = new Buffer;

    $buffer->add(['id' => '1']);
    $buffer->add(['id' => '2']);

    expect($buffer->count())->toBe(2)
        ->and($buffer->isEmpty())->toBeFalse()
        ->and($buffer->drain())->toBe([['id' => '1'], ['id' => '2']])
        ->and($buffer->isEmpty())->toBeTrue()
        ->and($buffer->drain())->toBe([]);
});

it('drops events beyond the protocol batch size and reports how many', function () {
    $buffer = new Buffer;

    for ($i = 0; $i < 105; $i++) {
        $buffer->add(['id' => (string) $i]);
    }

    expect($buffer->count())->toBe(100)
        ->and($buffer->takeDropped())->toBe(5)
        ->and($buffer->takeDropped())->toBe(0);
});

it('remembers the last reference even when the buffer is full', function () {
    $buffer = new Buffer;

    for ($i = 0; $i < 100; $i++) {
        $buffer->add(['id' => (string) $i]);
    }

    $buffer->add(['id' => 'late', 'reference' => 'NG-7F3K-92QD']);

    expect($buffer->lastReference())->toBe(['reference' => 'NG-7F3K-92QD', 'id' => 'late']);

    $buffer->clearReference();

    expect($buffer->lastReference())->toBeNull();
});
