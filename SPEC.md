# What Beacon sends

This is the contract between the package and a dashboard. Build a dashboard against this file and point `BEACON_URL` at it.

The example below is `tests/Fixtures/error-event.json`. A test checks that the package sends exactly the keys of that file, so the example and the code cannot drift apart.

## The request

| | |
|---|---|
| Method | `POST` |
| URL | `BEACON_URL` |
| `Content-Type` | `application/json` |
| `Accept` | `application/json` |
| `Authorization` | `Bearer <BEACON_TOKEN>`, only when a token is set |

One request is one exception. Nothing is batched and nothing is retried.

## The answer

| Status | What the package does |
|---|---|
| `2xx` | Nothing. The body is not read. |
| `403` | Stays silent for an hour, then tries again with the next exception. |
| `429` | Stays silent for the seconds in the `Retry-After` header: at least a minute, at most an hour. |
| Anything else, a timeout, no connection | Nothing. The report is lost. |

The package never waits longer than `beacon.timeout` seconds (2 by default) and sends at most `beacon.per_minute` reports per minute (20 by default). During a web request the report goes out after the response.

## The report

```json
{
    "v": 1,
    "type": "error",
    "occurred_at": "2026-11-19T10:15:30+00:00",
    "site": {
        "path": "/home/forge/voorbeeld.test",
        "host": "voorbeeld.test",
        "environment": "production",
        "php": "8.4.14",
        "laravel": "13.1.0",
        "beacon": "1.0.0"
    },
    "exception": {
        "class": "ErrorException",
        "message": "Undefined array key \"name\"",
        "code": "0",
        "file": "app/Http/Controllers/OrderController.php",
        "line": 42,
        "previous": [],
        "frames": [
            {
                "file": "app/Http/Controllers/OrderController.php",
                "line": 42,
                "class": "App\\Http\\Controllers\\OrderController",
                "function": "store",
                "in_app": true,
                "source": {
                    "start": 37,
                    "lines": [
                        "    public function store(StoreOrderRequest $request): RedirectResponse",
                        "    {",
                        "        $input = $request->validated();",
                        "",
                        "        $order = Order::create([",
                        "            'customer' => $input['name'],",
                        "            'quantity' => $input['quantity'],",
                        "        ]);",
                        "",
                        "        return to_route('orders.show', $order);",
                        "    }"
                    ]
                }
            },
            {
                "file": "vendor/laravel/framework/src/Illuminate/Routing/Controller.php",
                "line": 54,
                "class": "Illuminate\\Routing\\Controller",
                "function": "callAction",
                "in_app": false,
                "source": null
            },
            {
                "file": "public/index.php",
                "line": 20,
                "class": null,
                "function": "{main}",
                "in_app": true,
                "source": {
                    "start": 15,
                    "lines": [
                        "",
                        "// Bootstrap Laravel and handle the request...",
                        "/** @var Application $app */",
                        "$app = require_once __DIR__.'/../bootstrap/app.php';",
                        "",
                        "$app->handleRequest(Request::capture());"
                    ]
                }
            }
        ]
    },
    "context": {
        "type": "http",
        "method": "POST",
        "url": "https://voorbeeld.test/orders",
        "route": "orders.store",
        "command": null,
        "job": null,
        "user_id": "12"
    },
    "request": {
        "query": {
            "ref": "mail"
        },
        "body": {
            "name": "Jan Voorbeeld",
            "quantity": "2",
            "password": "[Filtered]"
        },
        "headers": {
            "user-agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)",
            "referer": "https://voorbeeld.test/cart",
            "content-type": "application/x-www-form-urlencoded"
        },
        "ip": "203.0.113.0",
        "livewire": null,
        "truncated": false
    }
}
```

Every key is always present. A value the package does not have is `null`. A string longer than its limit is cut by the package before it is sent, so a dashboard may refuse a longer one.

### Top level

| Field | Type | Meaning |
|---|---|---|
| `v` | integer | The version of this format: `1`. |
| `type` | string | What is reported: `error`. A query that stood out is `query`, see further on. |
| `occurred_at` | string | When the exception was reported, ISO 8601 with offset, by the clock of the site. |
| `site` | object | Which site reports. |
| `exception` | object | What was thrown, and from where. |
| `context` | object | What the application was doing. |
| `request` | object or `null` | The request it happened in. `null` in a command or a queued job. |

### `site`

| Field | Type | Limit | Meaning |
|---|---|---|---|
| `path` | string | 1000 | `base_path()`, as it is. On a zero-downtime deploy this is the release directory. |
| `host` | string or `null` | 255 | The host of `APP_URL`. A label, not an identity. |
| `environment` | string | 50 | `APP_ENV`. |
| `php` | string | 30 | `PHP_VERSION`. |
| `laravel` | string | 30 | The version of the framework. |
| `beacon` | string or `null` | 30 | The version of this package. |

There is no key and no site id. A dashboard decides for itself which site this is: by the address the request came from, by `path`, by a token, or by a combination. It should not trust `host` or `path` any further than it trusts the sender.

### `exception`

