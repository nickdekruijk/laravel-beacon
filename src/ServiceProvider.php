<?php

namespace NickDeKruijk\Beacon;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Throwable;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beacon.php', 'beacon');

        $this->app->singleton(Context::class);
        $this->app->singleton(Sender::class);
        $this->app->singleton(Collector::class);
        $this->app->singleton(CspReports::class);
        $this->app->singleton(Beacon::class);

        $this->app->singleton(Scrubber::class, fn () => new Scrubber(
            array_values(array_filter(explode(',', (string) config('beacon.request.filtered_fields', '')))),
        ));

        $this->app->resolving(ExceptionHandler::class, function (object $handler): void {
            $this->configureExceptionHandler($handler);
        });

        if ($this->app->resolved(ExceptionHandler::class)) {
            $this->configureExceptionHandler($this->app->make(ExceptionHandler::class));
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/beacon.php' => config_path('beacon.php'),
        ], 'beacon-config');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
        }

        // Also where nothing is reported, so a policy that names it never gets a 404. The
        // controller sends nothing there.
        $this->loadRoutesFrom(__DIR__.'/../routes/csp.php');

        if ($this->reports()) {
            $this->followContext($this->app->make('events'));

            if (filter_var(config('beacon.queries.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
                $this->watchQueries($this->app->make('events'));
            }
        }
    }

    /**
     * A reportable() callback sees what Laravel decided to report: nothing from dontReport(),
     * no 404, no validation error, and nothing another package filtered out before. It returns
     * nothing, so the exception is logged as before and other reporters get it too. Never chain
     * ->stop() on it, that stops the reporting of everything.
     */
    private function configureExceptionHandler(object $handler): void
    {
        if (! $this->app->bound('config') || ! $this->reports() || ! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $exception): void {
            $this->app->make(Beacon::class)->report($exception);
        });
    }

    /**
     * Keep track of the command or the queued job that is running, for the context of a report.
     * The job is forgotten when it is done and before the worker takes the next, not when it
     * fails: the worker reports the exception after it has said the job failed.
     */
    private function followContext(Dispatcher $events): void
    {
        $context = $this->app->make(Context::class);

        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $context->commandStarting($event->command));
        $events->listen(CommandFinished::class, fn () => $context->commandFinished());
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $context->jobStarting($event->job->resolveName()));
        $events->listen(JobProcessed::class, fn () => $context->jobDone());
        $events->listen(Looping::class, fn () => $context->jobDone());
    }

    /**
     * Count the queries of each request and each queued job. A request ends with the response
     * (under Octane the next one starts in the same process), a job when it is done or failed.
     * Registered after followContext(), so at the end of a job the context has already let go
     * of it: a report is therefore made while the job runs and only sent here.
     */
    private function watchQueries(Dispatcher $events): void
    {
        $this->app->singleton(QueryWatcher::class);

        $events->listen(QueryExecuted::class, fn (QueryExecuted $query) => $this->app->make(QueryWatcher::class)->executed($query));
        $events->listen(
            [RequestHandled::class, JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class],
            fn () => $this->app->make(QueryWatcher::class)->finish(),
        );
    }

    private function reports(): bool
    {
        return Beacon::isActive();
    }
}
