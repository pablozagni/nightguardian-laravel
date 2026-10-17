<?php

namespace NightGuardian\Laravel\Capture;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NightGuardian\Laravel\Http\FeedbackSigner;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;
use Throwable;

/**
 * Wraps the application's exception handler. It proxies everything and only touches `render()`:
 *
 * - HTML request, status 500, `APP_DEBUG=false`, `error_page` on, and this exception was captured:
 *   the NightGuardian error page with the reference code replaces the body (status stays 500).
 * - JSON request: only the `X-NightGuardian-Reference` header is added, the body is never changed.
 * - Anything that goes wrong while doing that returns the original response untouched.
 *
 * The page is never shown for an exception NightGuardian did not capture (SDK inactive, ignored
 * exception, below the log level): the application's own error page stays in charge.
 */
class ExceptionHandlerDecorator implements ExceptionHandler
{
    public const HEADER = 'X-NightGuardian-Reference';

    public function __construct(
        private ExceptionHandler $handler,
        private NightGuardian $client,
        private ViewFactory $views,
        private FeedbackSigner $signer,
        private Config $config,
    ) {}

    public function report(Throwable $e): void
    {
        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        $response = $this->handler->render($request, $e);

        try {
            return Guard::contain(fn () => $this->decorate($request, $e, $response), $response) ?? $response;
        } catch (Throwable $failure) {
            return $response;
        }
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    /**
     * Everything else (reportable, renderable, ignore, dontReport, ...) is the original handler's business.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->handler->{$method}(...$arguments);
    }

    public function original(): ExceptionHandler
    {
        return $this->handler;
    }

    private function decorate(mixed $request, Throwable $e, mixed $response): mixed
    {
        if (! $response instanceof \Symfony\Component\HttpFoundation\Response || ! $request instanceof Request) {
            return $response;
        }

        $captured = $this->client->referenceFor($e);

        if ($captured === null) {
            return $response;
        }

        if ($request->expectsJson()) {
            $response->headers->set(self::HEADER, $captured['reference']);

            return $response;
        }

        if ($response->getStatusCode() !== 500 || $this->config->get('app.debug') || ! $this->config->get('nightguardian.error_page', true)) {
            return $response;
        }

        $page = $this->views->make('nightguardian::error', $this->viewData($captured, $request))->render();

        $replacement = new Response($page, 500);

        // Keep whatever the original response carried (cookies, custom headers), except its body headers.
        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (! in_array(strtolower((string) $name), ['content-type', 'content-length', 'content-encoding', 'transfer-encoding'], true)) {
                $replacement->headers->set((string) $name, $values);
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $replacement->headers->setCookie($cookie);
        }

        $replacement->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $replacement->headers->set('Cache-Control', 'no-store, private');
        $replacement->headers->set(self::HEADER, $captured['reference']);

        return $replacement;
    }

    /**
     * @param  array{reference: string, id: string}  $captured
     * @return array<string, mixed>
     */
    private function viewData(array $captured, Request $request): array
    {
        $feedback = (bool) $this->config->get('nightguardian.feedback', true) && $this->signer->isAvailable();
        $signed = $feedback ? $this->signer->sign($captured['id'], $captured['reference']) : ['expires' => 0, 'signature' => ''];

        return [
            'reference' => $captured['reference'],
            'eventId' => $captured['id'],
            'feedback' => $feedback,
            'expires' => $signed['expires'],
            'signature' => $signed['signature'],
            'action' => $request->getBasePath().'/nightguardian/feedback',
            'home' => url('/'),
        ];
    }
}