| Field | Type | Limit | Meaning |
|---|---|---|---|
| `class` | string | 255 | The class of the exception. |
| `message` | string | 2000 | The message, with secrets masked (see below). |
| `code` | string | 50 | The code, as a string: a database driver gives codes like `42S02`. |
| `file` | string or `null` | 1000 | Where it was thrown. The same as `frames[0].file`. |
| `line` | integer | | The line in that file. |
| `previous` | list | 10 items | The chain of `getPrevious()`: objects with `class` (255) and `message` (2000). |
| `frames` | list | 100 items | The stack trace. Never empty. |

A frame:

| Field | Type | Limit | Meaning |
|---|---|---|---|
| `file` | string or `null` | 1000 | Relative to `site.path` when the file is inside the site, otherwise the full path. `null` for a call made by PHP itself. |
| `line` | integer or `null` | | The line in that file. |
| `class` | string or `null` | 255 | The class of the function that was running there. |
| `function` | string or `null` | 255 | The function that was running there. `{main}` for the last frame. |
| `in_app` | boolean | | Whether this is the site's own code: inside the site and not in `vendor/`. |
| `source` | object or `null` | | The code around `line`, for a frame of the site's own code: `start` is the number of the first line, `lines` the lines from there, 5 before and 5 after, each cut at 300 characters. `null` for other frames, for a compiled view, and when the file cannot be read. |

Frames run from where the exception was thrown outwards. Each frame is a place plus the function that was executing at that place, which is not how PHP's `getTrace()` gives them (there each entry is a call plus the place it was called from).

A frame in a compiled Blade view is sent as the Blade file it was compiled from, with the line number of the compiled file. A compiled view that cannot be traced back keeps its own path and gets `in_app: false`: its name is a hash that differs per release.

Since PHP 8.4 a closure is named after its line, such as `{closure:App\Foo::bar():12}`. A dashboard that groups on the function should strip that number.

Only the first 10 frames of the site's own code get their `source`. It is read from the file as it is on the site when the report is made, so after a deploy it can differ from the code that ran. A secret written in the code itself goes along with it.

Arguments of the calls in the trace are never sent.

### `context`

| Field | Type | Limit | Meaning |
|---|---|---|---|
| `type` | string | | `http`, `console` or `queue`. |
| `method` | string or `null` | 10 | The HTTP method. |
| `url` | string or `null` | 2000 | The URL without its query string. |
| `route` | string or `null` | 255 | The name of the route, or its pattern (`orders/{order}`) when it has none. |
| `command` | string or `null` | 500 | The name of the Artisan command that is running. Never its arguments. |
| `job` | string or `null` | 255 | The class of the queued job that is running. |
| `user_id` | string or `null` | 100 | The id of the signed-in user, as a string. Nothing else of the user. |

`method`, `url` and `route` are `null` unless `type` is `http`.

### `request`

| Field | Type | Meaning |
|---|---|---|
| `query` | object or `null` | The query string, scrubbed. `null` when empty or switched off. |
| `body` | object or `null` | The posted fields (form or JSON), scrubbed. `null` when empty, switched off, or for a Livewire update. |
| `headers` | object or `null` | The headers on the list below, lower case. `null` when none or switched off. |
| `ip` | string or `null` | The address of the visitor, as `BEACON_REQUEST_IP` says. |
| `livewire` | object or `null` | For a Livewire update: `components`, a list of `name`, `calls` (method names) and `updates` (property names). Never the values or the snapshot. |
| `truncated` | boolean | Whether parts were left out because the whole was over 4 KB as JSON. The body goes first, then the query and the Livewire part. |

## The report of a query

Only sent by a site that has `BEACON_QUERIES` on. It goes to the same URL and gets the same answers as the report of an exception, with `type` set to `query`. A dashboard that does not want them answers `422`.

```json
{
    "v": 1,
    "type": "query",
    "occurred_at": "2026-11-20T10:15:30+00:00",
    "site": {
        "path": "/home/forge/voorbeeld.test",
        "host": "voorbeeld.test",
        "environment": "production",
        "php": "8.4.14",
        "laravel": "13.1.0",
        "beacon": "1.0.0"
    },
    "query": {
        "kind": "repeated",
        "sql": "select * from `customers` where `customers`.`id` = ? limit ?",
        "connection": "mysql",
        "duration_ms": 41.3,
        "count": 25,
        "file": "app/Http/Controllers/OrderController.php",
        "line": 27,
        "frames": [
            {
                "file": "app/Http/Controllers/OrderController.php",
                "line": 27,
                "class": "App\\Http\\Controllers\\OrderController",
                "function": "index",
                "in_app": true,
                "source": {
                    "start": 22,
                    "lines": [
                        "    public function index(): View",
                        "    {",
                        "        $orders = Order::latest()->limit(25)->get();",
                        "",
                        "        foreach ($orders as $order) {",
                        "            $names[] = $order->customer->name;",
                        "        }",
                        "",
                        "        return view('orders.index', compact('orders', 'names'));",
                        "    }",
                        "}"
                    ]
                }
            },
            {
                "file": "vendor/laravel/framework/src/Illuminate/Routing/Controller.php",
                "line": 54,
                "class": "Illuminate\\Routing\\Controller",
                "function": "callAction",
                "in_app": false,
                "source": null
            },
            {
                "file": "public/index.php",
                "line": 20,
                "class": null,
                "function": "{main}",
                "in_app": true,
                "source": {
                    "start": 15,
                    "lines": [
                        "",
                        "// Bootstrap Laravel and handle the request...",
                        "/** @var Application $app */",
                        "$app = require_once __DIR__.'/../bootstrap/app.php';",
                        "",
                        "$app->handleRequest(Request::capture());"
                    ]
                }
            }
        ]
    },
    "context": {
        "type": "http",
        "method": "GET",
        "url": "https://voorbeeld.test/orders",
        "route": "orders.index",
        "command": null,
        "job": null,
        "user_id": "12"
    }
}
```

