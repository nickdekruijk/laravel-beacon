<?php

namespace NickDeKruijk\Beacon\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use NickDeKruijk\Beacon\Beacon;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

class CspTest extends TestCase
{
    /**
     * A report as report-uri posts it, with the fields of a test changed.
     *
     * @param  array<string, mixed>  $fields
     */
    private function reportUri(array $fields = []): string
    {
        return (string) json_encode(['csp-report' => [
            'document-uri' => 'https://voorbeeld.test/orders?email=jan@voorbeeld.test#top',
            'referrer' => 'https://www.google.com/search?q=orders',
            'violated-directive' => 'script-src-elem',
            'effective-directive' => 'script-src-elem',
            'original-policy' => "default-src 'self'; script-src 'self'; report-uri /beacon/csp",
            'disposition' => 'report',
            'blocked-uri' => 'https://cdn.voorbeeld-tracker.test/pixel.js?token=abc123',
            'line-number' => 12,
            'column-number' => 7,
            'source-file' => 'https://voorbeeld.test/orders',
            'status-code' => 200,
            'script-sample' => '',
            ...$fields,
        ]]);
    }

    /**
     * Reports as the Reporting API posts them: a list, of which only CSP violations count.
     *
     * @param  array<string, mixed>  $body
     */
    private function reportTo(array $body = []): string
    {
        return (string) json_encode([
            ['type' => 'deprecation', 'url' => 'https://voorbeeld.test/', 'body' => ['id' => 'Something']],
            ['type' => 'csp-violation', 'age' => 10, 'url' => 'https://voorbeeld.test/contact', 'user_agent' => 'Chrome', 'body' => [
                'documentURL' => 'https://voorbeeld.test/contact',
                'referrer' => '',
                'blockedURL' => 'inline',
                'effectiveDirective' => 'script-src-elem',
                'originalPolicy' => "script-src 'self'",
                'disposition' => 'enforce',
                'sourceFile' => 'https://voorbeeld.test/contact',
                'lineNumber' => 3,
                'columnNumber' => 1,
                'sample' => str_repeat('console.log(1);', 20),
                'statusCode' => 200,
                ...$body,
            ]],
        ]);
    }

    private function postReport(string $body, string $contentType = 'application/csp-report', string $ip = '203.0.113.7'): TestResponse
    {
        $response = $this->call('POST', 'https://voorbeeld.test/beacon/csp', server: [
            'CONTENT_TYPE' => $contentType,
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Safari',
        ], content: $body);

        $this->app->terminate();

        return $response;
    }

    public function test_a_report_of_report_uri_is_sent_on_scrubbed_after_the_response(): void
    {
        $this->postReport($this->reportUri())->assertNoContent();

        $report = $this->lastReport();

        $this->assertSame('csp', $report['type']);
        $this->assertSame(base_path(), $report['site']['path']);
        $this->assertSame([
            'directive' => 'script-src-elem',
            'blocked_uri' => 'https://cdn.voorbeeld-tracker.test/pixel.js',
            'document_uri' => 'https://voorbeeld.test/orders',
            'referrer' => 'https://www.google.com/search',
            'disposition' => 'report',
            'source_file' => 'https://voorbeeld.test/orders',
            'line' => 12,
            'column' => 7,
            'sample' => null,
            'status_code' => 200,
            'count' => 1,
            'user_agent' => 'Mozilla/5.0 Safari',
        ], $report['csp']);
        $this->assertStringNotContainsString('original-policy', (string) json_encode($report));
    }

