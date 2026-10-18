<?php

namespace NightGuardian\Laravel\Tests\Smoke;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\ObjectMap;
use NightGuardian\Laravel\Tests\ActiveTestCase;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Buffer;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Transport;
use NightGuardian\Laravel\Transport\TransportResult;
use RuntimeException;

/**
 * A plain PHPUnit suite for what Pest cannot run: Laravel 5.8 and PHP 7.1/7.2 (Pest 1 needs PHP 7.3+ and PHPUnit 9).
 * It covers the paths that matter most: boot, capture, scrubbing, delivery, spooling and the error page.
 * The full Pest suite runs on the Laravel and PHP versions that support it.
 */
class SmokeTest extends ActiveTestCase
{
    public function test_the_provider_boots_and_the_sdk_is_active()
    {
        $this->assertTrue($this->app->make(NightGuardian::class)->isActive());
        $this->assertArrayHasKey('nightguardian:status', Artisan::all());
        $this->assertArrayHasKey('nightguardian:test', Artisan::all());
    }

    public function test_a_logged_exception_becomes_an_exception_event_with_a_reference()
    {
        Log::error('Payment failed', ['exception' => new RuntimeException('Gateway down'), 'order' => 42]);

        $events = $this->captured();

        $this->assertCount(1, $events, 'events: '.json_encode(array_map(function ($event) {
            return [$event['type'], $event['payload']['message'] ?? ($event['payload']['exceptions'][0]['message'] ?? '')];
        }, $events)));
        $this->assertSame('exception', $events[0]['type']);
        $this->assertSame('Gateway down', $events[0]['payload']['exceptions'][0]['message']);
        $this->assertSame(1, preg_match('/^NG-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $events[0]['reference']));
    }

    public function test_sensitive_data_is_scrubbed()
    {
        $event = $this->app->make(EventFactory::class)->exception(
            new RuntimeException('card 4111 1111 1111 1111 with Bearer abc123token'),
            ['context' => ['api_key' => 'k', 'order' => ['password' => 'p', 'id' => 5]]]
        );

        $this->assertSame('card [Filtered] with Bearer [Filtered]', $event['payload']['exceptions'][0]['message']);
        $this->assertSame(['api_key' => '[Filtered]', 'order' => ['password' => '[Filtered]', 'id' => 5]], $event['context']);
    }

    public function test_a_batch_is_delivered_and_not_spooled_when_the_collector_accepts_it()
    {
        $result = $this->dispatcher()->deliver($this->batch(3));

        $this->assertSame(3, $result->delivered);
        $this->assertSame(0, $result->spooled);
        $this->assertCount(1, $this->transport()->batches);
    }

    public function test_a_batch_is_spooled_and_the_breaker_opens_when_the_collector_is_down()
    {
        $this->transport()->script[] = new TransportResult(0, 'curl error 7: Failed to connect');

        $result = $this->dispatcher()->deliver($this->batch(2));

        $this->assertSame(0, $result->delivered);
        $this->assertSame(2, $result->spooled);
        $this->assertSame(1, $this->dispatcher()->spool()->count());
        $this->assertSame('open', $this->dispatcher()->breaker()->status()['state']);

        // While the breaker is open nothing touches the network.
        $this->dispatcher()->deliver($this->batch(1, 'b'));
        $this->assertCount(1, $this->transport()->batches);
    }

    public function test_the_friendly_error_page_shows_the_reference_of_the_captured_error()
    {
        $this->app['config']->set('app.debug', false);
        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $response = $this->get('/boom', ['Accept' => 'text/html']);

        $response->assertStatus(500);
        $reference = $response->headers->get('X-NightGuardian-Reference');
        $this->assertSame(1, preg_match('/^NG-/', (string) $reference));
        $this->assertStringContainsString((string) $reference, $response->getContent());
        $this->assertStringNotContainsString('Kaboom', $response->getContent());
    }

    public function test_the_comment_of_the_error_page_reaches_the_collector_as_a_feedback_event()
    {
        $this->app['config']->set('app.debug', false);
        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $html = $this->get('/boom', ['Accept' => 'text/html'])->getContent();
        $fields = [];

        foreach (['event_id', 'reference', 'expires', 'signature'] as $name) {
            preg_match('/<input type="hidden" name="'.$name.'" value="([^"]*)"/', $html, $match);
            $fields[$name] = isset($match[1]) ? $match[1] : '';
        }

        $response = $this->post('/nightguardian/feedback', $fields + ['comment' => 'I clicked Confirm twice'], ['Accept' => 'text/html']);

        $response->assertOk();
        $this->assertStringContainsString('your message was sent', $response->getContent());

        $feedback = array_values(array_filter($this->transport()->events(), function ($event) {
            return $event['type'] === 'feedback';
        }));

        $this->assertCount(1, $feedback);
        $this->assertSame($fields['event_id'], $feedback[0]['payload']['event_id']);
        $this->assertSame('I clicked Confirm twice', $feedback[0]['payload']['comment']);
    }

    public function test_the_status_and_test_commands_run()
    {
        $this->assertSame(0, Artisan::call('nightguardian:status'));
        $this->assertStringContainsString('collector.test', Artisan::output());

        $this->assertSame(0, Artisan::call('nightguardian:test'));
        $this->assertStringContainsString('accepted the test event', Artisan::output());
    }

    public function test_the_object_map_remembers_objects_without_keeping_them_alive()
    {
        $map = new ObjectMap;
        $object = new \stdClass;

        $this->assertFalse(isset($map[$object]));

        $map[$object] = ['x' => 1];
        $this->assertTrue(isset($map[$object]));
        $this->assertSame(['x' => 1], $map[$object]);
        $this->assertFalse(isset($map[new \stdClass]));

        unset($map[$object]);
        $this->assertFalse(isset($map[$object]));
    }

    public function test_the_object_map_is_bounded()
    {
        $map = new ObjectMap;
        $kept = [];

        for ($i = 0; $i < ObjectMap::LIMIT + 20; $i++) {
            $kept[] = $object = new \stdClass;
            $map[$object] = $i;
        }

        $this->assertSame(ObjectMap::LIMIT + 19, $map[end($kept)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function captured()
    {
        return array_merge($this->transport()->events(), $this->app->make(Buffer::class)->all());
    }

    /**
     * @return Dispatcher
     */
    private function dispatcher()
    {
        return $this->app->make(Dispatcher::class);
    }

    /**
     * @return FakeTransport
     */
    private function transport()
    {
        return $this->app->make(Transport::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function batch($count = 1, $prefix = 'e')
    {
        $events = [];

        for ($i = 1; $i <= $count; $i++) {
            $events[] = ['id' => $prefix.$i, 'type' => 'log', 'level' => 'error', 'payload' => ['message' => 'event '.$i]];
        }

        return $events;
    }
}
