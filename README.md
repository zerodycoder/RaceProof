# RaceProof for Laravel

**Controlled and reproducible concurrency testing for Laravel.**

RaceProof starts independent Laravel processes against the same database, holds them at explicit barriers, and returns one result that can assert response distributions and database invariants. It is a test tool—not a load tester, a lock library, an automatic race detector, or a formal proof that no race exists.

> Current status: the v1 API candidate is frozen and release tooling is implemented on `main`, but no package has been published. The local kernel runner, file coordinator, start barrier, `RacePoint`, per-participant request/auth/bootstrap specs, versioned event timelines, human/JSON/JUnit reports, crash/timeout collection, database safety checks, deterministic database demonstrations, public-API guard, and release dry-run are implemented.

## The five-minute example

The canonical, copy-ready broken/fixed walkthrough is the
[tested five-minute guide](docs/five-minute-guide.md). The short form follows.

Application code after replacing the stale read/write gap with an atomic claim:

```php
race_point('stock-claim');

$created = DB::transaction(function () use ($id): bool {
    $claimed = Product::query()
        ->whereKey($id)
        ->where('stock', '>', 0)
        ->decrement('stock');

    if ($claimed === 0) {
        return false;
    }

    Order::query()->create(['product_id' => $id]);

    return true;
});

if (! $created) {
    abort(409);
}
```

Test:

```php
use function RaceProof\Laravel\race;

$result = race()
    ->participants(10)
    ->postJson('/api/checkout', ['product_id' => $id])
    ->releaseWhenAllReach('stock-claim')
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

No Packagist release exists yet. The commands below are the tagged-release
installation contract and become resolvable only after the first published beta;
contributors should use a source checkout in the meantime.

If application code contains checkpoints, install the production-safe runtime directly and keep the orchestrator dev-only:

```bash
composer require raceproof/runtime:^0.1
composer require raceproof/laravel --dev
php artisan vendor:publish --tag=raceproof-config
php artisan raceproof:doctor
```

If application code has no checkpoints, omit `raceproof/runtime` and install only the main dev package. Runtime checkpoint calls are no-ops unless a validated worker activates an in-memory handler; the runtime ships no Laravel integration, process runner, commands, filesystem access, or network behavior.

See [runtime checkpoint deployment](docs/runtime-checkpoints.md) for migration from the old facade/guarded-helper models.

## Supported MVP surface

- PHP 8.2+
- Laravel 12 and 13
- Ubuntu Linux is continuously verified; WSL2 is a primary development target
- macOS is best-effort compatible but is not continuously verified
- Native Windows is experimental and has maintainer smoke evidence, not CI parity
- MySQL 8.4 and PostgreSQL 17 are continuously verified; compatible MySQL/PostgreSQL releases are expected to work
- SQLite in-memory is rejected; SQLite files are useful only for package smoke tests and do not model production lock behavior

Implemented builder methods:

```php
use RaceProof\Laravel\ParticipantBuilder;

race()
    ->participants(5)
    ->postJson('/api/endpoint', ['id' => 1])
    ->withHeaders(['X-Tenant' => 'acme'])
    ->withCookies(['locale' => 'en'])
    ->withToken($token)
    ->actingAs($user, 'web')
    ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
        ->withPayload(['id' => 2])
        ->withHeaders(['X-Tenant' => 'north'])
        ->withToken($participantToken)
        ->actingAs($participantUser)
        ->withBootstrap(CheckoutParticipantBootstrap::class, ['tenant' => 'north']))
    ->withBootstrap(CheckoutParticipantBootstrap::class, ['tenant' => 'acme'])
    ->startTogether()
    ->releaseWhenAllReach('after-read')
    ->run();
```

Participant overrides are validated before orchestration and cross the process boundary only as JSON. See [per-participant requests and authentication](docs/participant-specs.md) for merge semantics plus session, token, Sanctum, identity, and credential-handling guidance.

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
$result->failureReport();
$result->report(app(HumanReporter::class));
$result->report(app(JsonReporter::class));
$result->report(app(JUnitReporter::class));
json_encode($result);
```

