# RaceProof for Laravel

**Controlled and reproducible concurrency testing for Laravel.**

RaceProof starts independent Laravel processes against the same database, holds them at explicit barriers, and returns one result that can assert response distributions and database invariants. It is a test tool—not a load tester, a lock library, an automatic race detector, or a formal proof that no race exists.

> Current status: technical MVP. The local kernel runner, file coordinator, start barrier, `RacePoint`, crash/timeout collection, JSON results, database safety checks, and a deterministic overselling demonstration are implemented.

## The five-minute example

Application code with a deliberately unsafe read/write gap:

```php
$product = Product::query()->findOrFail($id);

RacePoint::sync('stock-read');

if ($product->stock < 1) {
    abort(409);
}

$product->decrement('stock');
Order::query()->create(['product_id' => $product->id]);
```

Test:

```php
use function RaceProof\Laravel\race;

$result = race()
    ->participants(10)
    ->postJson('/api/checkout', ['product_id' => $product->id])
    ->releaseWhenAllReach('stock-read')
    ->run();

$result
    ->assertAllFinished()
    ->assertNoWorkerFailures()
    ->assertStatusCount(201, 1)
    ->assertStatusCount(409, 9)
    ->assertNoServerErrors()
    ->assertInvariant(
        fn () => Order::query()->count() === 1,
        'Only one order may be created.',
    );
```

Every participant boots the real application and sends its request through Laravel's HTTP kernel. They share the application's configured database and coordinator directory.

## Installation

```bash
composer require raceproof/laravel --dev
php artisan vendor:publish --tag=raceproof-config
php artisan raceproof:doctor
```

There is one important production-safety choice:

- If RaceProof is only used to start requests together, installing with `--dev` is correct.
- If application code directly calls the `RacePoint` facade, the class must exist in production. Install the package as a normal dependency; it is a no-op outside an active worker and all commands refuse production execution.
- A dev-only alternative is `function_exists('race_point') && race_point('stock-read');`. The guard is required because dev dependencies are absent in production.

This trade-off is explicit; a service provider cannot make a missing dev-only class safe.

## Supported MVP surface

- PHP 8.2+
- Laravel 12 and 13
- Linux and WSL are the primary targets
- macOS should work through Symfony Process
- Native Windows is currently validated as an experimental target
- MySQL/MariaDB and PostgreSQL are the intended databases
- SQLite in-memory is rejected; SQLite files are useful only for package smoke tests and do not model production lock behavior

Implemented builder methods:

```php
race()
    ->participants(5)
    ->postJson('/api/endpoint', ['id' => 1])
    ->withHeaders(['X-Tenant' => 'acme'])
    ->withCookies(['locale' => 'en'])
    ->withToken($token)
    ->actingAs($user, 'web')
    ->startTogether()
    ->releaseWhenAllReach('after-read')
    ->run();
```

Implemented assertions and queries:

```php
$result->assertAllFinished();
$result->assertNoWorkerFailures();
$result->assertNoServerErrors();
$result->assertStatusCount(201, 1);
$result->assertExactlySuccessful(1);
$result->assertStartSpreadBelow(10);
$result->assertNoTimeouts();
$result->assertInvariant(fn () => true, 'Invariant failed.');

$result->successful();
$result->failed();
$result->statuses();
$result->participant('p2');
$result->startSpreadMs();
$result->durationMs();
json_encode($result);
```

## Safety model

RaceProof:

- always refuses `production`;
- is disabled unless explicitly enabled;
- requires `testing` unless `RACEPROOF_ALLOW_NON_TESTING=1` is set for an isolated local environment;
- rejects open parent database transactions;
- rejects SQLite in-memory;
- can require a database-name allowlist with `RACEPROOF_REQUIRE_DATABASE_ALLOWLIST=true` and `RACEPROOF_ALLOWED_DATABASES=raceproof_test`;
- caps captured response bodies and only records allowlisted headers;
- uses JSON only—no closure serialization or untrusted `unserialize()`;
- uses path-safe run, participant, and checkpoint identifiers;
- retains artifacts when a worker crashes or the run times out.

For payment, inventory, or booking suites, use a dedicated disposable database and enable the database allowlist in CI.

## Process boundary

Runtime changes made in the parent test process do not automatically exist in workers. This includes container mocks, runtime routes, `Queue::fake()`, `Mail::fake()`, `Http::fake()`, and config values changed only in memory. Persist shared setup in the database or configure it through environment/application files loaded by every worker.

Factories are fine when they commit records before `run()`. Transaction-based test traits are not: child processes cannot see the parent's uncommitted rows.

## What “reproducible” means

The start barrier coordinates entry into the request. `RacePoint` creates an explicit rendezvous inside application code. The operating system and database still choose execution order after a checkpoint is released. RaceProof therefore does not claim exact schedule replay or use a misleading seed in the MVP; controlled schedules belong to a later interleaving engine.

## Commands

```bash
php artisan raceproof:doctor
php artisan raceproof:clean
```

`raceproof:worker` is an internal hidden command.

## Development

```bash
composer install
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The integration suite includes a real three-process Laravel checkpoint test and a broken/fixed overselling scenario. The latter forces three workers to read stock `1`; the broken implementation produces three orders and stock `-2`, while the atomic fix produces one order, stock `0`, one `201`, and two `409` responses.

See [architecture](docs/architecture.md), [database testing](docs/database-testing.md), and [production safety](docs/production-safety.md) for the operational details.

## Near-term roadmap

1. Harden the technical MVP on Linux CI with MySQL and PostgreSQL.
2. Add participant bootstrap classes for process-local setup without closure serialization.
3. Improve retained timelines and console failure reports.
4. Validate authentication adapters for session, Sanctum, and token guards.
5. Publish four complete broken/fixed demonstrations before stabilizing the API.

Redis coordination, network mode, queues, schedule fuzzing, exact interleaving control, and dashboards are deliberately outside the first release.

## License

MIT
