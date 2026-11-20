<?php

namespace NickDeKruijk\Beacon\Tests;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NickDeKruijk\Beacon\Beacon;
use NickDeKruijk\Beacon\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Throwable;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench runs #[DefineEnvironment] before defineEnvironment(), so a test sets these and
     * defineEnvironment() applies them.
     */
    protected string $environment = 'production';

    /**
     * @var array<string, mixed>
     */
    protected array $beaconConfig = [];

    /**
     * What the dashboard answers, when a test wants something else than "ok". Set through
     * dashboardAnswers(): stubs of Http::fake() add up and the first one wins, so a second
     * fake in a test would never be reached.
     *
     * @var (Closure(Request): mixed)|null
     */
    private ?Closure $dashboard = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn (Request $request) => $this->dashboard ? ($this->dashboard)($request) : Http::response(['status' => 'ok']));
    }

    protected function tearDown(): void
    {
        Beacon::sendUsing(null);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = $this->environment;
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://voorbeeld.test');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');

        foreach ($this->beaconConfig as $key => $value) {
            $app['config']->set('beacon.'.$key, $value);
        }
    }

    /**
     * @param  Closure(Request): mixed  $answer
     */
    protected function dashboardAnswers(Closure $answer): void
    {
        $this->dashboard = $answer;
    }

    protected function inLocalEnvironment(): void
    {
        $this->environment = 'local';
    }

    protected function disabled(): void
    {
        $this->beaconConfig['enabled'] = false;
    }

    /**
     * Report an exception as a request would, and end the request: a report waits for that.
     *
     * @return array<string, mixed>
     */
    protected function report(Throwable $exception): array
    {
        report($exception);
        $this->app->terminate();

        return $this->lastReport();
    }

    /**
     * Every report that was posted, in order.
     *
     * @return list<array<string, mixed>>
     */
    protected function reports(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastReport(): array
    {
        $reports = $this->reports();

        $this->assertNotSame([], $reports, 'Nothing was reported');

        return $reports[array_key_last($reports)];
    }

    protected function lastRequest(): Request
    {
        return Http::recorded()->last()[0];
    }
}
