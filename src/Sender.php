<?php

namespace NickDeKruijk\Beacon;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts a report to the dashboard. Nothing here may ever throw: this runs while the site is
 * already dealing with an exception, and a dashboard that is down must not become the next one.
 *
 * During a request the report waits until the response is out, so a visitor never waits for the
 * dashboard. A command or a queued job has nobody waiting, and sends at once.
 */
class Sender
{
    private const SILENCED = 'beacon:silenced';

    /**
     * How long nothing is sent after the dashboard refused the sender, in seconds, and the
     * longest it stays silent after being told it sends too much.
     */
    private const SILENCE = 3600;

    /**
     * The reports that wait for the end of the request.
     *
     * @var list<array<string, mixed>>
     */
    private array $waiting = [];

    private bool $shutdownRegistered = false;

    public function __construct(private Application $app) {}

    /**
     * @param  array<string, mixed>  $report
     */
    public function send(array $report, bool $afterResponse): void
    {
        if (! $afterResponse) {
            $this->post($report);

            return;
        }

        $this->waiting[] = $report;

        // Again for every first report: under Octane each request has its own application.
        if (count($this->waiting) === 1) {
            $this->app->terminating(fn () => $this->flush());
        }

        // A fatal error ends the script without terminating the application.
        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;

            register_shutdown_function(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        $reports = $this->waiting;
        $this->waiting = [];

        foreach ($reports as $report) {
            $this->post($report);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function post(array $report): void
    {
        try {
            $url = (string) $this->config('url');

            if ($url === '' || $this->silenced() || $this->overLimit(($report['type'] ?? null) === 'query')) {
                return;
            }

            $timeout = max(1, (int) $this->config('timeout', 2));
            $token = $this->config('token');

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->when(is_string($token) && $token !== '', fn ($request) => $request->withToken($token))
                ->post($url, $report);

            // Not let in: the dashboard has said what it thinks, so stop asking for an hour.
            if ($response->status() === 403) {
                $this->silence(self::SILENCE);
            }

            // Too many: wait as long as it asks. Its limit may be shared with other sites on
            // the same server, so this is no reason to stay away for an hour.
            if ($response->status() === 429) {
                $this->silence(min(self::SILENCE, max(60, (int) $response->header('Retry-After'))));
            }
        } catch (Throwable) {
            // The exception itself is in the log of the site. This one helps nobody.
        }
    }

    private function silenced(): bool
    {
        try {
            return (bool) Cache::get(self::SILENCED, false);
        } catch (Throwable) {
            return false;
        }
    }

    private function silence(int $seconds): void
    {
        try {
            Cache::put(self::SILENCED, true, $seconds);
        } catch (Throwable) {
            // Without a cache there is nothing to remember it in.
        }
    }

    /**
     * Whether this minute has had its number of reports. Queries have a number of their own, so
     * a page full of them never uses up what the exceptions may send. A cache that is broken
     * counts as no for an exception, as it may well be what the report is about, and as yes for
     * a query, which can wait.
     */
    private function overLimit(bool $query): bool
    {
        try {
            $key = 'beacon:sent:'.($query ? 'queries:' : '').intdiv(time(), 60);

            Cache::add($key, 0, 120);

            return Cache::increment($key) > (int) $this->config($query ? 'queries.per_minute' : 'per_minute', $query ? 5 : 20);
        } catch (Throwable) {
            return $query;
        }
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('beacon.'.$key, $default);
    }
}