    public function test_the_csp_violations_of_the_reporting_api_are_sent_and_the_rest_left_out(): void
    {
        $this->postReport($this->reportTo(), 'application/reports+json')->assertNoContent();

        $reports = $this->reports();

        $this->assertCount(1, $reports);
        $this->assertSame('enforce', $reports[0]['csp']['disposition']);
        $this->assertSame('inline', $reports[0]['csp']['blocked_uri']);
        $this->assertSame('/contact', parse_url($reports[0]['csp']['document_uri'], PHP_URL_PATH));
        $this->assertSame(100, mb_strlen($reports[0]['csp']['sample']));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function noise(): array
    {
        return [
            'a blocked extension' => [['blocked-uri' => 'chrome-extension://abcdef/inject.js']],
            'a script of an extension' => [['source-file' => 'moz-extension://1234/content.js']],
            'Safari hiding an extension' => [['source-file' => 'webkit-masked-url://hidden/']],
            'a page of the browser' => [['blocked-uri' => 'about:blank']],
            'a page of another site' => [['document-uri' => 'https://voorbeeld-test.translate.goog/orders']],
            'no page at all' => [['document-uri' => '']],
            'no directive' => [['effective-directive' => '', 'violated-directive' => '']],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('noise')]
    public function test_noise_is_left_out(array $fields): void
    {
        $this->postReport($this->reportUri($fields))->assertNoContent();

        $this->assertSame([], $this->reports());
    }

    public function test_the_same_violation_is_sent_once_per_period_with_the_times_it_was_seen(): void
    {
        foreach (range(1, 4) as $visit) {
            $this->postReport($this->reportUri(), ip: '203.0.113.'.$visit);
        }

        $this->assertCount(1, $this->reports());

        $this->travel(config('beacon.csp.every') + 1)->seconds();
        $this->postReport($this->reportUri());

        $this->assertCount(2, $this->reports());
        $this->assertSame(4, $this->lastReport()['csp']['count']);
    }

    public function test_another_page_or_disposition_is_another_violation(): void
    {
        $this->postReport($this->reportUri());
        $this->postReport($this->reportUri(['document-uri' => 'https://voorbeeld.test/contact']));
        $this->postReport($this->reportUri(['disposition' => 'enforce']));
        $this->postReport($this->reportUri(['document-uri' => 'https://voorbeeld.test/orders?page=2']));

        $this->assertCount(3, $this->reports());
    }

    public function test_a_path_segment_that_looks_like_a_token_is_left_out(): void
    {
        $this->postReport($this->reportUri(['document-uri' => 'https://voorbeeld.test/reset-password/4f9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c']));

        $this->assertSame('https://voorbeeld.test/reset-password/{token}', $this->lastReport()['csp']['document_uri']);
    }

    public function test_a_blocked_data_or_blob_is_only_its_scheme(): void
    {
        $this->postReport($this->reportUri(['blocked-uri' => 'data:image/png;base64,iVBORw0KGgo=']));

        $this->assertSame('data', $this->lastReport()['csp']['blocked_uri']);
    }

    public function test_one_address_that_keeps_posting_is_slowed_down_and_still_gets_a_204(): void
    {
        foreach (range(1, 35) as $page) {
            $this->postReport($this->reportUri(['document-uri' => 'https://voorbeeld.test/page-'.$page]))->assertNoContent();
        }

        // 30 got in, of which the limit per minute for CSP reports sent 10.
        $this->assertCount(10, $this->reports());

        $this->travel(61)->seconds();
        $this->postReport($this->reportUri(['document-uri' => 'https://voorbeeld.test/page-36']), ip: '198.51.100.9');

        $this->assertCount(11, $this->reports());
    }

    public function test_a_body_that_is_too_large_or_no_json_is_refused_with_a_204(): void
    {
        $this->postReport($this->reportUri(['script-sample' => str_repeat('a', 40000)]))->assertNoContent();
        $this->postReport('not json')->assertNoContent();
        $this->postReport('{"csp-report": "text"}')->assertNoContent();

        $this->assertSame([], $this->reports());
    }

    public function test_no_csrf_token_or_session_is_needed(): void
    {
        $this->postReport($this->reportUri())->assertNoContent()->assertCookieMissing('laravel_session');
    }

    #[DefineEnvironment('inLocalEnvironment')]
    public function test_the_route_exists_locally_but_sends_nothing(): void
    {
        $this->postReport($this->reportUri())->assertNoContent();

        $this->assertSame([], $this->reports());
        $this->assertNull(Beacon::cspReportUri());
    }

    #[DefineEnvironment('cspOff')]
    public function test_csp_reports_can_be_switched_off(): void
    {
        $this->postReport($this->reportUri())->assertNoContent();

        $this->assertSame([], $this->reports());
        $this->assertNull(Beacon::cspReportUri());
    }

    public function test_the_helper_gives_the_route_where_it_reports(): void
    {
        $this->assertSame(route('beacon.csp'), Beacon::cspReportUri());
        $this->assertStringEndsWith('/beacon/csp', (string) Beacon::cspReportUri());
    }

    public function test_an_application_that_takes_its_reports_itself_gets_the_csp_reports_too(): void
    {
        $taken = [];
        Beacon::sendUsing(function (array $report) use (&$taken) {
            $taken[] = $report;
        });

        $this->postReport($this->reportUri());

        $this->assertCount(1, $taken);
        $this->assertSame('csp', $taken[0]['type']);
        Http::assertNothingSent();
    }

    public function test_the_fixture_is_what_the_package_sends(): void
    {
        $this->postReport($this->reportUri());

        $fixture = json_decode((string) file_get_contents(__DIR__.'/Fixtures/csp-report.json'), true);

        $this->assertSame(array_keys($fixture), array_keys($this->lastReport()));
        $this->assertSame(array_keys($fixture['csp']), array_keys($this->lastReport()['csp']));
    }

    protected function cspOff(): void
    {
        $this->beaconConfig['csp.enabled'] = false;
    }
}
