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
    public const TTL_SECONDS = 900;

    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    public function __construct(private string $key, private Clock $clock) {}

    public function isAvailable(): bool
    {
        return $this->key !== '';
    }

    /**
     * @return array{expires: int, signature: string}
     */
    public function sign(string $eventId, string $reference, ?int $expires = null): array
    {
        $expires ??= (int) $this->clock->now() + self::TTL_SECONDS;

        return ['expires' => $expires, 'signature' => $this->hash($eventId, $reference, $expires)];
    }

    /**
     * @return self::VALID|self::EXPIRED|self::INVALID
     */
    public function verify(string $eventId, string $reference, int $expires, string $signature): string
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
