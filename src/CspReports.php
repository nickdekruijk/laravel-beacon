<?php

namespace NickDeKruijk\Beacon;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Turns what a browser posted about the Content-Security-Policy into reports for the dashboard.
 * Both ways a browser reports: `report-uri` posts one object under `csp-report`, `report-to`
 * (the Reporting API) posts a list of reports of which those of `type` `csp-violation` count.
 *
 * Left out: what browser extensions and other software on the visitor's machine cause, and a
 * page that is not of this site (a translation proxy shows the page under its own address).
 * The same violation on the same page is sent once per `beacon.csp.every` seconds, with the
 * number of times it was seen since: one broken embed must not send a report per visit.
 */
class CspReports
{
    /**
     * Schemes of what a browser extension or the browser itself adds to a page. Safari shows
     * the script of an extension as webkit-masked-url.
     *
     * @var list<string>
     */
    public const NOISE_SCHEMES = [
        'chrome-extension', 'moz-extension', 'safari-extension', 'safari-web-extension', 'ms-browser-extension',
        'webkit-masked-url', 'chrome', 'resource', 'about', 'android-webview-video-poster',
    ];

    /**
     * At most this many violations of one post are looked at.
     */
    private const PER_POST = 10;

    private const SAMPLE_LIMIT = 100;

    public function __construct(private Collector $collector) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function fromRequest(Request $request): array
    {
        $reports = [];

        foreach (array_slice($this->violations($request->getContent()), 0, self::PER_POST) as $violation) {
            if ($violation['directive'] === '' || $this->isNoise($violation, $request->getHost())) {
                continue;
            }

            $violation = $this->scrub($violation);
            $count = $this->countSince($violation);

            if ($count !== null) {
                $reports[] = $this->collector->csp($violation, $count, $this->cut((string) $request->userAgent(), 300));
            }
        }

        return $reports;
    }

    /**
     * The violations in a post, in one shape whatever way the browser reported them.
     *
     * @return list<array{directive: string, blocked_uri: ?string, document_uri: ?string, referrer: ?string, disposition: string, source_file: ?string, line: ?int, column: ?int, sample: ?string, status_code: ?int}>
     */
    public function violations(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return [];
        }

        // report-uri: {"csp-report": {"document-uri": ..., "effective-directive": ...}}
        if (is_array($data['csp-report'] ?? null)) {
            $report = $data['csp-report'];

            return [$this->shape(
                directive: $report['effective-directive'] ?? $report['violated-directive'] ?? null,
                blocked: $report['blocked-uri'] ?? null,
                document: $report['document-uri'] ?? null,
                referrer: $report['referrer'] ?? null,
                disposition: $report['disposition'] ?? null,
                source: $report['source-file'] ?? null,
                line: $report['line-number'] ?? null,
                column: $report['column-number'] ?? null,
                sample: $report['script-sample'] ?? null,
                status: $report['status-code'] ?? null,
            )];
        }

        // report-to: [{"type": "csp-violation", "body": {"documentURL": ..., "effectiveDirective": ...}}]
        $violations = [];

        foreach (array_is_list($data) ? $data : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'csp-violation' || ! is_array($item['body'] ?? null)) {
                continue;
            }

