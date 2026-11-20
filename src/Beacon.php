<?php

namespace NickDeKruijk\Beacon;

use Closure;
use Throwable;

/**
 * Reports an exception: collects it and hands it to the sender, or to a transport of your own.
 * The service provider calls this from Laravel's exception handler, so there is nothing to call
 * yourself.
 */
class Beacon
{
    /**
     * @var (Closure(array<string, mixed>): void)|null
     */
    private static ?Closure $transport = null;

    private bool $reporting = false;

    public function __construct(
        private Collector $collector,
        private Sender $sender,
        private Context $context,
    ) {}

    /**
     * Take the reports yourself instead of having them posted, for an application that is its
     * own dashboard and must not make a request to itself to say it is broken. The callable
     * gets the report of SPEC.md as an array, at the moment of the exception. Pass null to go
     * back to posting.
     *
     * @param  (callable(array<string, mixed>): void)|null  $transport
     */
    public static function sendUsing(?callable $transport): void
    {
        self::$transport = $transport === null ? null : $transport(...);
    }

    /**
     * Never throws, and never reports what goes wrong while it reports: an exception in here
     * would otherwise report itself until the stack is full.
     */
    public function report(Throwable $exception): void
    {
        if ($this->reporting) {
            return;
        }

        $this->reporting = true;

        try {
            $this->deliver($this->collector->collect($exception), afterResponse: $this->context->type() === Context::HTTP);
        } catch (Throwable) {
            // The exception that was being reported is in the log of the site either way.
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * Whether the application takes its reports itself.
     */
    public static function hasTransport(): bool
    {
        return self::$transport !== null;
    }

    /**
     * Hand a report to the transport of the application, or else to the sender.
     *
     * @param  array<string, mixed>  $report
     */
    public function deliver(array $report, bool $afterResponse): void
    {
        if (self::$transport) {
            (self::$transport)($report);
        } else {
            $this->sender->send($report, $afterResponse);
        }
    }
}
