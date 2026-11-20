# Changelog

All notable changes to `nickdekruijk/laravel-beacon` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-20

### Added

- Reports every exception Laravel reports to a dashboard, as JSON, in the configured environments. Auto-discovered: no key and no code in the site.
- The stack trace with paths relative to the site, a compiled view as its Blade file, and the route, command or queued job the exception happened in.
- Request data, scrubbed before it is sent: secret fields masked, values cut, uploads by field name only, a fixed list of headers, the address of the visitor anonymized. Each part has a switch in `.env`.
- `BEACON_URL` and `BEACON_TOKEN` for a dashboard of your own, and `SPEC.md` with the format of a report.
- Sends after the response, at most 20 reports a minute, stays silent for an hour after a 403 and for as long as `Retry-After` says after a 429.
- `Beacon::sendUsing()` for an application that is its own dashboard.
- With `BEACON_QUERIES=true`: reports a slow query and a query that runs many times in one request or queued job (an N+1), with its placeholders and the place in the code that asked for it. Off by default.
