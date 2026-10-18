<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use NightGuardian\Laravel\Capture\ExceptionHandlerDecorator;
use NightGuardian\Laravel\Event\Reference;
use NightGuardian\Laravel\Http\FeedbackSigner;
use NightGuardian\Laravel\NightGuardian as Client;
use NightGuardian\Laravel\NightGuardianServiceProvider;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Transport;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::get('/boom', function () {
        throw new RuntimeException('Kaboom');
    });
    Route::get('/teapot', function () {
        throw new HttpException(418, 'short and stout');
    });
    Route::get('/gone', function () {
        throw new HttpException(503, 'maintenance');
    });
    Route::get('/fine', function () {
        return 'all good';
    });
});

function ngTransportFake(): FakeTransport
{
    return app(Transport::class);
}

// --- when the page is shown -------------------------------------------------------------------------------

it('shows the friendly page with the reference code for an HTML request that ends in a 500', function () {
    $response = $this->get('/boom', ['Accept' => 'text/html']);

    $response->assertStatus(500)->assertSee('Something went wrong');

    $html = $response->getContent();
    preg_match('/NG-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}/', $html, $match);

    expect($match)->not->toBeEmpty()
        ->and(Reference::isValid($match[0]))->toBeTrue()
        ->and($response->headers->get('X-NightGuardian-Reference'))->toBe($match[0])
        ->and($html)->not->toContain('Kaboom')
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
});

it('shows the reference code of the event that was actually captured and sent', function () {
    $response = $this->get('/boom', ['Accept' => 'text/html']);

    $reference = $response->headers->get('X-NightGuardian-Reference');
    $sent = ngTransportFake()->events();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['reference'])->toBe($reference)
        ->and($sent[0]['payload']['exceptions'][0]['message'])->toBe('Kaboom')
        ->and($response->getContent())->toContain('value="'.$sent[0]['id'].'"');
});

it('is a self-contained page: inline CSS, no external assets, JavaScript only as an enhancement', function () {
    $html = $this->get('/boom', ['Accept' => 'text/html'])->getContent();

    expect($html)->toContain('<style>')
        ->and($html)->not->toMatch('/<link\b/i')
        ->and($html)->not->toMatch('/<script[^>]+src=/i')
        ->and($html)->not->toMatch('/<img\b/i')
        ->and($html)->not->toMatch('/@import/i')
        ->and($html)->not->toMatch('/url\(/i')
        ->and($html)->not->toMatch('/(?:src|href)=["\']https?:\/\/(?!localhost)/i')
        ->and($html)->toContain('prefers-color-scheme: dark')
        ->and($html)->toContain('<form method="post"');
});

it('uses the application locale', function (string $locale, string $title, string $label) {
    app()->setLocale($locale);

    $html = $this->get('/boom', ['Accept' => 'text/html'])->getContent();

    expect($html)->toContain($title)->and($html)->toContain($label)->and($html)->toContain('<html lang="'.$locale.'">');
})->with([
    'english' => ['en', 'Something went wrong', 'Reference code'],
    'spanish' => ['es', 'Algo salió mal', 'Código de referencia'],
]);

it('keeps the status 500 and never caches the page', function () {
    $response = $this->get('/boom', ['Accept' => 'text/html']);

    expect($response->getStatusCode())->toBe(500)->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

// --- when it is not shown -----------------------------------------------------------------------------------

it('leaves Laravel\'s debug page alone when APP_DEBUG is true', function () {
    config(['app.debug' => true]);

    $response = $this->get('/boom', ['Accept' => 'text/html']);

    // (Laravel's debug page quotes source code, so look at the header instead of the body.)
    expect($response->headers->has('X-NightGuardian-Reference'))->toBeFalse()->and($response->getStatusCode())->toBe(500);
});

it('can be turned off with error_page=false but still captures the error', function () {
    config(['nightguardian.error_page' => false]);

    $response = $this->get('/boom', ['Accept' => 'text/html']);

    expect($response->getContent())->not->toContain('ng-reference')
        ->and(ngTransportFake()->events())->toHaveCount(1);
});

it('does not touch the body of JSON requests, it only adds the reference header', function () {
    $json = $this->getJson('/boom');

    $original = json_decode($json->getContent(), true);

    expect($json->getStatusCode())->toBe(500)
        ->and($json->headers->get('X-NightGuardian-Reference'))->toMatch(Reference::PATTERN)
        ->and($original)->toHaveKey('message')
        ->and($json->getContent())->not->toContain('ng-reference');
});

it('leaves client errors and normal pages untouched', function () {
    $this->get('/fine', ['Accept' => 'text/html'])->assertOk()->assertSee('all good');
    $this->get('/teapot', ['Accept' => 'text/html'])->assertStatus(418);
    $this->get('/nope', ['Accept' => 'text/html'])->assertStatus(404);

    expect($this->get('/teapot', ['Accept' => 'text/html'])->getContent())->not->toContain('ng-reference');
});

it('leaves other 5xx responses to the application', function () {
    $response = $this->get('/gone', ['Accept' => 'text/html']);

    // 503 is captured (server error) but it is not the unexpected-500 case the page is for.
    expect($response->getStatusCode())->toBe(503)->and($response->getContent())->not->toContain('ng-reference');
});

it('does not replace the page for an exception that was not captured', function () {
    config(['nightguardian.ignore_exceptions' => [RuntimeException::class]]);
    app()->forgetInstance(Client::class);

    // A new client with the new ignore list is what the log listener resolves from now on.
    $response = $this->get('/boom', ['Accept' => 'text/html']);

    expect($response->getContent())->not->toContain('ng-reference');
});

// --- the decorator itself ------------------------------------------------------------------------------------

it('is installed around the application handler and proxies everything else', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler)->toBeInstanceOf(ExceptionHandlerDecorator::class)
        ->and($handler->shouldReport(new RuntimeException('x')))->toBeTrue()
        ->and($handler->shouldReport(new HttpException(404)))->toBeFalse();

    // Methods that only exist on the framework's handler still work through __call
    // (`ignore()` is public from Laravel 9; on older versions it is protected and nobody can call it from outside).
    if (! is_callable([$handler->original(), 'ignore'])) {
        expect($handler->original())->toBeInstanceOf(Handler::class);

        return;
    }

    $handler->ignore(DomainException::class);

    expect($handler->shouldReport(new DomainException('x')))->toBeFalse();
});

