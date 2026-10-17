<?php

namespace NightGuardian\Laravel\Event;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Identifies the current user without ever touching the database.
 *
 * Without a `user_resolver` only a user the auth guard has ALREADY loaded is used (`hasUser()`), so
 * capturing an error can never trigger a query. Only the id is sent unless `send_user_email` is on,
 * which also allows `email` and `name`.
 */
class UserContext
{
    /**
     * @param  (Closure(): mixed)|null  $guardUser  Returns the already-loaded authenticated user, if any.
     * @param  (callable(): mixed)|null  $resolver  Config `user_resolver`: returns ['id' => ..., 'email' => ..., 'name' => ...].
     */
    public function __construct(
        private ?Closure $guardUser,
        private mixed $resolver,
        private bool $sendEmail = false,
    ) {}

    /**
     * @return array{id: string, email?: string, name?: string}|null
     */
    public function resolve(): ?array
    {
        try {
            $raw = is_callable($this->resolver) ? ($this->resolver)() : $this->fromGuard();

            return $this->normalize($raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function fromGuard(): mixed
    {
        return $this->guardUser === null ? null : ($this->guardUser)();
    }

    /**
     * @return array{id: string, email?: string, name?: string}|null
     */
    private function normalize(mixed $raw): ?array
    {
        if ($raw instanceof Authenticatable) {
            $raw = [
                'id' => $raw->getAuthIdentifier(),
                'email' => $raw->email ?? null,
                'name' => $raw->name ?? null,
            ];
        }

        if (! is_array($raw) || ! isset($raw['id']) || ! (is_string($raw['id']) || is_int($raw['id']))) {
            return null;
        }

        $user = ['id' => mb_substr((string) $raw['id'], 0, 255)];

        if ($this->sendEmail) {
            foreach (['email', 'name'] as $field) {
                if (isset($raw[$field]) && is_string($raw[$field]) && $raw[$field] !== '') {
                    $user[$field] = mb_substr($raw[$field], 0, 255);
                }
            }
        }

        return $user;
    }
}
