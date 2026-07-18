<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RaceProof\Laravel\Support\DatabaseSafety;

$iterations = 1;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--iterations=')) {
        $iterations = (int) substr($argument, strlen('--iterations='));
    }
}

if ($iterations < 1 || $iterations > 100) {
    throw new InvalidArgumentException('Evidence iterations must be between 1 and 100.');
}

foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_USERNAME', 'RACEPROOF_ALLOWED_DATABASES'] as $required) {
    if (getenv($required) === false || trim((string) getenv($required)) === '') {
        throw new RuntimeException("The {$required} environment variable is required.");
    }
}

if (getenv('RACEPROOF_REQUIRE_DATABASE_ALLOWLIST') !== 'true') {
    throw new RuntimeException('RACEPROOF_REQUIRE_DATABASE_ALLOWLIST must be exactly true.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'RACEPROOF_ENABLED' => 'true',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$engine = $connection->getDriverName();
$database = (string) $connection->getDatabaseName();
$allowed = array_values(array_filter(array_map('trim', explode(',', (string) getenv('RACEPROOF_ALLOWED_DATABASES')))));

if (! in_array($engine, ['mysql', 'pgsql'], true)) {
    throw new RuntimeException("Database evidence only supports mysql or pgsql; received {$engine}.");
}

if ($allowed !== [$database]) {
    throw new RuntimeException('Evidence requires a one-name allowlist matching the disposable database exactly.');
}

$app->make(DatabaseSafety::class)->validate();

$migrationStatus = Artisan::call('migrate:fresh', [
    '--database' => $engine,
    '--path' => __DIR__.'/database/migrations',
    '--realpath' => true,
    '--force' => true,
]);

if ($migrationStatus !== 0) {
    throw new RuntimeException('The isolated evidence migration failed: '.Artisan::output());
}

/** @return array<int, int> */
function evidenceRun(string $uri, string $checkpoint): array
{
    $result = race()
        ->participants(2)
        ->postJson($uri)
        ->releaseWhenAllReach($checkpoint)
        ->run();

    $result->assertAllFinished()
        ->assertNoTimeouts()
        ->assertNoWorkerFailures();

    return $result->statuses();
}

/** @param array<int, int> $actual @param array<int, int> $expected */
function evidenceStatuses(array $actual, array $expected, string $scenario): void
{
    ksort($actual);
    ksort($expected);

    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s returned %s; expected %s.',
            $scenario,
            json_encode($actual, JSON_THROW_ON_ERROR),
            json_encode($expected, JSON_THROW_ON_ERROR),
        ));
    }
}

function evidenceAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function resetCommerceState(): void
{
    DB::table('orders')->delete();
    DB::table('products')->delete();
    DB::table('products')->insert(['id' => 1, 'stock' => 1]);

    DB::table('redemptions')->delete();
    DB::table('coupons')->delete();
    DB::table('coupons')->insert(['id' => 1, 'remaining_uses' => 1]);

    DB::table('ledger_entries')->delete();
    DB::table('wallets')->delete();
    DB::table('wallets')->insert(['id' => 1, 'balance' => 100]);

    DB::table('acceptances')->delete();
    DB::table('quotes')->delete();
    DB::table('quotes')->insert(['id' => 1, 'status' => 'pending']);

    DB::table('claims_broken')->delete();
    DB::table('claims_fixed')->delete();

    DB::table('lock_counters')->delete();
    DB::table('lock_counters')->insert(['id' => 1, 'version' => 0]);
}

/** @return array<string, int|string> */
function commerceState(bool $fixedClaim): array
{
    return [
        'stock' => (int) DB::table('products')->where('id', 1)->value('stock'),
        'orders' => DB::table('orders')->count(),
        'coupon_uses' => (int) DB::table('coupons')->where('id', 1)->value('remaining_uses'),
        'redemptions' => DB::table('redemptions')->count(),
        'wallet_balance' => (int) DB::table('wallets')->where('id', 1)->value('balance'),
        'ledger_count' => DB::table('ledger_entries')->count(),
        'ledger_total' => (int) DB::table('ledger_entries')->sum('amount'),
        'quote_status' => (string) DB::table('quotes')->where('id', 1)->value('status'),
        'acceptances' => DB::table('acceptances')->count(),
        'claims' => DB::table($fixedClaim ? 'claims_fixed' : 'claims_broken')->count(),
        'lock_version' => (int) DB::table('lock_counters')->where('id', 1)->value('version'),
    ];
}

