<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;

class NgFailingJob
{
    public function handle(): void
    {
        throw new RuntimeException('Job blew up');
    }
}

// --- queue ----------------------------------------------------------------------------------------------

it('captures a failed job as job_failed with its queue details', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendInvoice');
    $job->shouldReceive('getQueue')->andReturn('emails');
    $job->shouldReceive('attempts')->andReturn(3);

    event(new JobFailed('database', $job, new RuntimeException('SMTP down')));

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('job_failed')
        ->and($events[0]['payload'])->toMatchArray(['job' => 'App\\Jobs\\SendInvoice', 'queue' => 'emails', 'connection' => 'database', 'attempts' => 3])
        ->and($events[0]['payload']['exceptions'][0]['message'])->toBe('SMTP down')
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

it('captures a really failing job once, even though the worker also reports the exception', function () {
    $queue = new SyncQueue;
    $queue->setContainer(app());
    $queue->setConnectionName('sync');

    try {
        $queue->push(NgFailingJob::class.'@handle');
    } catch (RuntimeException $e) {
        // The worker reports the exception to the handler after the job failed: same instance.
        Log::error($e->getMessage(), ['exception' => $e]);
    }

    expect(ngCaptured('job_failed'))->toHaveCount(1)
        ->and(ngCaptured('exception'))->toBe([]);
});

it('survives job objects with missing methods', function () {
    $job = Mockery::mock(Job::class);

    event(new JobFailed('redis', $job, new RuntimeException('x')));

    expect(ngCaptured())->toHaveCount(1);
});

// --- schedule -------------------------------------------------------------------------------------------

function ngTask(): Event
{
    return app(Schedule::class)->exec('php artisan invoices:sync')->cron('*/5 * * * *');
}

it('captures a scheduled task that throws', function () {
    $task = ngTask();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('Sync crashed')));

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('schedule_failed')
        ->and($events[0]['payload']['command'])->toContain('invoices:sync')
        ->and($events[0]['payload']['expression'])->toBe('*/5 * * * *')
        ->and($events[0]['payload']['exceptions'][0]['message'])->toBe('Sync crashed')
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

it('captures a scheduled task that finishes with a non-zero exit code', function () {
    $task = ngTask();
    $task->exitCode = 2;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.5));

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['exit_code'])->toBe(2)
        ->and($events[0]['payload']['exceptions'])->toBe([]);
});

it('ignores scheduled tasks that finish successfully', function () {
    $task = ngTask();
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.2));

    expect(ngCaptured())->toBe([]);
});

it('reports a scheduled task once per run, but again on the next run', function () {
    $task = ngTask();
    $task->exitCode = 1;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('first')));
    event(new ScheduledTaskFinished($task, 1.0));

    expect(ngCaptured())->toHaveCount(1);

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.0));

    expect(ngCaptured())->toHaveCount(2);
});

it('includes the tail of the task output when the task writes to a file', function () {
    $file = tempnam(sys_get_temp_dir(), 'ngout');
    file_put_contents($file, str_repeat('x', 5000).'THE LAST LINE');

    $task = ngTask();
    $task->sendOutputTo($file);
    $task->exitCode = 1;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.0));

    $tail = ngCaptured()[0]['payload']['output_tail'];
    unlink($file);

    expect(strlen($tail))->toBe(2048)->and($tail)->toEndWith('THE LAST LINE');
});

// --- the exception Laravel reports when a scheduled task failed -----------------------------------------------

it('turns a failed scheduled task into one schedule_failed event, not two events', function () {
    $task = ngTask();
    $task->exitCode = 1;

    // What Laravel's schedule:run does when the command exits with a non-zero code:
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.4));

    $laravelException = new Exception('Scheduled command [php artisan demo:fail] failed with exit code [1].');
    event(new ScheduledTaskFailed($task, $laravelException));
    // ...and then the exception handler reports that exception to the log:
    Log::error($laravelException->getMessage(), ['exception' => $laravelException]);

    $types = array_column(ngCaptured(), 'type');

    expect($types)->toBe(['schedule_failed']);
});

it('reports a scheduled task that throws as one schedule_failed event with its exception', function () {
    $task = ngTask();

    $exception = new RuntimeException('Boom inside the task');
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, $exception));
    Log::error($exception->getMessage(), ['exception' => $exception]);

    expect(array_column(ngCaptured(), 'type'))->toBe(['schedule_failed']);
});
