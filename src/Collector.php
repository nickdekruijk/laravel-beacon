<?php

namespace NickDeKruijk\Beacon;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Turns an exception into the report of SPEC.md: where the site is, what was thrown and from
 * where, what the application was doing, and the request it happened in. And a query that
 * stood out into its own, smaller report.
 *
 * It collects at the moment of the exception, while the request is still what it was. Sending
 * comes later. Arguments of the calls in the stack trace are never read: they hold whatever the
 * application had in its hands.
 *
 * @phpstan-type Source array{start: int, lines: list<string>}
 * @phpstan-type Frame array{file: ?string, line: ?int, class: ?string, function: ?string, in_app: bool, source: ?Source}
 */
class Collector
{
    public const VERSION = 1;

    private const MESSAGE_LIMIT = 2000;

    private const FRAME_LIMIT = 100;

    private const PREVIOUS_LIMIT = 10;

    private const HEADER_LIMIT = 500;

    private const SQL_LIMIT = 2000;

    /**
     * How far back the trace of a query is read, and how much of it is sent. The first has to
     * get past the database layer to the code that asked.
     */
    private const BACKTRACE_LIMIT = 120;

    private const QUERY_FRAME_LIMIT = 50;

    /**
     * The lines of code around a frame of the site's own code: this many before and after the
     * line itself, each cut to a length, and for this many frames from the top. Below that the
     * trace is the framework calling in, and the code adds nothing.
     */
    private const SOURCE_CONTEXT = 5;

    private const SOURCE_LINE_LIMIT = 300;

    private const SOURCE_FRAME_LIMIT = 10;

    /**
     * The lines of a file, by its path, read once per report.
     *
     * @var array<string, list<string>|null>
     */
    private array $fileLines = [];

    /**
     * The request part may be this many bytes as JSON. Above it the body goes, then the rest.
     */
    private const REQUEST_LIMIT = 4096;

    /**
     * The Blade file a compiled view came from, by the path of the compiled file.
     *
     * @var array<string, ?string>
     */
    private array $bladeSources = [];

