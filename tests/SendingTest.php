<?php

namespace NickDeKruijk\Beacon\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery;
use NickDeKruijk\Beacon\Beacon;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class SendingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('reports-and-answers', function () {
            report(new RuntimeException('Handled, but worth knowing'));

            return 'sent so far: '.Http::recorded()->count();
        });
    }

    public function test_it_posts_to_the_dashboard_of_amphora_by_default_without_a_token(): void
    {
        $this->report(new RuntimeException('Boom'));

        $request = $this->lastRequest();

        $this->assertSame('https://mijn.amphora.nl/api/v1/beacon/errors', $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->isJson());
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function test_it_posts_to_your_own_dashboard_with_your_token(): void
    {
        config(['beacon.url' => 'https://dashboard.voorbeeld.test/beacon', 'beacon.token' => 'a-dashboard-token']);

        $this->report(new RuntimeException('Boom'));

        $this->assertSame('https://dashboard.voorbeeld.test/beacon', $this->lastRequest()->url());
        $this->assertTrue($this->lastRequest()->hasHeader('Authorization', 'Bearer a-dashboard-token'));
    }

    public function test_every_setting_reads_its_env_variable(): void
    {
        $variables = [
            'BEACON_ENABLED' => 'false',
            'BEACON_URL' => 'https://dashboard.voorbeeld.test/beacon',
            'BEACON_TOKEN' => 'a-dashboard-token',
            'BEACON_REQUEST_QUERY' => 'false',
            'BEACON_REQUEST_BODY' => 'false',
            'BEACON_REQUEST_HEADERS' => 'false',
            'BEACON_REQUEST_IP' => 'none',
            'BEACON_FILTERED_FIELDS' => 'bsn,geboortedatum',
        ];

        foreach ($variables as $name => $value) {
            putenv($name.'='.$value);
        }

        try {
            $config = require __DIR__.'/../config/beacon.php';
        } finally {
            foreach (array_keys($variables) as $name) {
                putenv($name);
            }
        }

        $this->assertFalse($config['enabled']);
        $this->assertSame('https://dashboard.voorbeeld.test/beacon', $config['url']);
        $this->assertSame('a-dashboard-token', $config['token']);
        $this->assertSame(['query' => false, 'body' => false, 'headers' => false, 'ip' => 'none', 'filtered_fields' => 'bsn,geboortedatum'], $config['request']);
    }

    #[DefineEnvironment('inLocalEnvironment')]
    public function test_nothing_is_reported_outside_production(): void
    {
        report(new RuntimeException('Boom'));
        $this->app->terminate();

        Http::assertNothingSent();
    }

    #[DefineEnvironment('disabled')]
    public function test_nothing_is_reported_when_it_is_switched_off(): void
    {
        report(new RuntimeException('Boom'));
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_what_laravel_does_not_report_is_not_sent(): void
    {
        report(new NotFoundHttpException);
        report(ValidationException::withMessages(['email' => 'Required']));
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_during_a_request_the_report_waits_until_the_response_is_out(): void
    {
        $this->get('reports-and-answers')->assertOk()->assertSee('sent so far: 0');

        $this->assertCount(1, $this->reports());
        $this->assertSame('Handled, but worth knowing', $this->lastReport()['exception']['message']);
    }

    public function test_a_command_sends_at_once_and_is_reported_by_its_name_only(): void
    {
        Artisan::command('orders:export {--password=}', function () {
            report(new RuntimeException('Boom'));
            $this->line('sent so far: '.Http::recorded()->count());
        });

        $this->artisan('orders:export', ['--password' => 'hunter2'])->expectsOutput('sent so far: 1');

        $report = $this->lastReport();

        $this->assertSame('console', $report['context']['type']);
        $this->assertSame('orders:export', $report['context']['command']);
        $this->assertNull($report['context']['url']);
        $this->assertNull($report['request']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($report));
    }

    public function test_a_command_that_ended_is_no_longer_the_context(): void
    {
        Artisan::command('orders:export', fn () => null);
        $this->artisan('orders:export');

        $this->assertSame('http', $this->report(new RuntimeException('Boom'))['context']['type']);
    }

    public function test_a_queued_job_sends_at_once_and_is_reported_by_its_name(): void
    {
        $job = Mockery::mock(Job::class, ['resolveName' => 'App\\Jobs\\ExportOrders', 'payload' => []]);

        event(new JobProcessing('redis', $job));
        report(new RuntimeException('Boom'));

        $report = $this->lastReport();

        $this->assertSame('queue', $report['context']['type']);
        $this->assertSame('App\\Jobs\\ExportOrders', $report['context']['job']);
        $this->assertNull($report['request']);
    }

    #[DataProvider('jobEndings')]
    public function test_a_job_that_is_over_is_no_longer_the_context(string $ending): void
    {
        $job = Mockery::mock(Job::class, ['resolveName' => 'App\\Jobs\\ExportOrders', 'payload' => []]);
        event(new JobProcessing('redis', $job));

        event($ending === JobProcessed::class ? new JobProcessed('redis', $job) : new Looping('redis', 'default'));

        $this->assertNull($this->report(new RuntimeException('Boom'))['context']['job']);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function jobEndings(): array
    {
        return [
            'it was processed' => [JobProcessed::class],
            'the worker takes the next' => [Looping::class],
        ];
    }

    public function test_a_dashboard_that_cannot_be_reached_breaks_nothing(): void
    {
        $this->dashboardAnswers(fn () => throw new ConnectionException('Connection timed out'));
        $logged = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$logged): void {
            $logged[] = $exception->getMessage();
        });

        $this->get('reports-and-answers')->assertOk();

        $this->assertSame(['Handled, but worth knowing'], $logged, 'The exception no longer reaches whatever reports after Beacon');
    }

    public function test_a_site_reports_at_most_its_number_per_minute(): void
    {
        config(['beacon.per_minute' => 3]);

        foreach (range(1, 5) as $number) {
            $this->report(new RuntimeException('Fault '.$number));
        }

        $this->assertSame(['Fault 1', 'Fault 2', 'Fault 3'], array_map(fn (array $report) => $report['exception']['message'], $this->reports()));
    }

    public function test_it_stays_silent_for_an_hour_after_the_dashboard_refuses_the_sender(): void
    {
        $this->dashboardAnswers(fn () => Http::response(['message' => 'Forbidden.'], 403));

        $this->report(new RuntimeException('First'));
        $this->report(new RuntimeException('Second'));
        $this->travel(59)->minutes();
        $this->report(new RuntimeException('Almost an hour later'));
        $this->assertCount(1, $this->reports());

        $this->travel(2)->minutes();
        $this->report(new RuntimeException('An hour later'));

        $this->assertCount(2, $this->reports());
    }

    #[DataProvider('retryAfters')]
    public function test_it_stays_silent_as_long_as_the_dashboard_asks_after_too_many_reports(?string $retryAfter, int $silentSeconds): void
    {
        $this->dashboardAnswers(fn () => Http::response(['message' => 'Too Many Attempts.'], 429, array_filter(['Retry-After' => $retryAfter])));

        $this->report(new RuntimeException('First'));
        $this->travel($silentSeconds - 1)->seconds();
        $this->report(new RuntimeException('Still silent'));
        $this->assertCount(1, $this->reports());

        $this->travel(2)->seconds();
        $this->report(new RuntimeException('Asking again'));

        $this->assertCount(2, $this->reports());
    }

    /**
     * @return array<string, array{?string, int}>
     */
    public static function retryAfters(): array
    {
        return [
            'the time it asks for' => ['300', 300],
            'at least a minute' => ['5', 60],
            'a minute when it does not say' => [null, 60],
            'at most an hour' => ['86400', 3600],
        ];
    }

    public function test_another_error_of_the_dashboard_does_not_silence_it(): void
    {
        $this->dashboardAnswers(fn () => Http::response('Server Error', 500));

        $this->report(new RuntimeException('First'));
        $this->report(new RuntimeException('Second'));

        $this->assertCount(2, $this->reports());
    }

    public function test_a_cache_that_is_broken_does_not_stop_a_report(): void
    {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis is gone'));
        Cache::shouldReceive('add')->andThrow(new RuntimeException('Redis is gone'));

        $this->report(new RuntimeException('Redis is gone'));

        $this->assertCount(1, $this->reports());
    }

    public function test_a_transport_of_your_own_gets_the_report_and_nothing_is_posted(): void
    {
        $received = [];
        Beacon::sendUsing(function (array $report) use (&$received): void {
            $received[] = $report;
        });

        report(new RuntimeException('Boom'));

        $this->assertCount(1, $received);
        $this->assertSame('Boom', $received[0]['exception']['message']);
        $this->assertSame(1, $received[0]['v']);

        $this->app->terminate();
        Http::assertNothingSent();
    }

    public function test_a_transport_that_fails_or_reports_again_does_not_loop(): void
    {
        $calls = 0;
        Beacon::sendUsing(function () use (&$calls): void {
            $calls++;
            report(new RuntimeException('The dashboard is broken too'));

            throw new RuntimeException('And it throws');
        });

        report(new RuntimeException('Boom'));

        $this->assertSame(1, $calls);
    }
}
