<?php

namespace NickDeKruijk\Beacon\Tests;

use App\OrderList;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use NickDeKruijk\Beacon\Beacon;
use NickDeKruijk\Beacon\QueryWatcher;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

class QueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Code of the site itself that asks for queries: the place a report points at.
        File::ensureDirectoryExists(base_path('app'));
        File::put(base_path('app/OrderList.php'), <<<'PHP'
            <?php

            namespace App;

            class OrderList
            {
                public static function customers(int $orders, float $milliseconds = 5.0): void
                {
                    for ($order = 1; $order <= $orders; $order++) {
                        \NickDeKruijk\Beacon\Tests\QueryTest::runQuery('select * from `customers` where `customers`.`id` = ? limit 1', [$order], $milliseconds);
                    }
                }
            }
            PHP);

        require_once base_path('app/OrderList.php');
    }

    protected function tearDown(): void
    {
        File::delete(base_path('app/OrderList.php'));

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        Route::get('orders', fn () => OrderList::customers((int) request('orders', 25)))->name('orders.index');
        Route::get('report', fn () => OrderList::customers(1, 812.44))->name('report.show');
        Route::get('almost', fn () => OrderList::customers(1, 199.9))->name('almost.show');
        Route::get('cheap', fn () => OrderList::customers(40, 0.3))->name('cheap.index');
    }

    protected function watchingQueries(): void
    {
        $this->beaconConfig['queries.enabled'] = true;
    }

    /**
     * A query as Laravel announces it, without a database that has to be slow for real.
     *
     * @param  list<mixed>  $bindings
     */
    public static function runQuery(string $sql, array $bindings = [], float $milliseconds = 1.5): void
    {
        event(new QueryExecuted($sql, $bindings, $milliseconds, DB::connection()));
    }

    public function test_queries_are_not_watched_unless_it_is_switched_on(): void
    {
        $this->get('orders');
        $this->get('report');
        $this->app->terminate();

        $this->assertSame([], $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_report_has_the_shape_of_the_example_in_the_spec(): void
    {
        $example = json_decode((string) file_get_contents(__DIR__.'/Fixtures/query-report.json'), true);

        $this->get('orders');
        $this->app->terminate();
        $report = $this->lastReport();

        $this->assertSame(array_keys($example), array_keys($report));
        $this->assertSame(array_keys($example['site']), array_keys($report['site']));
        $this->assertSame(array_keys($example['query']), array_keys($report['query']));
        $this->assertSame(array_keys($example['query']['frames'][0]), array_keys($report['query']['frames'][0]));
        $this->assertSame(array_keys($example['context']), array_keys($report['context']));
    }

    public function test_the_example_in_the_spec_is_the_one_the_tests_use(): void
    {
        preg_match_all('/```json\n(.+?)\n```/s', (string) file_get_contents(__DIR__.'/../SPEC.md'), $examples);

        $this->assertEquals(
            json_decode((string) file_get_contents(__DIR__.'/Fixtures/query-report.json'), true),
            json_decode($examples[1][1], true),
        );
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_that_runs_many_times_in_a_request_is_reported_once_with_how_often(): void
    {
        $this->get('orders?orders=25');
        $this->app->terminate();

        $this->assertCount(1, $this->reports());
        $report = $this->lastReport();
        $this->assertSame('query', $report['type']);
        $this->assertSame('repeated', $report['query']['kind']);
        $this->assertSame('select * from `customers` where `customers`.`id` = ? limit ?', $report['query']['sql']);
        $this->assertSame(25, $report['query']['count']);
        $this->assertEqualsWithDelta(125.0, $report['query']['duration_ms'], 0.01);
        $this->assertSame(['type' => 'http', 'method' => 'GET', 'url' => 'http://localhost/orders', 'route' => 'orders.index', 'command' => null, 'job' => null, 'user_id' => null], $report['context']);
        $this->assertArrayNotHasKey('request', $report);
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_the_report_points_at_the_code_of_the_site_that_asked_for_the_query(): void
    {
        $this->get('orders');
        $this->app->terminate();
        $query = $this->lastReport()['query'];

        $this->assertSame('app/OrderList.php', $query['file']);
        $this->assertSame(10, $query['line']);
        $this->assertSame(['file' => 'app/OrderList.php', 'line' => 10, 'class' => 'App\OrderList', 'function' => 'customers', 'in_app' => true], $query['frames'][0]);
        $this->assertSame('{main}', $query['frames'][array_key_last($query['frames'])]['function']);
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_that_stays_under_the_number_is_not_reported(): void
    {
        $this->get('orders?orders=4');
        $this->app->terminate();

        $this->assertSame([], $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_that_runs_often_for_next_to_nothing_is_not_reported(): void
    {
        $this->get('cheap');
        $this->app->terminate();

        $this->assertSame([], $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_that_was_too_cheap_to_report_is_reported_as_soon_as_it_costs_something(): void
    {
        $this->get('cheap');
        $this->get('orders?orders=40');
        $this->app->terminate();

        $this->assertCount(1, $this->reports());
        $this->assertSame(40, $this->lastReport()['query']['count']);
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_every_request_counts_for_itself(): void
    {
        $this->get('orders?orders=3');
        $this->get('orders?orders=3');
        $this->app->terminate();

        $this->assertSame([], $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_slow_query_is_reported_with_how_long_it_took(): void
    {
        $this->get('report');
        $this->app->terminate();

        $this->assertCount(1, $this->reports());
        $query = $this->lastReport()['query'];
        $this->assertSame('slow', $query['kind']);
        $this->assertSame(812.4, $query['duration_ms']);
        $this->assertSame(1, $query['count']);
        $this->assertSame('app/OrderList.php', $query['file']);
    }

    /**
     * Not an event made by the test: a query on a real connection, with the limit at zero so it
     * counts as slow.
     */
    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_on_a_real_connection_is_seen(): void
    {
        config(['beacon.queries.slow_ms' => 0]);

        $this->assertSame(31, DB::selectOne('select 31 as answer where 1 = ?', [1])->answer);
        $this->app->terminate();

        $this->assertSame('select ? as answer where ? = ?', $this->lastReport()['query']['sql']);
        $this->assertSame('slow', $this->lastReport()['query']['kind']);
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_query_just_under_the_limit_is_not_slow(): void
    {
        $this->get('almost');
        $this->app->terminate();

        $this->assertSame([], $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_the_values_of_a_query_never_leave_the_site(): void
    {
        self::runQuery("select * from users where email = 'jan@voorbeeld.test' and id = 31 and token = ?", ['geheim'], 900);
        $this->app->terminate();

        $sent = (string) json_encode($this->lastReport());
        $this->assertSame('select * from users where email = ? and id = ? and token = ?', $this->lastReport()['query']['sql']);
        $this->assertStringNotContainsString('jan@voorbeeld.test', $sent);
        $this->assertStringNotContainsString('geheim', $sent);
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_the_same_query_is_reported_once_an_hour(): void
    {
        $this->get('report');
        $this->get('report');
        $this->app->terminate();
        $this->assertCount(1, $this->reports());

        $this->travel(61)->minutes();
        $this->get('report');
        $this->app->terminate();
        $this->assertCount(2, $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_command_reports_a_slow_query_but_not_one_it_repeats(): void
    {
        Artisan::command('orders:import', fn () => OrderList::customers(50));
        Artisan::command('orders:report', fn () => OrderList::customers(1, 900));

        $this->artisan('orders:import')->run();
        $this->assertSame([], $this->reports());

        $this->artisan('orders:report')->run();
        $this->assertSame('slow', $this->lastReport()['query']['kind']);
        $this->assertSame(['type' => 'console', 'command' => 'orders:report'], array_intersect_key($this->lastReport()['context'], ['type' => 1, 'command' => 1]));
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_queued_job_counts_for_itself_and_is_reported_by_its_name(): void
    {
        $job = new SyncJob($this->app, (string) json_encode(['displayName' => 'App\Jobs\SendReport', 'job' => 'x']), 'sync', 'default');

        event(new JobProcessing('sync', $job));
        OrderList::customers(12);
        event(new JobProcessed('sync', $job));

        $this->assertCount(1, $this->reports());
        $this->assertSame(12, $this->lastReport()['query']['count']);
        $this->assertSame(['type' => 'queue', 'job' => 'App\Jobs\SendReport'], array_intersect_key($this->lastReport()['context'], ['type' => 1, 'job' => 1]));
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_query_reports_do_not_use_up_what_exceptions_may_send(): void
    {
        foreach (range(1, 8) as $table) {
            self::runQuery("select * from table_{$table}", [], 900);
        }
        $this->app->terminate();
        $this->assertCount(5, $this->reports());

        $this->report(new \RuntimeException('Still reported'));
        $this->assertSame('error', $this->lastReport()['type']);
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_transport_of_your_own_gets_the_query_reports_too(): void
    {
        $taken = [];
        Beacon::sendUsing(function (array $report) use (&$taken): void {
            $taken[] = $report['type'];
        });

        $this->get('report');
        $this->app->terminate();

        $this->assertSame(['query'], $taken);
        $this->assertSame([], $this->reports());
    }

    #[DefineEnvironment('watchingQueries')]
    public function test_a_cache_that_is_broken_reports_no_queries_and_breaks_nothing(): void
    {
        Cache::shouldReceive('add', 'has')->andThrow(new \RuntimeException('Cache is down'));

        $this->get('report')->assertOk();
        $this->app->terminate();

        $this->assertSame([], $this->reports());
    }

    #[DataProvider('queries')]
    public function test_a_query_is_known_by_its_text_without_what_differs_per_call(string $sql, string $normalized): void
    {
        $this->assertSame($normalized, QueryWatcher::normalize($sql));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function queries(): array
    {
        return [
            'placeholders stay' => ['select * from `users` where `id` = ?', 'select * from `users` where `id` = ?'],
            'a list of any length' => ['select * from `users` where `id` in (?, ?, ?,?)', 'select * from `users` where `id` in (?)'],
            'rows of an insert' => ['insert into `log` (`a`, `b`) values (?, ?), (?, ?), (?, ?)', 'insert into `log` (`a`, `b`) values (?), ...'],
            'a number written into it' => ['select * from orders where total > 12.50 limit 10 offset 20', 'select * from orders where total > ? limit ? offset ?'],
            'a text written into it' => ["select * from users where name = 'O''Brien' and city = 'Den Haag'", 'select * from users where name = ? and city = ?'],
            'a number in a name stays' => ['select `address_2`, t1.col3 from table_2 as t1', 'select `address_2`, t1.col3 from table_2 as t1'],
            'the placeholders of PostgreSQL stay' => ['select * from "users" where "id" = $1 and "age" > $2', 'select * from "users" where "id" = $1 and "age" > $2'],
            'white space' => ["select *\n    from users\n    where id = ?", 'select * from users where id = ?'],
        ];
    }
}