    public function __construct(
        private Application $app,
        private Scrubber $scrubber,
        private Context $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(Throwable $exception): array
    {
        $type = $this->context->type();
        $request = $type === Context::HTTP ? $this->currentRequest() : null;

        return [
            'v' => self::VERSION,
            'type' => 'error',
            'occurred_at' => date(DATE_ATOM),
            'site' => $this->site(),
            'exception' => $this->exception($exception),
            'context' => $this->context($type, $request),
            'request' => $request ? $this->request($request) : null,
        ];
    }

    /**
     * The report of a query that stood out: one that was slow, or one that ran many times in
     * the same request or job. Called at the moment of the query, so the trace is where it was
     * asked for. The values of the query are not part of it: `$sql` has its placeholders.
     *
     * @param  'slow'|'repeated'  $kind
     * @return array<string, mixed>
     */
    public function query(string $kind, string $sql, ?string $connection, float $milliseconds, int $count): array
    {
        $type = $this->context->type();
        $frames = $this->queryFrames();

        return [
            'v' => self::VERSION,
            'type' => 'query',
            'occurred_at' => date(DATE_ATOM),
            'site' => $this->site(),
            'query' => [
                'kind' => $kind,
                'sql' => $this->scrubber->cut($sql, self::SQL_LIMIT),
                'connection' => $this->cut($connection, 100),
                'duration_ms' => round($milliseconds, 1),
                'count' => $count,
                'file' => $frames[0]['file'] ?? null,
                'line' => $frames[0]['line'] ?? null,
                'frames' => $frames,
            ],
            'context' => $this->context($type, $type === Context::HTTP ? $this->currentRequest() : null),
        ];
    }

    /**
     * The report of a violation of the Content-Security-Policy, as CspReports made it out of what
     * a browser posted: already scrubbed, with how often it was seen since it was last sent.
     *
     * @param  array{directive: string, blocked_uri: ?string, document_uri: ?string, referrer: ?string, disposition: string, source_file: ?string, line: ?int, column: ?int, sample: ?string, status_code: ?int}  $violation
     * @return array<string, mixed>
     */
    public function csp(array $violation, int $count, ?string $userAgent): array
    {
        return [
            'v' => self::VERSION,
            'type' => 'csp',
            'occurred_at' => date(DATE_ATOM),
            'site' => $this->site(),
            'csp' => [...$violation, 'count' => $count, 'user_agent' => $userAgent === '' ? null : $userAgent],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $type, ?Request $request): array
    {
        return [
            'type' => $type,
            'method' => $request?->method(),
            'url' => $request ? $this->scrubber->cut($request->url(), 2000) : null,
            'route' => $request ? $this->route($request) : null,
            'command' => $this->cut($this->context->command(), 500),
            'job' => $this->cut($this->context->job(), 255),
            'user_id' => $this->userId(),
        ];
    }

    /**
     * The directory is what the dashboard knows the site by, so it is sent as it is. The host is
     * only a label.
     *
     * @return array<string, ?string>
     */
    private function site(): array
    {
        $host = parse_url((string) $this->config('app.url'), PHP_URL_HOST);

        return [
            'path' => $this->scrubber->cut($this->app->basePath(), 1000),
            'host' => is_string($host) ? $this->scrubber->cut($host, 255) : null,
            'environment' => $this->cut((string) $this->app->environment(), 50),
            'php' => $this->cut(PHP_VERSION, 30),
            'laravel' => $this->cut($this->app->version(), 30),
            'beacon' => $this->cut($this->packageVersion(), 30),
        ];
    }

    private function packageVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('nickdekruijk/laravel-beacon');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exception(Throwable $exception): array
    {
        $frames = $this->frames($exception);
        $previous = [];

        for ($cause = $exception->getPrevious(); $cause && count($previous) < self::PREVIOUS_LIMIT; $cause = $cause->getPrevious()) {
            $previous[] = ['class' => $this->scrubber->cut($cause::class, 255), 'message' => $this->message($cause)];
        }

        return [
            'class' => $this->scrubber->cut($exception::class, 255),
            'message' => $this->message($exception),
            'code' => $this->scrubber->cut((string) $exception->getCode(), 50),
            // The same file as the first frame, so a compiled view is its Blade file here too.
            'file' => $frames[0]['file'],
            'line' => $exception->getLine(),
            'previous' => $previous,
            'frames' => $frames,
        ];
    }

    /**
     * The message, with secrets in it masked. A QueryException carries the query with the values
     * filled in: an e-mail address, a name, whatever was being saved. The query with its
     * placeholders says the same about the bug.
     */
    private function message(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // Fine on a site without illuminate/database: instanceof does not load the class.
        if ($exception instanceof QueryException) {
            $message = trim((string) $exception->getPrevious()?->getMessage().' (Connection: '.$exception->connectionName.', SQL: '.$exception->getSql().')');
        }

        return $this->scrubber->message($message, self::MESSAGE_LIMIT);
    }

    /**
     * The stack trace from where the exception was thrown outwards. PHP gives each call with the
     * place it was called from; here every frame is a place with the function that was running
     * there, which is how a person reads a trace.
     *
     * @return non-empty-list<Frame>
     */
    private function frames(Throwable $exception): array
    {
        return array_slice($this->framesFrom($exception->getFile(), $exception->getLine(), $exception->getTrace()), 0, self::FRAME_LIMIT);
    }

    /**
     * Where a query was asked for: the trace from the first frame in the site's own code
     * outwards. Before that frame it is the same every time, the database layer and the event
     * that brought the query here. A query that no code of the site asked for keeps them all.
     *
     * @return list<Frame>
     */
    private function queryFrames(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT);

        // The calls of this package itself, the innermost of the trace, say nothing about where
        // the query came from: the trace starts where the package was called.
        do {
            $here = array_shift($trace);
        } while (str_starts_with((string) ($trace[0]['class'] ?? ''), __NAMESPACE__.'\\'));

        $frames = $this->framesFrom($here['file'] ?? null, $here['line'] ?? null, $trace);

        foreach ($frames as $index => $frame) {
            if ($frame['in_app']) {
                $frames = array_slice($frames, $index);

                break;
            }
        }

        return array_slice($frames, 0, self::QUERY_FRAME_LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $trace  As PHP gives it: each call with the place it was called from.
     * @return non-empty-list<Frame>
     */
    private function framesFrom(?string $file, ?int $line, array $trace): array
    {
        $frames = [];
        $places = [];

        foreach ($trace as $call) {
            $frames[] = $this->frame($file, $line, $call['class'] ?? null, $call['function'] ?? null);
            $places[] = $file;

            $file = $call['file'] ?? null;
            $line = $call['line'] ?? null;
        }

        $frames[] = $this->frame($file, $line, null, '{main}');
        $places[] = $file;

        $withSource = 0;

        foreach ($frames as $index => $frame) {
            if ($frame['in_app'] && $withSource < self::SOURCE_FRAME_LIMIT) {
                $frames[$index]['source'] = $this->source($places[$index], $frame['line']);
                $withSource += $frames[$index]['source'] === null ? 0 : 1;
            }
        }

        return $frames;
    }

    /**
     * The code around a line, read from the file as it is on the site now. Not for a compiled
     * view: its line number is that of the compiled file, which is not the Blade file the frame
     * names. Nothing when the file cannot be read.
     *
     * @return Source|null
     */
    private function source(?string $file, ?int $line): ?array
    {
        $compiled = rtrim((string) $this->config('view.compiled'), '/');

        if ($file === null || $line === null || $line < 1 || ($compiled !== '' && str_starts_with($file, $compiled.'/'))) {
            return null;
        }

        if (! array_key_exists($file, $this->fileLines)) {
            $contents = is_file($file) && is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
            $this->fileLines[$file] = $contents === false ? null : $contents;
        }

        $lines = $this->fileLines[$file];

        if ($lines === null || $line > count($lines)) {
            return null;
        }

        $start = max(1, $line - self::SOURCE_CONTEXT);
        $slice = array_slice($lines, $start - 1, min($line + self::SOURCE_CONTEXT, count($lines)) - $start + 1);

        return [
            'start' => $start,
            'lines' => array_map(fn (string $code) => $this->scrubber->cut(rtrim($code), self::SOURCE_LINE_LIMIT), $slice),
        ];
    }

    /**
     * @return Frame
     */
    private function frame(?string $file, ?int $line, ?string $class, ?string $function): array
    {
        [$file, $inApp] = $this->locate($file);

        // Since PHP 8.4 a closure outside a class is named after its file, with the full path:
        // {closure:/home/forge/site/releases/31/routes/web.php:12}. Relative, like the files.
        if ($function !== null) {
            $function = str_replace(rtrim($this->app->basePath(), '/').'/', '', $function);
        }

        return [
            'file' => $file,
            'line' => $line,
            'class' => $this->cut($class, 255),
            'function' => $this->cut($function, 255),
            'in_app' => $inApp,
            'source' => null,
        ];
    }

    /**
     * The file relative to the site, and whether it is the site's own code: inside the site and
     * not in vendor. A compiled view becomes the Blade file it was made from. Its own name is a
     * hash that differs per release, so one that cannot be traced back names no place.
     *
     * @return array{?string, bool}
     */
    private function locate(?string $file): array
    {
        if ($file === null) {
            return [null, false];
        }

        $compiled = rtrim((string) $this->config('view.compiled'), '/');
        $traceable = true;

        if ($compiled !== '' && str_starts_with($file, $compiled.'/')) {
            $source = $this->bladeSource($file);
            $traceable = $source !== null;
            $file = $source ?? $file;
        }

        $base = rtrim($this->app->basePath(), '/').'/';

        if (! str_starts_with($file, $base)) {
            return [$this->scrubber->cut($file, 1000), false];
        }

        $relative = substr($file, strlen($base));

        return [$this->scrubber->cut($relative, 1000), $traceable && ! str_starts_with($relative, 'vendor/')];
    }

    /**
     * Blade ends a compiled view with the path it came from.
     */
    private function bladeSource(string $compiledFile): ?string
    {
        if (! array_key_exists($compiledFile, $this->bladeSources)) {
            $contents = is_file($compiledFile) ? (string) @file_get_contents($compiledFile) : '';

            $this->bladeSources[$compiledFile] = preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $contents, $matches) ? $matches[1] : null;
        }

        return $this->bladeSources[$compiledFile];
    }

    private function currentRequest(): ?Request
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        return $request instanceof Request ? $request : null;
    }

    /**
     * The name of the route, or its pattern: "orders/{order}" groups better than "orders/31".
     */
    private function route(Request $request): ?string
    {
        $route = $request->route();

        return $route instanceof Route ? $this->cut($route->getName() ?? $route->uri(), 255) : null;
    }

    /**
     * The id only. Who that is can be looked up; a name or an e-mail address need not travel.
     * Asking for it can itself fail, when the database is what broke.
     */
    private function userId(): ?string
    {
        try {
            $id = $this->app->bound('auth') ? $this->app->make('auth')->id() : null;
        } catch (Throwable) {
            return null;
        }

        return is_scalar($id) ? $this->scrubber->cut((string) $id, 100) : null;
    }

    /**
     * The request the exception happened in, as far as the configuration allows and never more
     * than the scrubber lets through. Should collecting it fail, the report goes without.
     *
     * @return array<string, mixed>|null
     */
    private function request(Request $request): ?array
    {
        try {
            $livewire = $this->livewire($request);

            $data = [
                'query' => $this->allows('query') ? ($this->scrubber->fields($request->query()) ?: null) : null,
                'body' => $this->allows('body') && $livewire === null ? $this->body($request) : null,
                'headers' => $this->allows('headers') ? $this->headers($request) : null,
                'ip' => $this->scrubber->ip($request->ip(), (string) $this->config('beacon.request.ip', 'anonymized')),
                'livewire' => $this->allows('body') ? $livewire : null,
                'truncated' => false,
            ];

            if ($this->size($data) > self::REQUEST_LIMIT) {
                $data = [...$data, 'body' => null, 'truncated' => true];
            }

            if ($this->size($data) > self::REQUEST_LIMIT) {
                $data = [...$data, 'query' => null, 'livewire' => null];
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function size(array $data): int
    {
        return strlen((string) json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * The posted fields. Of an upload only the name of its field: the file stays home.
     *
     * @return array<array-key, mixed>|null
     */
    private function body(Request $request): ?array
    {
        $fields = $this->scrubber->fields($request->isJson() ? $request->json()->all() : $request->request->all());

        foreach (array_keys($request->allFiles()) as $name) {
            $fields[$name] = '[File]';
        }

        return $fields ?: null;
    }

    /**
     * @return array<string, string>|null
     */
    private function headers(Request $request): ?array
    {
        $headers = [];

        foreach (Scrubber::HEADERS as $name) {
            $value = $request->headers->get($name);

            if (is_string($value) && $value !== '') {
                // A referer is a URL, and a URL can carry a token.
                $headers[$name] = $this->scrubber->message($value, self::HEADER_LIMIT);
            }
        }

        return $headers ?: null;
    }

    /**
     * A Livewire update carries the whole state of its components. What says something about
     * the bug is which component, which method was called and which properties changed: the
     * names, not the values.
     *
     * @return array{components: list<array{name: ?string, calls: list<string>, updates: list<string>}>}|null
     */
    private function livewire(Request $request): ?array
    {
        $components = $request->input('components');

        if (! $request->headers->has('X-Livewire') || ! is_array($components)) {
            return null;
        }

        $summary = [];

        foreach (array_slice(array_filter($components, 'is_array'), 0, 20) as $component) {
            $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;
            $calls = [];

            foreach (is_array($component['calls'] ?? null) ? $component['calls'] : [] as $call) {
                if (is_array($call) && is_string($call['method'] ?? null)) {
                    $calls[] = $this->scrubber->cut($call['method'], 100);
                }
            }

            $summary[] = [
                'name' => is_string($name) ? $this->scrubber->cut($name, 255) : null,
                'calls' => array_slice($calls, 0, 20),
                'updates' => array_slice(array_map(
                    fn (int|string $property) => $this->scrubber->cut((string) $property, 100),
                    array_keys(is_array($component['updates'] ?? null) ? $component['updates'] : []),
                ), 0, 50),
            ];
        }

        return ['components' => $summary];
    }

    /**
     * Whether a part of the request may be sent. An env value arrives as a string, and "off" or
     * "0" mean no as much as "false" does.
     */
    private function allows(string $part): bool
    {
        return filter_var($this->config('beacon.request.'.$part, true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get($key, $default);
    }

    private function cut(?string $value, int $limit): ?string
    {
        return $value === null ? null : $this->scrubber->cut($value, $limit);
    }
}
