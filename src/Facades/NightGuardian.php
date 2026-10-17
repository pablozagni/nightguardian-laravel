<?php

namespace NightGuardian\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use NightGuardian\Laravel\NightGuardian as Client;

/**
 * @method static bool isActive()
 * @method static string|null inactiveReason()
 * @method static string|null ingestUrl()
 * @method static int internalErrors()
 *
 * @see Client
 */
class NightGuardian extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
