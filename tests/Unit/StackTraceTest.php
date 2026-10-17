<?php

use NightGuardian\Laravel\Event\StackTrace;

/** The project root of these tests: the package directory. */
function ngRoot(): string
{
    return dirname(__DIR__, 2);
}

function ngThrower(string $message = 'boom', ?Throwable $previous = null): Throwable
{
    try {
        throw new RuntimeException($message, 7, $previous);
    } catch (Throwable $e) {
        return $e;
    }
}

function ngRecursive(int $depth): void
{
    if ($depth === 0) {
        throw new LogicException('deep');
    }

    ngRecursive($depth - 1);
}

function ngCapture(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    throw new LogicException('expected an exception');
}

it('describes class, message and code', function () {
    $exception = (new StackTrace(ngRoot()))->describe(ngThrower('Order 12 failed'));

    expect($exception['class'])->toBe(RuntimeException::class)
        ->and($exception['message'])->toBe('Order 12 failed')
        ->and($exception['code'])->toBe('7')
        ->and($exception['frames'])->toBeArray();
});

it('omits the code when it is zero', function () {
    $exception = (new StackTrace(ngRoot()))->describe(new Exception('x'));

    expect($exception)->not->toHaveKey('code');
});

it('starts the frames at the throw site, most recent call first', function () {
    $exception = ngThrower();
    $frames = (new StackTrace(ngRoot()))->frames($exception);

    expect($frames[0]['file'])->toBe('tests/Unit/StackTraceTest.php')
        ->and($frames[0]['function'])->toBe('ngThrower')
        ->and($frames[0]['in_app'])->toBeTrue()
        ->and($frames[0]['line'])->toBe($exception->getLine())
        ->and($frames[1]['function'])->toBe('{closure}');
});

it('maps each function to the line where its code was running', function () {
    $exception = ngCapture(fn () => ngRecursive(2));
    $frames = (new StackTrace(ngRoot()))->frames($exception);

    // throw site in ngRecursive(0), then the recursive calls, then the closure, then the test.
    expect($frames[0]['function'])->toBe('ngRecursive')
        ->and($frames[0]['line'])->toBe($exception->getLine())
        ->and($frames[1]['function'])->toBe('ngRecursive')
        ->and($frames[1]['line'])->toBeGreaterThan($frames[0]['line'])
        ->and($frames[2]['function'])->toBe('ngRecursive')
        ->and($frames[2]['line'])->toBe($frames[1]['line']);
});

it('ends with a frame for the top-level script without function', function () {
    $frames = (new StackTrace(ngRoot()))->frames(ngThrower());

    expect(end($frames))->not->toHaveKey('function');
});

it('reports project files relative and everything else absolute', function () {
    $trace = new StackTrace(ngRoot());
    $sep = DIRECTORY_SEPARATOR;
    $root = str_replace('\\', '/', ngRoot());

    expect($trace->relativePath($root.'/app/Http/X.php'))->toBe('app/Http/X.php')
        ->and($trace->relativePath(str_replace('/', $sep, $root).$sep.'app'.$sep.'Y.php'))->toBe('app/Y.php')
        ->and($trace->relativePath('/usr/lib/php/z.php'))->toBe('/usr/lib/php/z.php')
        ->and($trace->relativePath($root.'-other/z.php'))->toBe(str_replace('\\', '/', $root).'-other/z.php');
});

it('detects in-app files: inside the project and not vendor or node_modules', function (string $file, bool $expected) {
    $root = str_replace('\\', '/', ngRoot());

    expect((new StackTrace(ngRoot()))->isInApp($root.'/'.$file))->toBe($expected);
})->with([
    'app code' => ['app/Services/Invoicer.php', true],
    'routes' => ['routes/web.php', true],
    'vendor' => ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php', false],
    'nested vendor' => ['packages/x/vendor/y/z.php', false],
    'node_modules' => ['node_modules/pkg/index.php', false],
    'vendorish name' => ['app/vendors/Thing.php', true],
]);

it('treats files outside the project as not in-app', function () {
    expect((new StackTrace(ngRoot()))->isInApp('/usr/share/php/x.php'))->toBeFalse();
});

it('marks vendor frames as not in-app and gives them no code context', function () {
    $frames = (new StackTrace(ngRoot()))->frames(ngCapture(fn () => app()->make('does-not-exist-'.uniqid())));
    $vendor = array_values(array_filter($frames, fn (array $f) => str_starts_with($f['file'], 'vendor/')));

    expect($vendor)->not->toBeEmpty();

    foreach ($vendor as $frame) {
        expect($frame['in_app'])->toBeFalse()->and($frame)->not->toHaveKey('context');
    }
});