/** @param array<string, int|string> $state */
function assertBrokenCommerceState(array $state, string $scenario): void
{
    evidenceAssert($state['stock'] === -1 && $state['orders'] === 2, "{$scenario}: oversell was not reproduced.");
    evidenceAssert($state['coupon_uses'] === -1 && $state['redemptions'] === 2, "{$scenario}: coupon over-redemption was not reproduced.");
    evidenceAssert($state['wallet_balance'] === 20 && $state['ledger_count'] === 2 && $state['ledger_total'] === 160, "{$scenario}: wallet over-debit was not reproduced.");
    evidenceAssert($state['quote_status'] === 'accepted' && $state['acceptances'] === 2, "{$scenario}: double quote acceptance was not reproduced.");
    evidenceAssert($state['claims'] === 2, "{$scenario}: duplicate claim was not reproduced.");
    evidenceAssert($state['lock_version'] === 1, "{$scenario}: lock misuse did not lose an update.");
}

/** @param array<string, int|string> $state */
function assertFixedCommerceState(array $state, string $scenario): void
{
    evidenceAssert($state['stock'] === 0 && $state['orders'] === 1, "{$scenario}: inventory invariant failed.");
    evidenceAssert($state['coupon_uses'] === 0 && $state['redemptions'] === 1, "{$scenario}: coupon invariant failed.");
    evidenceAssert($state['wallet_balance'] === 20 && $state['ledger_count'] === 1 && $state['ledger_total'] === 80, "{$scenario}: wallet invariant failed.");
    evidenceAssert($state['quote_status'] === 'accepted' && $state['acceptances'] === 1, "{$scenario}: quote invariant failed.");
    evidenceAssert($state['claims'] === 1, "{$scenario}: uniqueness invariant failed.");
    evidenceAssert($state['lock_version'] === 2, "{$scenario}: transactional lock invariant failed.");
}

$scenarios = [];

resetCommerceState();
$broken = evidenceRun('/api/oversell/broken', 'oversell-read');
$brokenState = commerceState(false);
evidenceStatuses($broken, [201 => 2], 'oversell/broken');
evidenceAssert($brokenState['stock'] === -1 && $brokenState['orders'] === 2, 'Overselling was not reproduced.');

resetCommerceState();
$fixed = evidenceRun('/api/oversell/fixed', 'oversell-claim');
$fixedState = commerceState(true);
evidenceStatuses($fixed, [201 => 1, 409 => 1], 'oversell/fixed');
evidenceAssert($fixedState['stock'] === 0 && $fixedState['orders'] === 1, 'Overselling fix failed.');
$scenarios['oversell'] = compact('broken', 'brokenState', 'fixed', 'fixedState');

resetCommerceState();
$broken = evidenceRun('/api/coupon/broken', 'coupon-read');
$brokenState = commerceState(false);
evidenceStatuses($broken, [201 => 2], 'coupon/broken');
evidenceAssert($brokenState['coupon_uses'] === -1 && $brokenState['redemptions'] === 2, 'Coupon over-redemption was not reproduced.');

resetCommerceState();
$fixed = evidenceRun('/api/coupon/fixed', 'coupon-claim');
$fixedState = commerceState(true);
evidenceStatuses($fixed, [201 => 1, 409 => 1], 'coupon/fixed');
evidenceAssert($fixedState['coupon_uses'] === 0 && $fixedState['redemptions'] === 1, 'Coupon fix failed.');
$scenarios['coupon'] = compact('broken', 'brokenState', 'fixed', 'fixedState');

resetCommerceState();
$broken = evidenceRun('/api/wallet/broken', 'wallet-read');
$brokenState = commerceState(false);
evidenceStatuses($broken, [201 => 2], 'wallet/broken');
evidenceAssert($brokenState['wallet_balance'] === 20 && $brokenState['ledger_total'] === 160, 'Wallet over-debit was not reproduced.');

resetCommerceState();
$fixed = evidenceRun('/api/wallet/fixed', 'wallet-claim');
$fixedState = commerceState(true);
evidenceStatuses($fixed, [201 => 1, 409 => 1], 'wallet/fixed');
evidenceAssert($fixedState['wallet_balance'] === 20 && $fixedState['ledger_total'] === 80, 'Wallet fix failed.');
$scenarios['wallet'] = compact('broken', 'brokenState', 'fixed', 'fixedState');

resetCommerceState();
$broken = evidenceRun('/api/quote/broken', 'quote-read');
$brokenState = commerceState(false);
evidenceStatuses($broken, [201 => 2], 'quote/broken');
evidenceAssert($brokenState['acceptances'] === 2, 'Double quote acceptance was not reproduced.');

resetCommerceState();
$fixed = evidenceRun('/api/quote/fixed', 'quote-claim');
$fixedState = commerceState(true);
evidenceStatuses($fixed, [201 => 1, 409 => 1], 'quote/fixed');
evidenceAssert($fixedState['acceptances'] === 1, 'Quote acceptance fix failed.');
$scenarios['quote'] = compact('broken', 'brokenState', 'fixed', 'fixedState');

