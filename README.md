# Laravel Beacon

Reports the exceptions of a Laravel site to a dashboard of your own, instead of to a third party. The site needs no key and no code: the dashboard knows it by where the report comes from.

**Without configuration it reports to the dashboard of Amphora, which only accepts its own servers.** Anywhere else, set `BEACON_URL` to your own dashboard first. Until you do, every report gets a 403 and the package stays silent for an hour after each one.

It is auto-discovered, so installing it is all you need to do:

```
composer require nickdekruijk/laravel-beacon
```

It needs Laravel 12 or later.

## What it does

It hooks into Laravel's exception handler. Whatever Laravel reports, Beacon posts as JSON: no 404s, no validation errors, nothing from `dontReport()`. The exception is still logged as before, and other reporters still get it.

- **Only in production.** Other environments report nothing.
- **After the response.** During a web request the report goes out when the response is already on its way. A command or a queued job sends at once.
- **Never in the way.** A dashboard that is slow, down or says no costs at most two seconds of a PHP worker and never an exception of its own.
- **At most 20 a minute.** A site in a loop throws the same exception thousands of times. The rest is dropped.

### What it sends

The class and message of the exception, the stack trace with paths relative to the site, what the application was doing (the route, the command or the queued job, the id of the user) and the request it happened in.

The request is scrubbed before it leaves the site:

| Part | What is sent |
|---|---|
| Query string and body | The fields, with anything that looks like a secret (`password`, `token`, `secret`, `key`, `iban`, `card` and the like) replaced by `[Filtered]`. Values are cut at 200 characters. |
| Uploads | The name of the field only. |
| Headers | `User-Agent`, `Referer`, `Accept`, `Accept-Language`, `Content-Type`, `X-Requested-With` and `X-Livewire`. |
| Address of the visitor | With the last part set to zero. |
| Livewire update | The component, the methods called and the names of the properties that changed. |

Cookies, the `Authorization` header, the session, file contents and the arguments of calls in the stack trace are never sent, whatever you configure. A `QueryException` is sent with its placeholders, not with the values.

[SPEC.md](SPEC.md) has the exact format, with an example.

### Slow and repeated queries

Off by default. With `BEACON_QUERIES=true` the site also reports:

- a query that takes 200 ms or more;
- a query that runs 5 times or more in one request or queued job, which is nearly always a relation loaded inside a loop (an N+1). Only when those runs took 50 ms or more together: what costs nothing is not reported.

The report has the query with its placeholders, never its values, how long it took or how often it ran, and the place in your code that asked for it. Each query is reported at most once an hour, and at most 5 query reports leave the site per minute, apart from the exceptions.

Watching costs next to nothing: a query is counted by its text, and the stack trace is only taken for a query that is going to be reported. Switch it on for one site first to see how much it finds.

## Configuration

Everything works through `.env`. Publish the config file only if you want to change the environments or the limits:

```
php artisan vendor:publish --tag=beacon-config
```

| Variable | Default | |
|---|---|---|
| `BEACON_ENABLED` | `true` | Master switch. |
| `BEACON_URL` | the dashboard of Amphora | Where the reports go. |
| `BEACON_TOKEN` | empty | Sent as a bearer token when set, for a dashboard that wants one. |
| `BEACON_REQUEST_QUERY` | `true` | Send the query string. |
| `BEACON_REQUEST_BODY` | `true` | Send the body. |
| `BEACON_REQUEST_HEADERS` | `true` | Send the headers on the list. |
| `BEACON_REQUEST_IP` | `anonymized` | `anonymized`, `full` or `none`. Anything else counts as `anonymized`. |
| `BEACON_FILTERED_FIELDS` | empty | Extra field names to mask, comma separated: `bsn,geboortedatum`. |
| `BEACON_QUERIES` | `false` | Report slow and repeated queries. |
| `BEACON_SLOW_QUERY_MS` | `200` | A query is slow from this many milliseconds. |
| `BEACON_QUERY_REPEATS` | `5` | A query is repeated from this many runs in one request or job. |
| `BEACON_QUERY_REPEATS_MS` | `50` | A repeated query is reported from this many milliseconds for all its runs together. |

## Your own dashboard

A dashboard is one endpoint that takes a `POST` with JSON and answers `2xx`. [SPEC.md](SPEC.md) describes what it receives and what the package does with each answer.

The package sends no key by default, so a dashboard has to decide for itself who may report: by the address of the sender, or by the token you set in `BEACON_TOKEN`.

An application that is its own dashboard should not post to itself to say it is broken. Give it a transport instead, in a service provider:

```php
use NickDeKruijk\Beacon\Beacon;

Beacon::sendUsing(function (array $report): void {
    // Store the report yourself. This runs at the moment of the exception.
});
```

## What it does not do

- No performance tracing (how long each request takes) and no JavaScript errors.
- No grouping: the dashboard decides which reports are the same issue.
- No retries. A report that does not arrive is gone; the exception is still in `laravel.log`.
- It does not catch what happens before Laravel has booted, such as a syntax error in a config file.

## Tests

```
composer install
vendor/bin/phpunit
```

## License

MIT, see [LICENSE.md](LICENSE.md).