it('adds ±5 lines of code context to in-app frames', function () {
    $frames = (new StackTrace(ngRoot()))->frames(ngThrower());
    $context = $frames[0]['context'];

    expect($context['line'])->toContain('throw new RuntimeException')
        ->and($context['pre'])->toHaveCount(5)
        ->and($context['post'])->toHaveCount(5)
        ->and($context['pre'][4])->toContain('try {');
});

it('shortens the context near the start of a file', function () {
    $path = sys_get_temp_dir().'/ng-ctx-'.uniqid().'.php';
    file_put_contents($path, "<?php\nthrow new Exception('x');\n");

    $trace = new StackTrace(dirname($path));

    try {
        include $path;
    } catch (Throwable $e) {
        $frames = $trace->frames($e);
    }

    unlink($path);

    expect($frames[0]['context']['pre'])->toBe(['<?php'])
        ->and($frames[0]['context']['line'])->toBe("throw new Exception('x');")
        ->and($frames[0]['context']['post'])->toBe([]);
});

it('keeps lines of the context short', function () {
    $path = sys_get_temp_dir().'/ng-long-'.uniqid().'.php';
    file_put_contents($path, "<?php\n\$x = '".str_repeat('a', 5000)."'; throw new Exception('x');\n");

    try {
        include $path;
    } catch (Throwable $e) {
        $frames = (new StackTrace(dirname($path)))->frames($e);
    }

    unlink($path);

    expect(mb_strlen($frames[0]['context']['line']))->toBe(250);
});

it('limits code context to the first 30 in-app frames', function () {
    $frames = (new StackTrace(ngRoot()))->frames(ngCapture(fn () => ngRecursive(60)));
    $withContext = array_filter($frames, fn (array $f) => isset($f['context']));

    expect(count($frames))->toBeGreaterThan(60)->and(count($withContext))->toBe(30);
});

it('keeps the first 50 and last 50 frames of very deep stacks', function () {
    $exception = ngCapture(fn () => ngRecursive(250));
    $frames = (new StackTrace(ngRoot()))->frames($exception);

    expect($frames)->toHaveCount(100)
        ->and($frames[0]['line'])->toBe($exception->getLine())
        ->and(end($frames))->not->toHaveKey('function');
});

it('flattens the previous chain from outermost to innermost', function () {
    $inner = new InvalidArgumentException('inner');
    $middle = new RuntimeException('middle', 0, $inner);
    $outer = new LogicException('outer', 0, $middle);

    $chain = (new StackTrace(ngRoot()))->chain($outer);

    expect(array_column($chain, 'class'))->toBe([LogicException::class, RuntimeException::class, InvalidArgumentException::class])
        ->and(array_column($chain, 'message'))->toBe(['outer', 'middle', 'inner']);
});

it('caps the chain length', function () {
    $e = new Exception('0');

    for ($i = 1; $i < 30; $i++) {
        $e = new Exception((string) $i, 0, $e);
    }

    expect((new StackTrace(ngRoot()))->chain($e))->toHaveCount(StackTrace::MAX_CHAIN);
});

it('truncates huge messages', function () {
    $exception = (new StackTrace(ngRoot()))->describe(new Exception(str_repeat('é', 20000)));

    expect(mb_strlen($exception['message']))->toBe(8192);
});

it('labels frames that come from internal functions', function () {
    $exception = ngCapture(fn () => array_map(function () {
        throw new Exception('in callback');
    }, [1]));

    $frames = (new StackTrace(ngRoot()))->frames($exception);
    $internal = array_values(array_filter($frames, fn (array $f) => ($f['function'] ?? null) === 'array_map'));

    expect($internal)->toHaveCount(1)
        ->and($internal[0]['file'])->toBe('[internal]')
        ->and($internal[0]['line'])->toBeNull()
        ->and($internal[0]['in_app'])->toBeFalse();
});

it('survives source files that are missing or unreadable', function () {
    $path = sys_get_temp_dir().'/ng-gone-'.uniqid().'.php';
    file_put_contents($path, "<?php\nthrow new Exception('x');\n");

    try {
        include $path;
    } catch (Throwable $e) {
        $captured = $e;
    }

    unlink($path);

    $frames = (new StackTrace(dirname($path)))->frames($captured);

    expect($frames[0]['in_app'])->toBeTrue()->and($frames[0])->not->toHaveKey('context');
});
