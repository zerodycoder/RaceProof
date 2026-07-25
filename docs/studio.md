# RaceProof Studio

RaceProof Studio is an optional local evidence viewer. Tests still define the
request, participants, authentication, checkpoints, and assertions in committed
PHP. Studio makes the resulting concurrency evidence easier to understand; it
does not create a second execution model.

![RaceProof Studio showing a passed three-participant run and its checkpoint execution lanes](assets/raceproof-studio.png)

## Enable Studio

Studio is disabled by default. Enable it only in a local or testing
environment:

```dotenv
RACEPROOF_STUDIO_ENABLED=true
```

Publish the package configuration if it is not already present:

```bash
php artisan vendor:publish --tag=raceproof-config
```

Run the application's normal local development server and visit `/raceproof`.
The UI is server-rendered, includes its own bounded CSS, and requires no
Node/Vite setup or published frontend assets.

The base route is configurable:

```php
'studio' => [
    'enabled' => (bool) env('RACEPROOF_STUDIO_ENABLED', false),
    'route_prefix' => 'raceproof',
    'allowed_ips' => ['127.0.0.1', '::1'],
],
```

The prefix must be one path-safe segment. Studio routes are registered only
when the feature is enabled and `APP_ENV` is exactly `local` or `testing`.
Requests are additionally checked against the direct peer address, not
forwarded headers. Add a Docker bridge or other development address to
`allowed_ips` only when it is known and required.

## Code-first workflow

Generate a PHPUnit test:

```bash
php artisan make:race-test InventoryOversell /api/checkout --participants=3
```

Or generate Pest syntax:

```bash
php artisan make:race-test InventoryOversell /api/checkout --participants=3 --pest
```

The generator requires a real URI, validates the participant range, refuses
path traversal, and will not replace a file without `--force`. It produces
infrastructure, timeout, and server-error assertions rather than a fake passing
assertion. Add the domain invariant that defines correctness for the
application.

Run the generated test through the project's normal runner:

```bash
php artisan test --filter=InventoryOversellTest
```

Studio never accepts an arbitrary URI, token, cookie, or payload in its web
interface. This preserves Git history, code review, CI reproduction, and the
database assertions that distinguish a real fix from a visually successful
request.

## CLI

List retained reports:

```bash
php artisan raceproof:reports
```

Inspect one run:

```bash
php artisan raceproof:reports 5df8e3efb8eb49d19e26a6423f7d57e7
```

Print its archive JSON:

```bash
php artisan raceproof:reports 5df8e3efb8eb49d19e26a6423f7d57e7 --json
```

Print the dashboard URL:

```bash
php artisan raceproof:studio
php artisan raceproof:studio 5df8e3efb8eb49d19e26a6423f7d57e7
```

`raceproof:studio` does not start or control the application's server. It uses
the configured `app.url` and prints the route to open.

Remove coordinator scratch runs while preserving Studio history:

```bash
php artisan raceproof:clean
```

Remove both coordinator runs and validated Studio JSON files:

```bash
php artisan raceproof:clean --studio
```

The Studio cleanup deletes only files named with a validated 32-character run
ID and `.json`; unrelated files in the configured directory are left alone.

## What Studio shows

- run outcome, duration, start spread, and completed/expected participants;
- one execution lane for the parent plus one lane per participant;
- bounded lifecycle, checkpoint-reached, and checkpoint-release events;
- participant status, duration, outcome, exception class, and diagnostic;
- bounded response bodies and captured response headers;
- coordination summary and timeline parsing warnings;
- a downloadable copy of the validated archived JSON.

Studio shows the shared report model's protocol outcome, not the final
Pest/PHPUnit verdict. A business response such as HTTP 409 is displayed as
`http_failure` even when the test correctly expects one or more 409 responses.
Committed assertions and invariants remain authoritative.

The timeline uses host-local monotonic timestamps. Horizontal positions explain
relative ordering within one run; they are not wall-clock timestamps and must
not be compared across machines.

## Archive contract and retention

When Studio is enabled, the orchestrator archives a schema-versioned report
before successful coordinator scratch evidence is cleaned. Failed runs can
therefore have both:

- the normal coordinator artifact directory for detailed diagnosis; and
- the smaller Studio report for the local UI.

Defaults:

```php
'studio' => [
    'path' => storage_path('framework/raceproof-studio'),
    'max_reports' => 50,
    'max_report_bytes' => 1_048_576,
],
```

Writes use a temporary file in the archive directory followed by an atomic
rename. The directory and files are permissioned to `0700` and `0600` where
the platform supports those modes. Run IDs are validated before path
construction. Reports beyond the configured count are pruned oldest first.
Malformed, unsupported, empty, and oversized files are ignored rather than
rendered.

Archive JSON has a small envelope:

```json
{
  "archive_schema": 1,
  "captured_at": "2026-07-25T18:00:00+00:00",
  "report": {
    "schema_version": 1
  }
}
```

The nested report is the same model described in [evidence
reporters](reporters.md). Absolute coordinator artifact paths are removed from
the archived projection.

## Security

Studio is a development diagnostic surface, not an administration panel:

- production route registration is refused;
- archive reads and writes are independently refused in production;
- the direct client address must be in the explicit Studio allowlist;
- Content Security Policy blocks scripts, frames, forms, remote assets, and
  external content;
- download and HTML responses set no-store, `nosniff`, `DENY`, and no-referrer
  headers;
- request plans, bearer tokens, cookies, session credentials, and bootstrap
  configuration are never copied into Studio reports;
- configured credential keys, authorization/cookie headers, bearer tokens,
  diagnostics, response bodies, and timeline data are bounded and redacted
  through the shared report factory.

Pattern redaction cannot identify arbitrary personally identifiable or
application-specific data. Do not expose the Studio route on a shared host,
commit its storage directory, or upload the archive as a public CI artifact.
Keep using disposable least-privilege credentials in concurrency tests.

CI normally needs JSON/JUnit [reporters](reporters.md), not Studio. Leave Studio
disabled unless a short-lived diagnostic job explicitly requires its bounded
archive.