`v`, `occurred_at`, `site` and `context` are those of the report above. There is no `request`: a query that stands out is about the code, not about one visitor.

### `query`

| Field | Type | Limit | Meaning |
|---|---|---|---|
| `kind` | string | | `slow`: one query took at least `beacon.queries.slow_ms` (200 by default). `repeated`: the same query ran at least `beacon.queries.repeats` times (5 by default) in one request or queued job, and those runs took at least `beacon.queries.repeats_ms` together (50 by default). |
| `sql` | string | 2000 | The query with its placeholders, made the same for every call (see below). Never the values. |
| `connection` | string or `null` | 100 | The name of the database connection. |
| `duration_ms` | number | | `slow`: how long the query took, in milliseconds. `repeated`: how long all its runs took together. |
| `count` | integer | | `slow`: `1`. `repeated`: how often it ran in that request or job. |
| `file` | string or `null` | 1000 | Where the query was asked for. The same as `frames[0].file`. |
| `line` | integer or `null` | | The line in that file. |
| `frames` | list | 50 items | The stack trace, with the frames of the report above. Never empty. |

The trace starts at the first frame in the site's own code: before it, it is the database layer every time. A query that no code of the site asked for (a package in a middleware) keeps its whole trace. For a `repeated` query the trace is that of the run that made it too many, so it points into the loop.

The text of a query is made the same for every call before it is counted and sent:

- a quoted text or a number that was written into the query becomes `?`, so a value in a raw query stays on the site too;
- `in (?, ?, ?)` becomes `in (?)` and `values (?, ?), (?, ?)` becomes `values (?), ...`, whatever their length;
- white space becomes one space.

What the package limits itself:

- The same query (by `kind` and `sql`) is reported once per `beacon.queries.every` seconds, an hour by default. A dashboard therefore counts reports, not runs.
- At most `beacon.queries.per_minute` query reports per minute (5 by default), apart from the number for exceptions.
- A command does not report `repeated` queries: the same query a thousand times is what an import is for. It does report `slow` ones.
- A `repeated` query is sent when the request or the job ends, a `slow` one like an exception: after the response, or at once in a command or a job.

## What never leaves the site

Scrubbing happens in the package, before anything is sent. A dashboard does well to apply the same rules again, for a site that runs an older version.

- **Secret fields.** A field of the query or the body whose name contains `password`, `passwd`, `apiuser`, `token`, `secret`, `key`, `signature`, `iban`, `card`, `cvc`, `cvv`, `authorization` or `cookie` is sent as `[Filtered]`, at any depth and whatever its case. `BEACON_FILTERED_FIELDS` adds names to that list.
- **Long values.** A string value is cut at 200 characters, a level holds at most 50 fields, and nesting stops at 5 levels (`[Nested]`).
- **Uploads.** A file is sent as `[File]` under the name of its field. Not its name, not its contents.
- **Headers.** Only `user-agent`, `referer`, `accept`, `accept-language`, `content-type`, `x-requested-with` and `x-livewire`, each cut at 500 characters. `Cookie`, `Authorization` and every other header are never sent, whatever the configuration.
- **Addresses.** By default the address of the visitor has its last part set to zero: an IPv4 address keeps its first three numbers, an IPv6 address its first 48 bits.
- **Messages.** In an exception message and in a header value, `name=value` is sent as `name=[Filtered]` when the name contains one of the secret field names: a URL with a token in it often ends up in a message.
- **Queries.** A `QueryException` is sent with its SQL and placeholders, not with the values that were bound. The report of a query has its placeholders too, and a value that was written into the query itself is replaced by one.
- **Never, whatever is configured:** cookies, the session, the contents of a file, arguments of calls in the stack trace, arguments of a command, the name or e-mail address of the user.

Two things are not caught, because they cannot be told apart from what a developer needs:

- An address or a name that the code itself puts in an exception message.
- A value in the message of a database driver, such as the duplicate entry of a unique index.

## What is a dashboard's own work

- Deciding who may send, and which site a report belongs to.
- Grouping reports into issues. The package sends no fingerprint.
- Limiting how much it keeps.

## Versions

`v` is raised when a field is removed, renamed or changes meaning. A new field, or a new value of `type`, does not raise it: a dashboard should ignore fields it does not know, and answer a `type` it does not know with a `422`, which the package ignores.