it('is not installed twice', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler->original())->not->toBeInstanceOf(ExceptionHandlerDecorator::class);
});

function ngDecorator(ExceptionHandler $inner, ?ViewFactory $views = null): ExceptionHandlerDecorator
{
    return new ExceptionHandlerDecorator($inner, app(Client::class), $views ?? app(ViewFactory::class), app(FeedbackSigner::class), app('config'));
}

/**
 * @param  Response|JsonResponse  $response
 */
function ngInnerHandler($response): ExceptionHandler
{
    return new class($response) implements ExceptionHandler
    {
        /**
         * @var mixed
         */
        private $response;

        /**
         * @param  mixed  $response
         */
        public function __construct($response)
        {
            $this->response = $response;
        }

        public function report(Throwable $e): void {}

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            return $this->response;
        }

        public function renderForConsole($output, Throwable $e): void {}
    };
}

it('returns the original response when rendering the page fails', function () {
    $exception = new RuntimeException('boom');
    app(Client::class)->captureThrowable($exception, 'error');

    $views = Mockery::mock(ViewFactory::class);
    $views->shouldReceive('make')->andThrow(new RuntimeException('view exploded'));

    $original = new Response('original body', 500);
    $result = ngDecorator(ngInnerHandler($original), $views)->render(Request::create('/x', 'GET'), $exception);

    expect($result)->toBe($original)->and($result->getContent())->toBe('original body');
});

it('keeps cookies and custom headers of the original response', function () {
    $exception = new RuntimeException('boom');
    app(Client::class)->captureThrowable($exception, 'error');

    $original = new Response('original body', 500, ['X-Request-Id' => 'abc123']);
    $original->headers->setCookie(new Cookie('theme', 'dark'));

    $result = ngDecorator(ngInnerHandler($original))->render(Request::create('/x', 'GET'), $exception);

    expect($result)->not->toBe($original)
        ->and($result->getStatusCode())->toBe(500)
        ->and($result->headers->get('X-Request-Id'))->toBe('abc123')
        ->and($result->headers->getCookies())->toHaveCount(1)
        ->and($result->getContent())->toContain('Reference code');
});

it('does not look at responses that are not HTTP responses', function () {
    $exception = new RuntimeException('boom');
    app(Client::class)->captureThrowable($exception, 'error');

    $handler = new class implements ExceptionHandler
    {
        public function report(Throwable $e): void {}

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            return 'not a response';
        }

        public function renderForConsole($output, Throwable $e): void {}
    };

    expect(ngDecorator($handler)->render(Request::create('/x'), $exception))->toBe('not a response');
});

it('does not break console rendering or reporting', function () {
    $inner = Mockery::mock(ExceptionHandler::class);
    $inner->shouldReceive('report')->once();
    $inner->shouldReceive('renderForConsole')->once();

    $decorator = ngDecorator($inner);
    $decorator->report(new RuntimeException('x'));
    $decorator->renderForConsole(new NullOutput, new RuntimeException('x'));

    expect(true)->toBeTrue();
});

it('publishes its views and translations', function () {
    $provider = NightGuardianServiceProvider::class;

    expect(ServiceProvider::pathsToPublish($provider, 'nightguardian-views'))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish($provider, 'nightguardian-lang'))->not->toBeEmpty();
});
