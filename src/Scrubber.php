<?php

namespace NickDeKruijk\Beacon;

/**
 * Takes out of a report what must not leave the site: the value of anything that looks like a
 * secret, the tail of a long value, and the last part of a visitor's address.
 */
class Scrubber
{
    public const FILTERED = '[Filtered]';

    /**
     * A field is masked when its name contains one of these. Wide on purpose: "key" also masks
     * "keyword", and a masked search term costs less than a leaked API key.
     *
     * @var list<string>
     */
    public const FIELDS = ['password', 'passwd', 'apiuser', 'token', 'secret', 'key', 'signature', 'iban', 'card', 'cvc', 'cvv', 'authorization', 'cookie'];

    /**
     * The only request headers that are ever sent. A list of what is allowed, not of what is
     * refused: a header nobody thought of stays home.
     *
     * @var list<string>
     */
    public const HEADERS = ['user-agent', 'referer', 'accept', 'accept-language', 'content-type', 'x-requested-with', 'x-livewire'];

    private const VALUE_LIMIT = 200;

    private const ITEM_LIMIT = 50;

    private const DEPTH_LIMIT = 5;

    /**
     * @param  list<string>  $extraFields  Field names to mask on top of the built-in ones.
     */
    public function __construct(private array $extraFields = []) {}

    /**
     * The fields of a query string or a body, with secrets masked, strings cut and anything
     * that is not plain data replaced by its type.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function fields(array $values, int $depth = 0): array
    {
        $scrubbed = [];

        foreach (array_slice($values, 0, self::ITEM_LIMIT, true) as $name => $value) {
            $scrubbed[$name] = match (true) {
                is_string($name) && $this->isSecret($name) => self::FILTERED,
                is_array($value) => $depth < self::DEPTH_LIMIT ? $this->fields($value, $depth + 1) : '[Nested]',
                is_string($value) => $this->cut($value, self::VALUE_LIMIT),
                is_scalar($value), $value === null => $value,
                default => '['.get_debug_type($value).']',
            };
        }

        if (count($values) > self::ITEM_LIMIT) {
            $scrubbed['...'] = count($values) - self::ITEM_LIMIT.' more';
        }

        return $scrubbed;
    }

    /**
     * An exception message with the value of a secret parameter masked, as in a URL that ended
     * up in the message: "...?token=abc" becomes "...?token=[Filtered]".
     */
    public function message(string $message, int $limit): string
    {
        $names = implode('|', array_map(fn (string $field) => preg_quote($field, '/'), $this->fieldNames()));

        return $this->cut(preg_replace('/\b([\w-]*(?:'.$names.')[\w-]*)=[^&\s"\']*/i', '$1='.self::FILTERED, $message) ?? $message, $limit);
    }

    /**
     * The address of a visitor as the site wants it sent: with the last part set to zero (an
     * IPv4 address keeps its first three numbers, an IPv6 address its first 48 bits), whole, or
     * not at all. A mode that is not known counts as anonymized, never as whole.
     */
    public function ip(?string $ip, string $mode): ?string
    {
        if ($ip === null || $mode === 'none') {
            return null;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if ($mode === 'full') {
            return $ip;
        }

        $kept = strlen($packed) === 4 ? 3 : 6;

        return inet_ntop(substr($packed, 0, $kept).str_repeat("\0", strlen($packed) - $kept)) ?: null;
    }

    public function cut(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private function isSecret(string $name): bool
    {
        $name = strtolower($name);

        foreach ($this->fieldNames() as $field) {
            if (str_contains($name, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function fieldNames(): array
    {
        return array_values(array_filter(array_map(
            fn (string $field) => strtolower(trim($field)),
            [...self::FIELDS, ...$this->extraFields],
        )));
    }
}
