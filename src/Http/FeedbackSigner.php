<?php

namespace NightGuardian\Laravel\Http;

use NightGuardian\Laravel\Support\Clock;

/**
 * Signs the hidden fields of the feedback form: HMAC-SHA256(app key, event_id|reference|expires).
 * The feedback route has no session and no CSRF token (the session may be what is broken), so this
 * signature is what proves the form was issued by this application, recently.
 */
class FeedbackSigner
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var Clock
     */
    private $clock;

    public const TTL_SECONDS = 900;

    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    public function __construct(string $key, Clock $clock)
    {
        $this->key = $key;
        $this->clock = $clock;
    }

    public function isAvailable(): bool
    {
        return $this->key !== '';
    }

    /**
     * @param  string  $eventId
     * @param  string  $reference
     * @param  int|null  $expires
     * @return array{expires: int, signature: string}
     */
    public function sign($eventId, $reference, $expires = null): array
    {
        $expires = $expires ?? (int) $this->clock->now() + self::TTL_SECONDS;

        return ['expires' => $expires, 'signature' => $this->hash($eventId, $reference, $expires)];
    }

    /**
     * @param  string  $eventId
     * @param  string  $reference
     * @param  int  $expires
     * @param  string  $signature
     * @return self::VALID|self::EXPIRED|self::INVALID
     */
    public function verify($eventId, $reference, $expires, $signature): string
    {
        if (! $this->isAvailable() || ! hash_equals($this->hash($eventId, $reference, $expires), $signature)) {
            return self::INVALID;
        }

        return $this->clock->now() > $expires ? self::EXPIRED : self::VALID;
    }

    private function hash(string $eventId, string $reference, int $expires): string
    {
        return hash_hmac('sha256', $eventId.'|'.$reference.'|'.$expires, $this->key);
    }
}
