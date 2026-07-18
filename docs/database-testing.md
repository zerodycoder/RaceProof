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
