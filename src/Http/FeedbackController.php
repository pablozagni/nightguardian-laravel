<?php

namespace NightGuardian\Laravel\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NightGuardian\Laravel\Event\Reference;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Transport\Dispatcher;
use Throwable;

/**
 * `POST /nightguardian/feedback`: the "what were you doing?" box of the error page.
 *
 * Registered without the `web` middleware group: no session, no CSRF, no cookies. Protected by the signed
 * hidden fields, a 15 minute lifetime and a per-IP limit of 5 messages per minute.
 *
 * The feedback event is sent synchronously (2 s timeout). The thank-you page only says "sent" when the
 * collector accepted it; if it was spooled it says "recorded"; if it could not even be stored it says so.
 */
class FeedbackController
{
    /**
     * @var NightGuardian
     */
    private $client;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * @var FeedbackSigner
     */
    private $signer;

    /**
     * @var CacheFactory
     */
    private $cache;

    /**
     * @var Config
     */
    private $config;

    public const MAX_COMMENT = 2000;

    private const LIMIT_PER_MINUTE = 5;

    public function __construct(NightGuardian $client, Dispatcher $dispatcher, FeedbackSigner $signer, CacheFactory $cache, Config $config)
    {
        $this->client = $client;
        $this->dispatcher = $dispatcher;
        $this->signer = $signer;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function store(Request $request): Response
    {
        $reference = null;

        try {
            $reference = $this->reference($request);
            [$status, $httpStatus] = $this->handle($request);

            return $this->page($status, $reference, $httpStatus);
        } catch (Throwable $e) {
            Guard::record($e);

            return $this->page('failed', $reference, 500);
        }
    }

    /**
     * @return array{0: string, 1: int} the page status and the HTTP status
     */
    private function handle(Request $request): array
    {
        if (! $this->client->isActive() || ! (bool) $this->config->get('nightguardian.feedback', true)) {
            return ['invalid', 404];
        }

        $eventId = $this->text($request, 'event_id', 64);
        $reference = $this->text($request, 'reference', 16);
        $signature = $this->text($request, 'signature', 128);
        $expires = $request->input('expires');

        if ($eventId === '' || ! Reference::isValid($reference) || $signature === '' || ! is_scalar($expires) || ! ctype_digit((string) $expires)) {
            return ['invalid', 422];
        }

        $verdict = $this->signer->verify($eventId, $reference, (int) $expires, $signature);

        if ($verdict !== FeedbackSigner::VALID) {
            return [$verdict === FeedbackSigner::EXPIRED ? 'expired' : 'invalid', 403];
        }

        if ($this->tooMany($request)) {
            return ['too_many', 429];
        }

        $comment = $request->input('comment');
        $comment = is_string($comment) ? trim($comment) : '';

        if ($comment === '') {
            return ['empty', 422];
        }

        $event = $this->client->factory()->feedback($eventId, $reference, mb_substr($comment, 0, self::MAX_COMMENT));
        $result = $this->dispatcher->deliver([$event]);

        if ($result->delivered > 0 && $result->spooled === 0 && $result->dropped === 0) {
            return ['sent', 200];
        }

        return $result->spooled > 0 ? ['recorded', 200] : ['failed', 500];
    }

    private function reference(Request $request): ?string
    {
        $reference = $request->input('reference');

        return is_string($reference) && Reference::isValid($reference) ? $reference : null;
    }

    private function text(Request $request, string $key, int $max): string
    {
        $value = $request->input($key);

        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /**
     * 5 messages per minute per IP. If the cache is unavailable the limit is skipped (never block feedback).
     */
    private function tooMany(Request $request): bool
    {
        try {
            $store = $this->cache->store();
            $key = 'nightguardian:feedback:'.sha1((string) $request->ip());

            $store->add($key, 0, 60);
            $count = $store->increment($key);

            return is_int($count) && $count > self::LIMIT_PER_MINUTE;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function page(string $status, ?string $reference, int $httpStatus): Response
    {
        return new Response(view('nightguardian::feedback-thanks', [
            'status' => $status,
            'reference' => $reference,
            'home' => url('/'),
        ])->render(), $httpStatus, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