resetCommerceState();
$broken = evidenceRun('/api/unique/broken', 'unique-check');
$brokenState = commerceState(false);
evidenceStatuses($broken, [201 => 2], 'unique/broken');
evidenceAssert($brokenState['claims'] === 2, 'Duplicate claim was not reproduced.');

resetCommerceState();
$fixed = evidenceRun('/api/unique/fixed', 'unique-insert');
$fixedState = commerceState(true);
evidenceStatuses($fixed, [201 => 1, 409 => 1], 'unique/fixed');
evidenceAssert($fixedState['claims'] === 1, 'Unique constraint fix failed.');
$scenarios['unique_constraint'] = compact('broken', 'brokenState', 'fixed', 'fixedState');

resetCommerceState();
$broken = evidenceRun('/api/lock/broken', 'lock-read');
$brokenState = commerceState(false);
evidenceStatuses($broken, [201 => 2], 'lock/broken');
evidenceAssert($brokenState['lock_version'] === 1, 'Lock misuse did not reproduce a lost update.');

resetCommerceState();
$fixed = evidenceRun('/api/lock/fixed', 'before-lock');
$fixedState = commerceState(true);
evidenceStatuses($fixed, [201 => 2], 'lock/fixed');
evidenceAssert($fixedState['lock_version'] === 2, 'Transactional locking fix failed.');
$scenarios['lock_misuse'] = compact('broken', 'brokenState', 'fixed', 'fixedState');

DB::table('deadlock_rows')->delete();
DB::table('deadlock_rows')->insert([['id' => 1, 'value' => 0], ['id' => 2, 'value' => 0]]);
DB::table('scenario_metrics')->updateOrInsert(['metric' => 'deadlock_completed'], ['value' => 0]);
$broken = evidenceRun('/api/deadlock/broken', 'deadlock-first-lock');
$brokenCompleted = (int) DB::table('scenario_metrics')->where('metric', 'deadlock_completed')->value('value');
evidenceStatuses($broken, [201 => 1, 503 => 1], 'deadlock/broken');
evidenceAssert($brokenCompleted === 1, 'The broken lock order did not lose one operation to a deadlock.');

DB::table('scenario_metrics')->where('metric', 'deadlock_completed')->update(['value' => 0]);
$fixed = evidenceRun('/api/deadlock/fixed', 'before-ordered-locks');
$fixedCompleted = (int) DB::table('scenario_metrics')->where('metric', 'deadlock_completed')->value('value');
evidenceStatuses($fixed, [201 => 2], 'deadlock/fixed');
evidenceAssert($fixedCompleted === 2, 'Ordered locking did not preserve both operations.');
$scenarios['deadlock'] = compact('broken', 'brokenCompleted', 'fixed', 'fixedCompleted');

DB::table('timeout_rows')->delete();
DB::table('timeout_rows')->insert(['id' => 1, 'value' => 0]);
DB::table('scenario_metrics')->updateOrInsert(['metric' => 'timeout_completed'], ['value' => 0]);
$broken = evidenceRun('/api/timeout/broken', 'timeout-lock-held');
$brokenCompleted = (int) DB::table('scenario_metrics')->where('metric', 'timeout_completed')->value('value');
evidenceStatuses($broken, [201 => 1, 503 => 1], 'timeout/broken');
evidenceAssert($brokenCompleted === 1, 'The broken lock timeout did not reject the blocked operation.');

DB::table('scenario_metrics')->where('metric', 'timeout_completed')->update(['value' => 0]);
$fixed = evidenceRun('/api/timeout/fixed', 'timeout-lock-held');
$fixedCompleted = (int) DB::table('scenario_metrics')->where('metric', 'timeout_completed')->value('value');
evidenceStatuses($fixed, [201 => 2], 'timeout/fixed');
evidenceAssert($fixedCompleted === 2, 'The bounded lock wait did not preserve both operations.');
$scenarios['lock_timeout'] = compact('broken', 'brokenCompleted', 'fixed', 'fixedCompleted');

$critical = ['expected' => $iterations, 'broken_passed' => 0, 'fixed_passed' => 0];

for ($iteration = 1; $iteration <= $iterations; $iteration++) {
    resetCommerceState();
    evidenceStatuses(evidenceRun('/api/critical/broken', 'critical-read'), [201 => 2], "critical/broken #{$iteration}");
    assertBrokenCommerceState(commerceState(false), "critical/broken #{$iteration}");
    $critical['broken_passed']++;

    resetCommerceState();
    evidenceStatuses(evidenceRun('/api/critical/fixed', 'critical-claim'), [201 => 2], "critical/fixed #{$iteration}");
    assertFixedCommerceState(commerceState(true), "critical/fixed #{$iteration}");
    $critical['fixed_passed']++;
}

echo json_encode([
    'engine' => $engine,
    'database' => $database,
    'allowlist_enforced' => true,
    'isolated_migration' => true,
    'scenarios' => $scenarios,
    'critical_evidence' => $critical,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
