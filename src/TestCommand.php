<?php

namespace NickDeKruijk\Beacon;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends one test exception, to see that the dashboard takes the reports of this site. Runs in
 * every environment and also when Beacon is switched off: it is what you run before switching
 * it on. It does not wait for the end of anything and ignores the limits, and it says what the
 * dashboard answered.
 */
class TestCommand extends Command
{
    protected $signature = 'beacon:test';

    protected $description = 'Send a test exception to the Beacon dashboard and show what it answered';

    public function handle(Collector $collector, Sender $sender, Beacon $beacon): int
    {
        $report = $collector->collect(new TestException('This is a test exception sent by `php artisan beacon:test`.'));

        if (Beacon::hasTransport()) {
            try {
                $beacon->deliver($report, afterResponse: false);
            } catch (Throwable $exception) {
                $this->components->error('The transport of this application failed: '.$exception->getMessage());

                return self::FAILURE;
            }

            $this->components->info('Handed to the transport set with Beacon::sendUsing(). Nothing was posted.');

            return self::SUCCESS;
        }

        $url = (string) config('beacon.url');

        if ($url === '') {
            $this->components->error('BEACON_URL is empty: there is nowhere to send to.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Dashboard', $url);
        $this->components->twoColumnDetail('Site', (string) $report['site']['path']);

        try {
            $response = $sender->sendNow($report);
        } catch (ConnectionException $exception) {
            $this->components->error('The dashboard could not be reached: '.$exception->getMessage());

            return self::FAILURE;
        }

        $status = $response->status();

        match (true) {
            $response->successful() => $this->components->info("The dashboard took the test exception ({$status})."),
            $status === 403 => $this->components->error('The dashboard does not know this sender (403). On a dashboard that knows its senders by their address, allow the address of this server.'),
            $status === 401 => $this->components->error('The dashboard wants a token (401). Set BEACON_TOKEN.'),
            $status === 429 => $this->components->warn('The dashboard says there were too many reports (429). Try again in '.max(1, (int) $response->header('Retry-After')).' seconds.'),
            $status === 422 => $this->components->error('The dashboard refused the report (422): '.$response->body()),
            default => $this->components->error("The dashboard answered {$status}."),
        };

        if (! config('beacon.enabled', true) || ! app()->environment((array) config('beacon.environments', ['production']))) {
            $this->components->warn('Beacon does not report here: it is switched off, or this environment is not one of beacon.environments.');
        }

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
