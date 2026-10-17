<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;

it('registers the capture hooks when the SDK is configured', function () {
    $events = app('events');

    expect($events->hasListeners(MessageLogged::class))->toBeTrue()
        ->and($events->hasListeners(JobFailed::class))->toBeTrue()
        ->and($events->hasListeners(QueryExecuted::class))->toBeTrue();
});
