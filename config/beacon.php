<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. Set BEACON_ENABLED=false to stop reporting, for example on
    | a site that is being taken apart.
    |
    */

    'enabled' => env('BEACON_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Exceptions are only reported in these environments. A bug you make while
    | developing is on your screen already.
    |
    */

    'environments' => ['production'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Where the exceptions go. The default is the dashboard of Amphora, which
    | only takes reports from its own servers: anyone else gets a 403, after
    | which this package stays silent for an hour. Point BEACON_URL at your own
    | dashboard; SPEC.md says what it receives.
    |
    | The token is optional. When set it is sent as a bearer token, for a
    | dashboard that does not know its senders by their address.
    |
    */

    'url' => env('BEACON_URL', 'https://mijn.amphora.nl/api/v1/beacon/errors'),

    'token' => env('BEACON_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds, for connecting and for the whole request. A report
    | goes out after the response, so a slow dashboard does not slow a visitor,
    | but it does keep a PHP worker busy.
    |
    | At most this many reports leave the site per minute. The rest is dropped:
    | a site in a loop throws the same exception thousands of times.
    |
    */

    'timeout' => 2,

    'per_minute' => 20,

    /*
    |--------------------------------------------------------------------------
    | Request data
    |--------------------------------------------------------------------------
    |
    | What is sent along of the request the exception happened in. Each part can
    | be switched off. Passwords, tokens and the like are masked, long values
    | are cut, and cookies and the Authorization header are never sent, whatever
    | is set here.
    |
    | The address of the visitor is "anonymized" (the last part set to zero),
    | "full" or "none". Anything else counts as "anonymized".
    |
    */

    'request' => [
        'query' => env('BEACON_REQUEST_QUERY', true),
        'body' => env('BEACON_REQUEST_BODY', true),
        'headers' => env('BEACON_REQUEST_HEADERS', true),
        'ip' => env('BEACON_REQUEST_IP', 'anonymized'),

        // Extra field names to mask, on top of the built-in list: comma separated.
        'filtered_fields' => env('BEACON_FILTERED_FIELDS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    |
    | Off by default. Set BEACON_QUERIES=true to have the site report a query
    | that takes at least this many milliseconds, and a query that runs at
    | least this many times in one request or queued job (an N+1). A repeated
    | query is only worth a report when its runs took this many milliseconds
    | together: six runs of a third of a millisecond cost nobody anything. The
    | query is sent with its placeholders, never with its values.
    |
    | The same query is reported once per "every" seconds, and at most this
    | many query reports leave the site per minute, apart from the exceptions.
    |
    */

    'queries' => [
        'enabled' => env('BEACON_QUERIES', false),
        'slow_ms' => env('BEACON_SLOW_QUERY_MS', 200),
        'repeats' => env('BEACON_QUERY_REPEATS', 5),
        'repeats_ms' => env('BEACON_QUERY_REPEATS_MS', 50),
        'every' => 3600,
        'per_minute' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy reports
    |--------------------------------------------------------------------------
    |
    | Browsers post their CSP reports to this path on the site itself, and the
    | site sends them on to the dashboard. Point the policy at it with
    | Beacon::cspReportUri(), which is null where nothing is reported.
    |
    | Reports of browser extensions and of pages that are not of this site are
    | left out. The same violation on the same page is sent once per "every"
    | seconds, with the number of times it was seen since, and at most this many
    | CSP reports leave the site per minute, apart from the exceptions.
    |
    */

    'csp' => [
        'enabled' => env('BEACON_CSP', true),
        'path' => 'beacon/csp',
        'every' => 3600,
        'per_minute' => 10,
    ],

];
