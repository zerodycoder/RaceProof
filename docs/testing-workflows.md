# PHPUnit and Pest workflows

RaceProof has no test-runner-specific API. The same race body works in PHPUnit
and Pest because both boot Laravel and call the package normally.

## Shared setup rules

- commit migrations and seed rows before `run()`;
- use a disposable database with the exact allowlist enabled;
- do not use transaction-based reset traits around a multi-process race;
- put database configuration in environment/application files visible to child
  processes, not parent-only `config()->set()` calls;
- assert response distribution and the final database invariant.

## PHPUnit

Generate a safe starting point with a real URI and no placeholder passing
assertion:

```bash
php artisan make:race-test OversellingRace /api/oversell/fixed --participants=2
```

```php
<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OversellingRaceTest extends TestCase
{
    public function test_only_one_order_claims_the_last_unit(): void
    {
        $result = race()
            ->participants(2)
            ->postJson('/api/oversell/fixed')
            ->releaseWhenAllReach('oversell-claim')
            ->run();

        $result
            ->assertAllFinished()
            ->assertNoWorkerFailures()
            ->assertNoTimeouts()
            ->assertStatusCount(201, 1)
            ->assertStatusCount(409, 1)
            ->assertInvariant(
                fn () => DB::table('orders')->count() === 1,
                'Exactly one order may be created.',
            );
    }
}
```

```bash
vendor/bin/phpunit --filter OversellingRaceTest
```

## Pest

Install the Pest major compatible with the application's PHP version. RaceProof
itself does not require Pest. This repository uses Pest 3 because its supported
matrix includes PHP 8.2 and PHPUnit 11. The generator fails without writing a
test file when Pest is unavailable and prints the Composer command needed to
install it.

```bash
php artisan make:race-test OversellingRace /api/oversell/fixed --participants=2 --pest
```

```php
<?php

use Illuminate\Support\Facades\DB;

it('prevents overselling', function (): void {
    $result = race()
        ->participants(2)
        ->postJson('/api/oversell/fixed')
        ->releaseWhenAllReach('oversell-claim')
        ->run();

    $result
        ->assertAllFinished()
        ->assertNoWorkerFailures()
        ->assertNoTimeouts()
        ->assertStatusCount(201, 1)
        ->assertStatusCount(409, 1);

    expect(DB::table('orders')->count())->toBe(1);
});
```

```bash
vendor/bin/pest --filter "prevents overselling"
```

The repository command `composer test:pest` proves the Pest workflow by driving
three real Laravel worker processes through a checkpoint. `composer check`
runs both the PHPUnit suite and that Pest contract on every supported
PHP/Laravel CI combination.

## Outer parallelism

When PHPUnit or Pest runs test files in parallel, assign a different disposable
database to each outer process. RaceProof workers for one test must share that
outer process's database. Never let two outer tests migrate or truncate the same
database concurrently.
