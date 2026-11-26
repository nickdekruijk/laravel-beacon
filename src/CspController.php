<?php

namespace NickDeKruijk\Beacon;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Where the browsers of visitors post their Content-Security-Policy reports, on the site itself.
 * Each report is checked, scrubbed and counted here, and sent on from this server to the
 * dashboard after the response, as an exception is: the dashboard knows the server, not the
 * browser.
 *
 * Always a 204 and nothing else, also for a report that is refused: a browser does nothing with
 * the answer, and someone who posts nonsense learns nothing from it.
 */
class CspController
{
    /**
     * The largest body that is read, in bytes. A browser sends one report, or a few in a list.
     */
    private const BODY_LIMIT = 32768;

    /**
     * Reports from one address per minute. A page with a broken embed reports on every visit,
     * one visitor does not need more.
     */
    private const PER_ADDRESS_PER_MINUTE = 30;

    public function __construct(
        private CspReports $reports,
        private Beacon $beacon,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            if (Beacon::isActive() && (bool) config('beacon.csp.enabled', true) && $this->allowed($request)) {
                foreach ($this->reports->fromRequest($request) as $report) {
                    $this->beacon->deliver($report, afterResponse: true);
                }
            }
        } catch (Throwable) {
            // A report about a policy is never worth an error of the site.
        }

        return response()->noContent();
    }

    private function allowed(Request $request): bool
    {
        if ((int) $request->header('Content-Length', '0') > self::BODY_LIMIT || strlen($request->getContent()) > self::BODY_LIMIT) {
            return false;
        }

        $key = 'beacon:csp:address:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::PER_ADDRESS_PER_MINUTE)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
