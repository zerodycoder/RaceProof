# Five-minute race test

This guide turns one unsafe inventory endpoint into a deterministic broken/fixed
regression test. Its endpoint is the published
[overselling example](../examples/overselling/routes.php), executed by the
regular integration suite and by MySQL/PostgreSQL CI.

## 1. Install and enable

No Packagist release exists yet. The commands below describe the post-beta
contract; contributors currently run the workflow from a source checkout.

Keep the orchestrator out of production dependencies. Install the tiny runtime
only when application code contains `race_point()` calls:

```bash
composer require raceproof/runtime:^0.1
composer require raceproof/laravel --dev
php artisan vendor:publish --tag=raceproof-config
```

Use a disposable test database:

```dotenv
APP_ENV=testing
RACEPROOF_ENABLED=true
RACEPROOF_REQUIRE_DATABASE_ALLOWLIST=true
RACEPROOF_ALLOWED_DATABASES=my_app_raceproof_test
```

Run `php artisan raceproof:doctor` before debugging a test failure.

## 2. Expose the stale read

Put a checkpoint after the unsafe read and before the decision/write:

```php
$id = 1; // Replace with the route/controller input in your application.
$stock = (int) DB::table('products')->where('id', $id)->value('stock');

race_point('oversell-read');

if ($stock < 1) {
    abort(409);
}

DB::table('products')->where('id', $id)->decrement('stock');
DB::table('orders')->insert(['product_id' => $id]);
```

## 3. Prove the broken behavior

Seed committed data before `run()`; do not wrap this test in
`RefreshDatabase` or `DatabaseTransactions`.

```php
DB::table('orders')->delete();
DB::table('products')->where('id', 1)->delete();
DB::table('products')->insert(['id' => 1, 'stock' => 1]);

$result = race()
    ->participants(2)
    ->postJson('/api/oversell/broken')
    ->releaseWhenAllReach('oversell-read')
    ->run();

$result
    ->assertAllFinished()
    ->assertNoTimeouts()
    ->assertNoWorkerFailures()
    ->assertStatusCount(201, 2)
    ->assertInvariant(
        fn () => DB::table('orders')->count() === 2
            && (int) DB::table('products')->where('id', 1)->value('stock') === -1,
        'The controlled schedule must expose the oversell.',
    );
```

This is a passing reproduction of a bug, not the desired product behavior.

## 4. Make the claim atomic

Replace the stale decision with one conditional write:

```php
$id = 1; // Replace with the route/controller input in your application.
$created = DB::transaction(function () use ($id): bool {
    $claimed = DB::table('products')
        ->where('id', $id)
        ->where('stock', '>', 0)
        ->decrement('stock');

    if ($claimed === 0) {
        return false;
    }

    DB::table('orders')->insert(['product_id' => $id]);

    return true;
});

if (! $created) {
    abort(409);
}
```

The transaction keeps the successful claim and its order side effect atomic.
Reset the same committed seed data, keep the checkpoint immediately before the
claim, and change the regression expectations:

```php
$result = race()
    ->participants(2)
    ->postJson('/api/oversell/fixed')
    ->releaseWhenAllReach('oversell-claim')
    ->run();

$result
    ->assertAllFinished()
    ->assertStatusCount(201, 1)
    ->assertStatusCount(409, 1)
    ->assertInvariant(
        fn () => DB::table('orders')->count() === 1
            && (int) DB::table('products')->where('id', 1)->value('stock') === 0,
        'Exactly one order may claim the final unit.',
    );
```

## 5. Run and diagnose

The test body works inside PHPUnit or Pest:

```bash
vendor/bin/phpunit --filter OversellingRaceTest
vendor/bin/pest --filter "prevents overselling"
```

On failure, append `$result->failureReport()` to the assertion message or emit a
[human, JSON, or JUnit report](reporters.md). Retained artifacts are evidence;
do not publish them without reviewing captured bodies and paths for sensitive
application data.
