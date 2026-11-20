<?php

namespace NickDeKruijk\Beacon;

/**
 * What the application is doing right now: handling a request, running a command or working a
 * queued job. Kept up to date by the events the service provider listens to.
 *
 * Not `runningInConsole()`: that is also true for a job on the queue, and says nothing about
 * which command. A command started from inside a request (`Artisan::call()`) counts as a
 * command only while it runs.
 */
class Context
{
    public const HTTP = 'http';

    public const CONSOLE = 'console';

    public const QUEUE = 'queue';

    /**
     * The commands that are running, the innermost last.
     *
     * @var list<string>
     */
    private array $commands = [];

    private ?string $job = null;

    public function commandStarting(?string $name): void
    {
        $this->commands[] = $name ?: 'artisan';
    }

    public function commandFinished(): void
    {
        array_pop($this->commands);
    }

    public function jobStarting(string $name): void
    {
        $this->job = $name;
    }

    public function jobDone(): void
    {
        $this->job = null;
    }

    public function type(): string
    {
        return match (true) {
            $this->job !== null => self::QUEUE,
            $this->commands !== [] => self::CONSOLE,
            default => self::HTTP,
        };
    }

    /**
     * The name of the command only, never its arguments: those can hold a password.
     */
    public function command(): ?string
    {
        return $this->commands === [] ? null : $this->commands[array_key_last($this->commands)];
    }

    public function job(): ?string
    {
        return $this->job;
    }
}
