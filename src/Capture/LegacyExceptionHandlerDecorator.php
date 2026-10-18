<?php

namespace NightGuardian\Laravel\Capture;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * The decorator for PHP 7.1, where a parameter type cannot be widened: the handler contract of Laravel 5.8 and 6
 * declares `Exception $e`, so this class does too.
 */
class LegacyExceptionHandlerDecorator implements ExceptionHandler
{
    use DecoratesExceptionHandler;

    public const HEADER = 'X-NightGuardian-Reference';

    public function report(Exception $e): void
    {
        $this->handler->report($e);
    }

    public function shouldReport(Exception $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Exception $e)
    {
        return $this->renderWith($request, $e);
    }

    public function renderForConsole($output, Exception $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }
}