            $body = $item['body'];
            $violations[] = $this->shape(
                directive: $body['effectiveDirective'] ?? null,
                blocked: $body['blockedURL'] ?? null,
                document: $body['documentURL'] ?? $item['url'] ?? null,
                referrer: $body['referrer'] ?? null,
                disposition: $body['disposition'] ?? null,
                source: $body['sourceFile'] ?? null,
                line: $body['lineNumber'] ?? null,
                column: $body['columnNumber'] ?? null,
                sample: $body['sample'] ?? null,
                status: $body['statusCode'] ?? null,
            );
        }

        return $violations;
    }

    /**
     * @return array{directive: string, blocked_uri: ?string, document_uri: ?string, referrer: ?string, disposition: string, source_file: ?string, line: ?int, column: ?int, sample: ?string, status_code: ?int}
     */
    private function shape(mixed $directive, mixed $blocked, mixed $document, mixed $referrer, mixed $disposition, mixed $source, mixed $line, mixed $column, mixed $sample, mixed $status): array
    {
        $text = fn (mixed $value, int $limit) => is_string($value) && $value !== '' ? $this->cut($value, $limit) : null;
        $number = fn (mixed $value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null;

        // "script-src-elem 'self'" in an old browser that sends the whole directive.
        $directive = strtolower(trim(explode(' ', is_string($directive) ? trim($directive) : '')[0]));

        return [
            'directive' => $this->cut($directive, 100),
            'blocked_uri' => $text($blocked, 2000),
            'document_uri' => $text($document, 2000),
            'referrer' => $text($referrer, 2000),
            'disposition' => $disposition === 'report' ? 'report' : 'enforce',
            'source_file' => $text($source, 2000),
            'line' => $number($line),
            'column' => $number($column),
            'sample' => $text($sample, self::SAMPLE_LIMIT),
            'status_code' => $number($status),
        ];
    }

    /**
     * A violation caused by an extension or the browser, or reported for a page that is not
     * of this site.
     *
     * @param  array{directive: string, blocked_uri: ?string, document_uri: ?string, source_file: ?string}  $violation
     */
    private function isNoise(array $violation, string $host): bool
    {
        foreach ([$violation['blocked_uri'], $violation['source_file'], $violation['document_uri']] as $uri) {
            $scheme = $uri !== null ? strtolower((string) strtok($uri, ':')) : null;

            if ($scheme !== null && $scheme !== $uri && in_array($scheme, self::NOISE_SCHEMES, true)) {
                return true;
            }
        }

        $documentHost = $violation['document_uri'] !== null ? parse_url($violation['document_uri'], PHP_URL_HOST) : null;

        return ! is_string($documentHost) || strcasecmp($documentHost, $host) !== 0;
    }

    /**
     * Without the query string and the fragment, which can hold a token or an e-mail address,
     * and without a path segment that looks like a token, as in a link to reset a password. A
     * blocked `data:` or `blob:` is only its scheme.
     *
     * @param  array{directive: string, blocked_uri: ?string, document_uri: ?string, referrer: ?string, disposition: string, source_file: ?string, line: ?int, column: ?int, sample: ?string, status_code: ?int}  $violation
     * @return array{directive: string, blocked_uri: ?string, document_uri: ?string, referrer: ?string, disposition: string, source_file: ?string, line: ?int, column: ?int, sample: ?string, status_code: ?int}
     */
    private function scrub(array $violation): array
    {
        foreach (['blocked_uri', 'document_uri', 'referrer', 'source_file'] as $field) {
            $violation[$field] = $this->scrubUri($violation[$field]);
        }

        return $violation;
    }

    public function scrubUri(?string $uri): ?string
    {
        if ($uri === null) {
            return null;
        }

        if (preg_match('/^(data|blob|filesystem):/i', $uri, $scheme)) {
            return strtolower($scheme[1]);
        }

        $uri = preg_replace('/[?#].*$/s', '', $uri) ?? $uri;

        return preg_replace('#(?<=/)[A-Za-z0-9_-]{24,}(?=/|$)#', '{token}', $uri) ?? $uri;
    }

    /**
     * How often this violation was seen since it was last sent, this time included, or null
     * when it was sent lately and is only counted. Without a working cache nothing is sent: a
     * flood of reports is worse than none.
     *
     * @param  array{directive: string, blocked_uri: ?string, document_uri: ?string, disposition: string}  $violation
     */
    private function countSince(array $violation): ?int
    {
        $key = 'beacon:csp:'.sha1(implode('|', [
            $violation['directive'],
            $violation['blocked_uri'],
            parse_url((string) $violation['document_uri'], PHP_URL_PATH),
            $violation['disposition'],
        ]));
        $every = max(1, (int) config('beacon.csp.every', 3600));

        try {
            if (Cache::add($key.':sent', true, $every)) {
                return 1 + (int) Cache::pull($key.':seen', 0);
            }

            Cache::add($key.':seen', 0, $every * 2);
            Cache::increment($key.':seen');
        } catch (Throwable) {
            // Nothing to count in.
        }

        return null;
    }

    private function cut(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
