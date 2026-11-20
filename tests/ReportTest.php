<?php

namespace NickDeKruijk\Beacon\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use LogicException;
use PDOException;
use RuntimeException;
use Throwable;

class ReportTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete([base_path('app/Thrower.php'), base_path('vendor/acme/Thrower.php'), resource_path('views/broken.blade.php')]);
        File::deleteDirectory(base_path('vendor/acme'));

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        Route::post('orders/{order}', fn () => throw new RuntimeException('Boom'))->name('orders.store');
    }

    /**
     * The spec and the code describe the same report: every key of the example is sent, and
     * nothing is sent that the example does not have.
     */
    public function test_a_report_has_the_shape_of_the_example_in_the_spec(): void
    {
        $example = json_decode((string) file_get_contents(__DIR__.'/Fixtures/error-event.json'), true);

        $this->post('orders/31?ref=mail', ['quantity' => '2'], ['User-Agent' => 'Mozilla/5.0']);
        $report = $this->lastReport();

        $this->assertSame(array_keys($example), array_keys($report));

        foreach (['site', 'exception', 'context', 'request'] as $part) {
            $this->assertSame(array_keys($example[$part]), array_keys($report[$part]), $part);
        }

        $this->assertSame(array_keys($example['exception']['frames'][0]), array_keys($report['exception']['frames'][0]));
        $this->assertSame($example['v'], $report['v']);
        $this->assertSame($example['type'], $report['type']);
    }

    public function test_the_example_in_the_spec_is_the_one_the_tests_use(): void
    {
        preg_match('/```json\n(.+?)\n```/s', (string) file_get_contents(__DIR__.'/../SPEC.md'), $matches);

        $this->assertSame(
            json_decode((string) file_get_contents(__DIR__.'/Fixtures/error-event.json'), true),
            json_decode($matches[1] ?? '', true),
        );
    }

    public function test_it_says_which_site_reports(): void
    {
        $site = $this->report(new RuntimeException('Boom'))['site'];

        $this->assertSame(base_path(), $site['path']);
        $this->assertSame('voorbeeld.test', $site['host']);
        $this->assertSame('production', $site['environment']);
        $this->assertSame(PHP_VERSION, $site['php']);
        $this->assertSame(app()->version(), $site['laravel']);
    }

    public function test_it_says_what_was_thrown(): void
    {
        $exception = $this->report(new RuntimeException('Boom', 42, new LogicException('The cause')))['exception'];

        $this->assertSame(RuntimeException::class, $exception['class']);
        $this->assertSame('Boom', $exception['message']);
        $this->assertSame('42', $exception['code']);
        $this->assertSame(__FILE__, $exception['file']);
        $this->assertSame([['class' => LogicException::class, 'message' => 'The cause']], $exception['previous']);
    }

    public function test_the_frames_read_from_the_throw_outwards(): void
    {
        $frames = $this->report($this->thrownBy('app/Thrower.php'))['exception']['frames'];

        $this->assertSame('app/Thrower.php', $frames[0]['file']);
        $this->assertSame(4, $frames[0]['line']);
        $this->assertStringStartsWith('{closure', (string) $frames[0]['function']);
        $this->assertStringNotContainsString(base_path(), (string) $frames[0]['function'], 'A closure is named after its full path');
        $this->assertTrue($frames[0]['in_app']);
        $this->assertSame(__FILE__, $frames[1]['file']);
        $this->assertSame('thrownBy', $frames[1]['function']);
        $this->assertSame('{main}', $frames[array_key_last($frames)]['function']);
    }

    public function test_code_in_vendor_and_outside_the_site_is_not_the_site_its_own(): void
    {
        $frames = $this->report($this->thrownBy('vendor/acme/Thrower.php'))['exception']['frames'];

        $this->assertSame('vendor/acme/Thrower.php', $frames[0]['file']);
        $this->assertFalse($frames[0]['in_app']);
        $this->assertFalse($frames[1]['in_app'], 'This test file is outside the site');
    }

    public function test_a_compiled_view_is_reported_as_its_blade_file(): void
    {
        File::put(resource_path('views/broken.blade.php'), "<div>\n{{ \$missing->name }}\n</div>\n");

        try {
            view('broken')->render();
            $this->fail('The view rendered');
        } catch (Throwable $exception) {
            $report = $this->report($exception);
        }

        $this->assertSame('resources/views/broken.blade.php', $report['exception']['file']);
        $this->assertSame('resources/views/broken.blade.php', $report['exception']['frames'][0]['file']);
        $this->assertTrue($report['exception']['frames'][0]['in_app']);
    }

    public function test_the_arguments_of_a_call_never_leave_the_site(): void
    {
        $report = $this->report($this->failWith('a-password-in-an-argument'));

        $this->assertStringNotContainsString('a-password-in-an-argument', (string) json_encode($report));
    }

    public function test_a_query_exception_is_sent_without_the_values_of_its_query(): void
    {
        $exception = new QueryException(
            'mysql',
            'select * from users where email = ? and name = ?',
            ['jan@voorbeeld.test', 'Jan Voorbeeld'],
            new PDOException('SQLSTATE[42S02]: Base table or view not found'),
        );

        $message = $this->report($exception)['exception']['message'];

        $this->assertSame('SQLSTATE[42S02]: Base table or view not found (Connection: mysql, SQL: select * from users where email = ? and name = ?)', $message);
    }

    public function test_a_secret_in_a_message_is_masked(): void
    {
        $report = $this->report(new RuntimeException('GET https://api.voorbeeld.test/?apipassword=hunter2 failed', 0, new RuntimeException('token=abc123 was refused')));

        $this->assertSame('GET https://api.voorbeeld.test/?apipassword=[Filtered] failed', $report['exception']['message']);
        $this->assertSame('token=[Filtered] was refused', $report['exception']['previous'][0]['message']);
    }

    public function test_a_long_message_and_a_deep_trace_are_cut_to_what_the_spec_allows(): void
    {
        $report = $this->report($this->thrownAtDepth(150, str_repeat('a', 5000)));

        $this->assertSame(2000, strlen($report['exception']['message']));
        $this->assertCount(100, $report['exception']['frames']);
    }

    /**
     * An exception thrown from a file inside the site, by a class that lives there.
     */
    private function thrownBy(string $file): Throwable
    {
        File::ensureDirectoryExists(dirname(base_path($file)));
        // The throw is on line 4.
        File::put(base_path($file), "<?php\n\nreturn function (): never {\n    throw new RuntimeException('Thrown inside the site');\n};\n");

        try {
            (require base_path($file))();
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    private function failWith(string $secret): Throwable
    {
        try {
            $this->explodeWith($secret);
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    private function explodeWith(string $secret): never
    {
        throw new RuntimeException('Boom');
    }

    private function thrownAtDepth(int $depth, string $message): Throwable
    {
        try {
            $this->descend($depth, $message);
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    private function descend(int $depth, string $message): never
    {
        $depth > 0 ? $this->descend($depth - 1, $message) : throw new RuntimeException($message);
    }
}
