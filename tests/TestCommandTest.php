<?php

namespace NickDeKruijk\Beacon\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use NickDeKruijk\Beacon\Beacon;
use NickDeKruijk\Beacon\TestException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

class TestCommandTest extends TestCase
{
    public function test_it_sends_a_test_exception_and_says_it_arrived(): void
    {
        $this->artisan('beacon:test')
            ->expectsOutputToContain('https://mijn.amphora.nl/api/v1/beacon/errors')
            ->expectsOutputToContain('The dashboard took the test exception (200).')
            ->assertSuccessful();

        $report = $this->lastReport();
        $this->assertSame(TestException::class, $report['exception']['class']);
        $this->assertSame('console', $report['context']['type']);
        $this->assertSame('beacon:test', $report['context']['command']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function answers(): array
    {
        return [
            'unknown sender' => [403, 'does not know this sender'],
            'token wanted' => [401, 'Set BEACON_TOKEN'],
            'refused' => [422, 'refused the report'],
            'broken' => [500, 'answered 500'],
        ];
    }

    #[DataProvider('answers')]
    public function test_it_says_what_the_dashboard_answered(int $status, string $said): void
    {
        $this->dashboardAnswers(fn () => Http::response(['message' => 'No.'], $status));

        $this->artisan('beacon:test')->expectsOutputToContain($said)->assertFailed();
    }

    public function test_it_says_how_long_to_wait_after_too_many(): void
    {
        $this->dashboardAnswers(fn () => Http::response('', 429, ['Retry-After' => '120']));

        $this->artisan('beacon:test')->expectsOutputToContain('Try again in 120 seconds.')->assertFailed();
    }

    public function test_it_says_when_the_dashboard_cannot_be_reached(): void
    {
        $this->dashboardAnswers(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->artisan('beacon:test')->expectsOutputToContain('could not be reached: cURL error 7')->assertFailed();
    }

    public function test_a_test_is_sent_when_reports_would_be_dropped(): void
    {
        config(['beacon.per_minute' => 0]);
        $this->dashboardAnswers(fn () => Http::response('', 403));
        $this->artisan('beacon:test');

        $this->artisan('beacon:test')->assertFailed();

        $this->assertCount(2, $this->reports());
    }

    #[DefineEnvironment('inLocalEnvironment')]
    public function test_it_sends_outside_production_and_says_beacon_does_not_report_there(): void
    {
        $this->artisan('beacon:test')
            ->expectsOutputToContain('took the test exception')
            ->expectsOutputToContain('Beacon does not report here')
            ->assertSuccessful();
    }

    #[DefineEnvironment('disabled')]
    public function test_it_sends_when_beacon_is_switched_off(): void
    {
        $this->artisan('beacon:test')->expectsOutputToContain('Beacon does not report here')->assertSuccessful();

        $this->assertCount(1, $this->reports());
    }

    public function test_an_empty_url_says_so(): void
    {
        config(['beacon.url' => '']);

        $this->artisan('beacon:test')->expectsOutputToContain('BEACON_URL is empty')->assertFailed();

        $this->assertSame([], $this->reports());
    }

    public function test_an_application_with_its_own_transport_gets_the_test_and_nothing_is_posted(): void
    {
        $taken = [];
        Beacon::sendUsing(function (array $report) use (&$taken): void {
            $taken[] = $report['exception']['class'];
        });

        $this->artisan('beacon:test')->expectsOutputToContain('Nothing was posted')->assertSuccessful();

        $this->assertSame([TestException::class], $taken);
        $this->assertSame([], $this->reports());
    }

    public function test_a_transport_that_fails_says_so(): void
    {
        Beacon::sendUsing(fn () => throw new \RuntimeException('Database is down'));

        $this->artisan('beacon:test')->expectsOutputToContain('transport of this application failed: Database is down')->assertFailed();
    }
}