All three reporters share a versioned, redacted report model. See [evidence reporters](docs/reporters.md) for CLI/CI examples, the JSON v1 contract, JUnit outcome mapping, and output bounds.

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
- records a versioned JSONL timeline and redacts bounded diagnostic text before persistence.

For payment, inventory, or booking suites, use a dedicated disposable database and enable the database allowlist in CI.

## Process boundary

Runtime changes made in the parent test process do not automatically exist in workers. This includes container mocks, runtime routes, `Queue::fake()`, `Mail::fake()`, `Http::fake()`, and config values changed only in memory. Persist shared setup in the database or configure it through environment/application files loaded by every worker.

Factories are fine when they commit records before `run()`. Transaction-based test traits are not: child processes cannot see the parent's uncommitted rows. Use a [participant bootstrap](docs/participant-bootstrap.md) for trusted class-based environment, config, auth, or process-local setup.

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
composer test:pest
vendor/bin/pint --test
vendor/bin/phpstan analyse

# Real engine evidence (after starting compose services)
composer test:database
```

The integration suite includes a real three-process PHPUnit checkpoint test, the same workflow written in Pest, and a broken/fixed overselling scenario. The latter forces three workers to read stock `1`; the broken implementation produces three orders and stock `-2`, while the atomic fix produces one order, stock `0`, one `201`, and two `409` responses.

The database suite runs isolated migrations with an exact database-name allowlist and proves broken/fixed behavior for overselling, coupons, wallets, quotes, uniqueness, lock misuse, deadlocks, and lock timeouts. CI also produces 100/100 machine-readable critical evidence for both MySQL and PostgreSQL.

Four published broken/fixed demonstrations use the executable routes exercised by that database suite:

- [overselling](examples/overselling/README.md);
- [coupon redemption](examples/coupon-redemption/README.md);
- [wallet debit](examples/wallet-debit/README.md);
- [quote acceptance](examples/quote-acceptance/README.md).

See [PHPUnit and Pest workflows](docs/testing-workflows.md), the [public API contract](docs/public-api.md), [versioning policy](docs/versioning.md), [upgrade guide](UPGRADING.md), [release runbook](docs/releasing.md), [pre-release audit](docs/release-audit.md), [compatibility policy](docs/compatibility.md), [maintenance policy](docs/maintenance.md), [known limitations](docs/known-limitations.md), the [private-beta runbook](docs/private-beta.md), [current beta evidence](docs/beta-evidence.md), the [platform support matrix](docs/platform-support.md), the [troubleshooting decision guide](docs/troubleshooting.md), [architecture](docs/architecture.md), [participant authentication](docs/participant-specs.md), [participant bootstrap](docs/participant-bootstrap.md), [evidence reporters](docs/reporters.md), [runtime deployment](docs/runtime-checkpoints.md), [timeline evidence](docs/timeline.md), [database testing](docs/database-testing.md), and [production safety](docs/production-safety.md).

## Near-term roadmap

1. Provision the public split repository and Packagist records, then exercise the
   fail-closed release workflow on the beta.
2. Run the evidence-backed beta before declaring a stable release.

Redis coordination, network mode, queues, schedule fuzzing, exact interleaving control, and dashboards are deliberately outside the first release.

## Project standards

Changes are developed as focused pull requests and merged only after review and green CI. The enforced checks include the supported PHP/Laravel matrix, PHPUnit, a real-process Pest contract, Pint, PHPStan level max, Composer validation/audit, a 90% line-coverage floor, both real databases, the artifact dry-run, and the dependent pre-release audit.

- [Roadmap](ROADMAP.md)
- [Quality policy](docs/quality.md)
- [Release checklist](docs/releasing.md)
- [Pre-release audit](docs/release-audit.md)
- [Known limitations](docs/known-limitations.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Support](SUPPORT.md)

## License

MIT
