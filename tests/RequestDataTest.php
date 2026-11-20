<?php

namespace NickDeKruijk\Beacon\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class RequestDataTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::any('orders/{order}', fn () => throw new RuntimeException('Boom'))->name('orders.store');
        Route::any('unnamed/{thing}', fn () => throw new RuntimeException('Boom'));
        Route::post('livewire/update', fn () => throw new RuntimeException('Boom'));
    }

    public function test_it_says_which_request_went_wrong_without_its_query_string_in_the_url(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 12]));

        $context = $this->post('orders/31?ref=mail&token=abc')->baseResponse->getStatusCode() === 500
            ? $this->lastReport()['context']
            : $this->fail('The route did not fail');

        $this->assertSame([
            'type' => 'http',
            'method' => 'POST',
            'url' => 'http://localhost/orders/31',
            'route' => 'orders.store',
            'command' => null,
            'job' => null,
            'user_id' => '12',
        ], $context);
    }

    public function test_a_route_without_a_name_is_reported_by_its_pattern_and_a_guest_without_a_user(): void
    {
        $this->get('unnamed/31');

        $context = $this->lastReport()['context'];

        $this->assertSame('unnamed/{thing}', $context['route']);
        $this->assertNull($context['user_id']);
    }

    public function test_the_query_and_the_body_are_sent_with_their_secrets_masked(): void
    {
        $this->post('orders/31?ref=mail&api_token=abc', [
            'name' => 'Jan Voorbeeld',
            'password' => 'hunter2',
            'payment' => ['iban' => 'NL00BANK0123456789', 'amount' => '12.50'],
            'notes' => str_repeat('a', 500),
        ]);

        $request = $this->lastReport()['request'];

        $this->assertSame(['ref' => 'mail', 'api_token' => '[Filtered]'], $request['query']);
        $this->assertSame('Jan Voorbeeld', $request['body']['name']);
        $this->assertSame('[Filtered]', $request['body']['password']);
        $this->assertSame(['iban' => '[Filtered]', 'amount' => '12.50'], $request['body']['payment']);
        $this->assertSame(200, strlen($request['body']['notes']));
        $this->assertFalse($request['truncated']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($this->lastReport()));
    }

    public function test_a_json_body_is_sent_the_same_way(): void
    {
        $this->postJson('orders/31', ['quantity' => 2, 'client_secret' => 'xyz']);

        $this->assertSame(['quantity' => 2, 'client_secret' => '[Filtered]'], $this->lastReport()['request']['body']);
    }

    public function test_of_an_upload_only_the_field_is_sent(): void
    {
        $this->post('orders/31', ['title' => 'Scan', 'attachment' => UploadedFile::fake()->createWithContent('scan.txt', 'the contents of the file')]);

        $report = $this->lastReport();

        $this->assertSame(['title' => 'Scan', 'attachment' => '[File]'], $report['request']['body']);
        $this->assertStringNotContainsString('the contents of the file', (string) json_encode($report));
        $this->assertStringNotContainsString('scan.txt', (string) json_encode($report));
    }

    public function test_only_the_headers_on_the_list_are_sent_and_never_a_cookie_or_authorization(): void
    {
        $this->withUnencryptedCookie('session', 'a-session-id')->post('orders/31', [], [
            'User-Agent' => 'Mozilla/5.0',
            'Referer' => 'https://voorbeeld.test/cart?token=abc',
            'Accept-Language' => 'nl-NL',
            'Authorization' => 'Bearer a-bearer-token',
            'Cookie' => 'other=a-raw-cookie',
            'X-Forwarded-For' => '198.51.100.1',
            'X-Api-Key' => 'an-api-key',
        ]);

        $report = $this->lastReport();
        $headers = $report['request']['headers'];

        $this->assertSame('Mozilla/5.0', $headers['user-agent']);
        $this->assertSame('https://voorbeeld.test/cart?token=[Filtered]', $headers['referer']);
        $this->assertSame('nl-NL', $headers['accept-language']);
        $this->assertSame([], array_diff(array_keys($headers), ['user-agent', 'referer', 'accept', 'accept-language', 'content-type', 'x-requested-with', 'x-livewire']));

        foreach (['a-session-id', 'a-bearer-token', 'a-raw-cookie', '198.51.100.1', 'an-api-key'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) json_encode($report));
        }
    }

    #[DataProvider('addresses')]
    public function test_the_address_of_the_visitor_is_sent_as_the_setting_says(mixed $setting, string $ip, ?string $expected): void
    {
        config(['beacon.request.ip' => $setting]);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('orders/31');

        $this->assertSame($expected, $this->lastReport()['request']['ip']);
    }

    /**
     * @return array<string, array{mixed, string, ?string}>
     */
    public static function addresses(): array
    {
        return [
            'anonymized by default' => ['anonymized', '203.0.113.57', '203.0.113.0'],
            'anonymized IPv6' => ['anonymized', '2001:db8:1234:5678::9', '2001:db8:1234::'],
            'full' => ['full', '203.0.113.57', '203.0.113.57'],
            'none' => ['none', '203.0.113.57', null],
            'a setting that is not known' => ['yes please', '203.0.113.57', '203.0.113.0'],
            'a setting that is true' => [true, '203.0.113.57', '203.0.113.0'],
        ];
    }

    #[DataProvider('switches')]
    public function test_a_part_that_is_switched_off_is_not_sent(string $part, mixed $off): void
    {
        config(['beacon.request.'.$part => $off]);

        $this->post('orders/31?ref=mail', ['name' => 'Jan'], ['User-Agent' => 'Mozilla/5.0']);

        $request = $this->lastReport()['request'];

        foreach (['query', 'body', 'headers'] as $other) {
            $other === $part ? $this->assertNull($request[$other]) : $this->assertNotNull($request[$other], $other);
        }
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function switches(): array
    {
        return [
            'query' => ['query', false],
            'body' => ['body', false],
            'headers' => ['headers', false],
            'off as an env value reads' => ['body', 'off'],
            'zero as an env value reads' => ['query', '0'],
        ];
    }

    public function test_extra_field_names_of_the_site_are_masked(): void
    {
        config(['beacon.request.filtered_fields' => 'bsn, geboortedatum']);

        $this->post('orders/31', ['bsn' => '123456782', 'Geboortedatum' => '1980-01-01', 'naam' => 'Jan']);

        $this->assertSame(['bsn' => '[Filtered]', 'Geboortedatum' => '[Filtered]', 'naam' => 'Jan'], $this->lastReport()['request']['body']);
    }

    public function test_of_a_livewire_update_only_the_names_are_sent(): void
    {
        $this->postJson('livewire/update', [
            '_token' => 'a-csrf-token',
            'components' => [[
                'snapshot' => json_encode(['data' => ['email' => 'jan@voorbeeld.test'], 'memo' => ['name' => 'checkout-form'], 'checksum' => 'abc']),
                'updates' => ['form.email' => 'piet@voorbeeld.test', 'coupon' => 'ZOMER'],
                'calls' => [['path' => '', 'method' => 'pay', 'params' => ['a-param-value']]],
            ]],
        ], ['X-Livewire' => 'true']);

        $report = $this->lastReport();

        $this->assertNull($report['request']['body']);
        $this->assertSame(['components' => [['name' => 'checkout-form', 'calls' => ['pay'], 'updates' => ['form.email', 'coupon']]]], $report['request']['livewire']);

        foreach (['jan@voorbeeld.test', 'piet@voorbeeld.test', 'ZOMER', 'a-param-value', 'a-csrf-token'] as $value) {
            $this->assertStringNotContainsString($value, (string) json_encode($report));
        }
    }

    public function test_a_request_that_is_too_large_loses_its_body_and_says_so(): void
    {
        $this->post('orders/31?ref=mail', collect(range(1, 40))->mapWithKeys(fn (int $i) => ['field_'.$i => str_repeat('a', 200)])->all());

        $request = $this->lastReport()['request'];

        $this->assertTrue($request['truncated']);
        $this->assertNull($request['body']);
        $this->assertSame(['ref' => 'mail'], $request['query']);
    }
}
