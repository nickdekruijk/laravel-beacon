<?php

namespace NickDeKruijk\Beacon\Tests;

use NickDeKruijk\Beacon\Scrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class ScrubberTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_an_address_is_sent_as_the_mode_says(?string $ip, string $mode, ?string $expected): void
    {
        $this->assertSame($expected, (new Scrubber)->ip($ip, $mode));
    }

    /**
     * @return array<string, array{?string, string, ?string}>
     */
    public static function addresses(): array
    {
        return [
            'IPv4 loses its last number' => ['203.0.113.57', 'anonymized', '203.0.113.0'],
            'IPv6 keeps its first 48 bits' => ['2001:db8:1234:5678:9abc:def0:1234:5678', 'anonymized', '2001:db8:1234::'],
            'a short IPv6 address' => ['::1', 'anonymized', '::'],
            'whole when asked' => ['203.0.113.57', 'full', '203.0.113.57'],
            'not at all when asked' => ['203.0.113.57', 'none', null],
            'a mode that is not known counts as anonymized' => ['203.0.113.57', 'everything', '203.0.113.0'],
            'an empty mode counts as anonymized' => ['203.0.113.57', '', '203.0.113.0'],
            'no address' => [null, 'full', null],
            'something that is no address' => ['not-an-ip', 'full', null],
        ];
    }

    public function test_fields_that_look_like_a_secret_are_masked_at_every_depth(): void
    {
        $scrubbed = (new Scrubber)->fields([
            'email' => 'jan@voorbeeld.test',
            'password' => 'hunter2',
            'Password_Confirmation' => 'hunter2',
            'api_token' => 'abc',
            'card' => ['number' => '4111111111111111', 'holder' => 'Jan'],
            'profile' => ['city' => 'Utrecht', 'client_secret' => 'xyz', 'bank' => ['IBAN' => 'NL00BANK0123456789']],
            0 => 'a list item',
        ]);

        $this->assertSame([
            'email' => 'jan@voorbeeld.test',
            'password' => '[Filtered]',
            'Password_Confirmation' => '[Filtered]',
            'api_token' => '[Filtered]',
            'card' => '[Filtered]',
            'profile' => ['city' => 'Utrecht', 'client_secret' => '[Filtered]', 'bank' => ['IBAN' => '[Filtered]']],
            0 => 'a list item',
        ], $scrubbed);
    }

    public function test_field_names_of_the_site_are_masked_too(): void
    {
        $scrubbed = (new Scrubber([' bsn', 'Geboortedatum ', '']))->fields(['bsn' => '123456782', 'geboortedatum' => '1980-01-01', 'naam' => 'Jan']);

        $this->assertSame(['bsn' => '[Filtered]', 'geboortedatum' => '[Filtered]', 'naam' => 'Jan'], $scrubbed);
    }

    public function test_long_values_deep_nesting_and_long_lists_are_cut(): void
    {
        $scrubbed = (new Scrubber)->fields([
            'notes' => str_repeat('é', 250),
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]],
            'object' => new stdClass,
            'count' => 3,
            'none' => null,
            'many' => range(1, 60),
        ]);

        $this->assertSame(200, mb_strlen($scrubbed['notes']));
        $this->assertSame('[Nested]', $scrubbed['deep']['a']['b']['c']['d']['e']);
        $this->assertSame('[stdClass]', $scrubbed['object']);
        $this->assertSame(3, $scrubbed['count']);
        $this->assertNull($scrubbed['none']);
        $this->assertCount(51, $scrubbed['many']);
        $this->assertSame('10 more', $scrubbed['many']['...']);
    }

    public function test_a_secret_in_a_message_is_masked_and_the_message_is_cut(): void
    {
        $scrubber = new Scrubber(['bsn']);

        $this->assertSame(
            'cURL error 28 for https://api.voorbeeld.test/v1?apiuser=[Filtered]&apipassword=[Filtered]&page=2 and bsn=[Filtered]',
            $scrubber->message('cURL error 28 for https://api.voorbeeld.test/v1?apiuser=jan&apipassword=hunter2&page=2 and bsn=123456782', 2000),
        );
        $this->assertSame('Undefined array key "password"', $scrubber->message('Undefined array key "password"', 2000));
        $this->assertSame(10, mb_strlen($scrubber->message(str_repeat('a', 50), 10)));
    }
}
