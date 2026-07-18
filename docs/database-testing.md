# Database testing

Every worker is a separate process and therefore a separate database connection. This is required to exercise real transactions and locks, but it changes normal Laravel test setup.

## Do not wrap a race in a parent transaction

Records created inside `RefreshDatabase`/`DatabaseTransactions` may remain uncommitted in the parent connection. Workers cannot see them. RaceProof rejects a run when the active connection has an open transaction and recommends migrations plus explicit cleanup instead.

## Use a disposable database

Create a dedicated database such as `my_app_raceproof_test`. For CI and sensitive applications, make the allowlist mandatory:

```dotenv
APP_ENV=testing
RACEPROOF_ENABLED=true
RACEPROOF_REQUIRE_DATABASE_ALLOWLIST=true
RACEPROOF_ALLOWED_DATABASES=my_app_raceproof_test
```

Database-name checks are a final guard, not a replacement for isolated credentials with minimum privileges.

## Parallel test suites

If PHPUnit/Pest itself runs in parallel, give each outer test process a distinct database. All RaceProof workers belonging to that outer process must receive the same database name. Environment-based database configuration naturally crosses the worker boundary; runtime-only `config()->set()` calls do not.

## SQLite

SQLite `:memory:` cannot be shared and is rejected. A file database can smoke-test the package mechanics, as the repository integration suite does, but its locking and concurrency behavior is not representative of MySQL or PostgreSQL. Product claims and application regression tests should use the production database engine.

## What to assert

HTTP status distributions are useful but insufficient. Always assert the final invariant as well:

```php
$result->assertInvariant(
    fn () => Product::query()->findOrFail($id)->stock === 0
        && Order::query()->where('product_id', $id)->count() === 1,
    'Exactly one item must be sold.',
);
```

## Repository evidence matrix

The `Database` test suite runs against disposable MySQL 8.4 and PostgreSQL 17 services in CI. It applies a real isolated migration only after `DatabaseSafety` verifies that `RACEPROOF_ALLOWED_DATABASES` contains exactly the connected database. The suite covers:

| Scenario | Broken behavior forced at the checkpoint | Fixed invariant |
| --- | --- | --- |
| Oversell | both workers authorize stock `1` | one order, stock `0` |
| Coupon | both workers authorize one remaining use | one redemption |
| Wallet | both workers authorize the same balance | ledger total matches the debit |
| Quote | both workers accept one pending quote | one acceptance |
| Unique claim | check-then-insert creates duplicates | database uniqueness plus `insertOrIgnore` |
| Lock misuse | `lockForUpdate` outside a transaction loses an update | ordered transactional lock preserves both updates |
| Deadlock | opposite lock order aborts one operation | consistent lock order commits both |
| Lock timeout | the wait budget is shorter than lock ownership | a bounded, sufficient wait commits both |

The six business invariants are also combined into one critical evidence race. CI repeats its broken and fixed forms 100 times per engine and uploads the JSON result as `database-evidence-mysql` and `database-evidence-pgsql`. This is repeatability evidence for the controlled scenarios, not a claim that arbitrary schedules are proven safe.

## Run locally with Docker Compose

Start either or both disposable services:

```bash
docker compose up -d mysql postgres
```

MySQL uses host port `33060`:

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=33060 \
DB_DATABASE=raceproof_test \
DB_USERNAME=root \
DB_PASSWORD=raceproof \
RACEPROOF_REQUIRE_DATABASE_ALLOWLIST=true \
RACEPROOF_ALLOWED_DATABASES=raceproof_test \
composer test:database
```

PostgreSQL uses host port `54320`; use `DB_CONNECTION=pgsql` and `DB_USERNAME=postgres`. Set `RACEPROOF_EVIDENCE_ITERATIONS=100` to reproduce the release-level evidence run. The default is one iteration for fast local feedback.

The Compose services use temporary filesystems, so `docker compose down` discards their data.

## Engine-specific behavior

- MySQL's `innodb_lock_wait_timeout` is measured in whole seconds and applies to row-lock waits. The evidence fixture uses one second for the failing case.
- PostgreSQL's `lock_timeout` supports millisecond precision and is set transaction-locally so it cannot leak to later operations.
- Both engines choose a deadlock victim, but SQLSTATE and message text differ. Tests assert the portable contract—one conflict response and one committed operation—not vendor-specific wording.
- `insertOrIgnore` maps to engine-specific conflict handling. Keep the unique index as the invariant boundary; an application-level existence check is not sufficient.
- Always acquire multiple locks in a stable order. Increasing a timeout does not repair a deadlock cycle.

## Troubleshooting

`could not find driver`
: Enable `pdo_mysql` or `pdo_pgsql`. CI installs both explicitly.

`Database [...] is not in RACEPROOF_ALLOWED_DATABASES`
: Confirm `DB_DATABASE` and the single allowlisted name match exactly. Do not weaken this guard to make a test pass.

Workers cannot connect but the parent can
: Worker processes inherit environment variables, not parent-only `config()->set()` values. Put all `DB_*` values in the environment before calling `run()`.

A deadlock scenario hangs until RaceProof times out
: Confirm both tables use InnoDB on MySQL, no outer transaction is active, and the database is not behind a transaction-pooling proxy.

PostgreSQL reports a missing `public` schema
: Use a disposable database owned by the test role, or set a writable isolated search path in the fixture connection.

Ports `33060` or `54320` are already in use
: Stop the conflicting service or run the evidence against an existing disposable database with explicit `DB_*` variables.
