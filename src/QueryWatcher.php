<?php

namespace NickDeKruijk\Beacon;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Looks at every query the site runs and reports the two kinds that are worth a look: one that
 * is slow, and one that runs many times in the same request or queued job, which is nearly
 * always a relation loaded inside a loop (an N+1).
 *
 * Looking costs next to nothing: a query is counted by its text. The expensive part, the trace
 * that says where the query was asked for, is only taken for a query that is about to be
 * reported. A slow query is reported at once. A repeated one is reported when the request or
 * job ends, with how often it ran in the end.
 *
 * Only switched on with BEACON_QUERIES. Nothing here may throw: a query that worked must never
 * fail because it was looked at.
 */
class QueryWatcher
{
    public const SLOW = 'slow';

    public const REPEATED = 'repeated';

    /**
     * No more different queries than this are counted in one request or job. A job that builds
     * its queries by hand could otherwise fill the memory of its worker.
     */
    private const DISTINCT_LIMIT = 500;

    /**
     * The queries of the request or job that is running, by their text.
     *
     * @var array<string, array{count: int, milliseconds: float, report: ?array<string, mixed>, afterResponse: bool}>
     */
    private array $queries = [];

    private bool $busy = false;

    public function __construct(
        private Application $app,
        private Collector $collector,
        private Context $context,
        private Beacon $beacon,
    ) {}

    public function executed(QueryExecuted $query): void
    {
        // Remembering that a query was reported can itself be a query, with a database cache.
        if ($this->busy) {
            return;
        }

        $this->busy = true;

        try {
            $sql = self::normalize($query->sql);
            $type = $this->context->type();

            if ($query->time >= (float) $this->config('slow_ms', 200) && $this->claim(self::SLOW, $sql)) {
                $this->beacon->deliver(
                    $this->collector->query(self::SLOW, $sql, $query->connectionName, (float) $query->time, 1),
                    afterResponse: $type === Context::HTTP,
                );
            }

            // A command is batch work: the same query a thousand times is what it is for.
            if ($type !== Context::CONSOLE) {
                $this->count($sql, $query, $type);
            }
        } catch (Throwable) {
            // The query itself went fine.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * The request or job is over, or a job starts in the middle of a request: report what was
     * repeated so far and start counting again.
     */
    public function finish(): void
    {
        $queries = $this->queries;
        $this->queries = [];

        foreach ($queries as $sql => $seen) {
            // Often, but for next to nothing: not worth a report, and not a reason to stay
            // silent about it for an hour either.
            if ($seen['report'] === null || $seen['milliseconds'] < (float) $this->config('repeats_ms', 50)) {
                continue;
            }

            try {
                if (! $this->claim(self::REPEATED, $sql)) {
                    continue;
                }

                $seen['report']['query']['count'] = $seen['count'];
                $seen['report']['query']['duration_ms'] = round($seen['milliseconds'], 1);

                $this->beacon->deliver($seen['report'], $seen['afterResponse']);
            } catch (Throwable) {
                // Nothing that a visitor or a job should notice.
            }
        }
    }

    /**
     * The text of a query without anything that differs per call, so the same query is the same
     * text: values written into it become placeholders, a list of placeholders becomes one.
     * This is also what keeps a value that was written into a raw query on the site.
     */
    public static function normalize(string $sql): string
    {
        $sql = preg_replace([
            // A quoted text, with '' or \' inside it.
            "/'(?:[^'\\\\]|\\\\.|'')*'/",
            // A number that stands by itself: not part of a name, and not the $1 of PostgreSQL.
            '/(?<![\w`"$.])-?\d+(?:\.\d+)?(?![\w`"])/',
            // in (?, ?, ?) is as long as its list.
            '/\(\s*\?(?:\s*,\s*\?)*\s*\)/',
            // values (?), (?), (?) is as long as its rows.
            '/\(\?\)(?:\s*,\s*\(\?\))+/',
            '/\s+/',
        ], ['?', '?', '(?)', '(?), ...', ' '], $sql) ?? $sql;

        return trim($sql);
    }

    private function count(string $sql, QueryExecuted $query, string $type): void
    {
        if (! isset($this->queries[$sql])) {
            if (count($this->queries) >= self::DISTINCT_LIMIT) {
                return;
            }

            $this->queries[$sql] = ['count' => 0, 'milliseconds' => 0.0, 'report' => null, 'afterResponse' => false];
        }

        $seen = &$this->queries[$sql];
        $seen['count']++;
        $seen['milliseconds'] += (float) $query->time;

        // At the moment it becomes too many, the trace is that of the loop it runs in. The
        // count and the time are filled in when the request or job ends, and only then is it
        // decided whether it is reported: by then it is known what the runs cost together.
        if ($seen['count'] === max(2, (int) $this->config('repeats', 5)) && ! $this->reportedLately(self::REPEATED, $sql)) {
            $seen['report'] = $this->collector->query(self::REPEATED, $sql, $query->connectionName, $seen['milliseconds'], $seen['count']);
            $seen['afterResponse'] = $type === Context::HTTP;
        }
    }

    /**
     * Whether this query may be reported now, and if so, that it has been: once per
     * `beacon.queries.every` seconds. A page with a slow query would otherwise report it with
     * every visit. Without a working cache nothing is reported, as there is nothing to
     * remember it in.
     */
    private function claim(string $kind, string $sql): bool
    {
        try {
            return Cache::add($this->key($kind, $sql), true, max(1, (int) $this->config('every', 3600)));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The same question without the claim, to skip taking a trace that would not be sent.
     */
    private function reportedLately(string $kind, string $sql): bool
    {
        try {
            return Cache::has($this->key($kind, $sql));
        } catch (Throwable) {
            return true;
        }
    }

    private function key(string $kind, string $sql): string
    {
        return 'beacon:query:'.sha1($kind.'|'.$sql);
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('beacon.queries.'.$key, $default);
    }
}
