<?php

namespace NightGuardian\Laravel\Capture;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Wraps the application's exception handler; see DecoratesExceptionHandler for what it does.
 *
 * Parameters are untyped so that the same class fits the handler contract of every supported Laravel version
 * (Throwable from Laravel 7, Exception before). PHP 7.1 cannot widen parameter types, so PHP 7.1 (Laravel 5.8) uses
 * LegacyExceptionHandlerDecorator.
 */
class ExceptionHandlerDecorator implements ExceptionHandler
{
    use DecoratesExceptionHandler;

    public const HEADER = 'X-NightGuardian-Reference';

    public function report($e): void
    {
        $this->handler->report($e);
    }

    public function shouldReport($e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, $e)
    {
        return $this->renderWith($request, $e);
    }

    public function renderForConsole($output, $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }
}
