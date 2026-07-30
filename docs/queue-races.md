# Queue races

Queue races execute one distinct Laravel `ShouldQueue` job per participant
through Laravel's native queue worker lifecycle. They use the same independent
RaceProof workers, READY/START barrier, runtime checkpoints, database safety
guard, coordinator, local/remote transport, evidence model, and cleanup rules
as HTTP races.

This is a bounded regression-test primitive. It does not attach to existing
`queue:work` daemons, consume arbitrary application queues, or fuzz production
traffic.

## Configure a disposable queue

Select an explicitly named `database` or `redis` connection. Do not point it at
a shared application queue.

```php
// config/queue.php
'connections' => [
    'raceproof_database' => [
        'driver' => 'database',
        'connection' => 'raceproof_queue_database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 30,
        'after_commit' => false,
    ],

    'raceproof_redis' => [
        'driver' => 'redis',
        'connection' => 'raceproof',
        'queue' => 'default',
        'retry_after' => 30,
        'block_for' => null,
        'after_commit' => false,
    ],
],
```

The connection must implement Laravel's clearable queue contract. `sync`,
`null`, custom, unavailable, malformed, and non-clearable connections fail
before the job factory runs.

Omitting the `connection` argument resolves Laravel's configured
`queue.default` and records its actual name in the plan and evidence. The
resolved default must still be a supported disposable connection.

For the database driver, commit the `jobs` migration before the race and use a
disposable database visible to every worker. For Redis, use a dedicated
single-node test connection with restricted credentials. The queue backend and
RaceProof coordinator are configured independently; either file or Redis
coordination can drive a supported queue race.

If a database queue names a different Laravel database connection, RaceProof
applies the same open-transaction, in-memory SQLite, and exact database-name
allowlist checks to that queue database before resolving the queue backend.
Include both disposable database names in `RACEPROOF_ALLOWED_DATABASES` when
they are intentionally separate.

## Define a checkpointed job

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RedeemCoupon implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $couponId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $coupon = Coupon::query()->findOrFail($this->couponId);

        race_point('coupon-claim');

        Coupon::query()
            ->whereKey($coupon->getKey())
            ->whereNull('redeemed_by')
            ->update(['redeemed_by' => $this->userId]);
    }
}
```

The job is ordinary application code. RaceProof activates runtime checkpoints
inside its validated worker process and invokes the job through Laravel's
`Worker::process()` path, so middleware, dependency injection, events, exception
handling, deletion, and native retry bookkeeping remain in effect.

## Run the race

```php
$result = race()
    ->participants(3)
    ->queue(
        fn (string $participantId) => new RedeemCoupon(
            couponId: $coupon->getKey(),
            userId: 100 + (int) substr($participantId, 1),
        ),
        connection: 'raceproof_database',
    )
    ->queueAttempts(maxAttempts: 3, backoffSeconds: 1)
    ->releaseWhenAllReach('coupon-claim')
    ->run();

$result
    ->assertAllFinished()
    ->assertNoWorkerFailures()
    ->assertNoTimeouts()
    ->assertStatusCount(204, 3)
    ->assertInvariant(
        fn () => Coupon::query()
            ->whereKey($coupon->getKey())
            ->whereNotNull('redeemed_by')
            ->count() === 1,
        'Exactly one queued job may claim the coupon.',
    );
```

The factory runs in the parent only and receives `p1` through `pN`. It must
return a new object implementing `ShouldQueue` for every participant. Closures
and job payloads never enter the serialized race plan; only the validated job
class manifest does.

`queueAttempts()` is optional and defaults to one attempt with no backoff.
RaceProof accepts one to five attempts and zero to sixty seconds of backoff.
The run timeout remains the outer deadline, so a configured backoff never makes
execution unbounded.

## Isolation and lifecycle

For each participant, RaceProof derives a random run-scoped queue:

```text
raceproof:<128-bit-run-id>:p1
raceproof:<128-bit-run-id>:p2
...
```

Before dispatch, each exact queue is cleared and verified empty. Exactly one
validated job is pushed to each queue. Workers reserve their own job before
announcing READY; START then releases all reserved jobs together. This avoids
cross-consumption and preserves barrier semantics on database and Redis queues.

On every success or failure path, RaceProof clears only those run-scoped queue
names. A cleanup failure is never hidden: it raises `RaceExecutionFailed` and
retains the result when one exists. The parent does not purge the connection,
the default queue, failed jobs, or unrelated application work.

Queue participant evidence adds:

- `execution: queue`;
- the bounded attempt count;
- the expected job class;
- the configured connection name;
- the run-scoped queue name;
- reservation, attempt, completion, exception, and cleanup timeline events.

Successful jobs use synthetic status `204`, allowing existing result assertions
and reporters to remain compatible. Exhausted application exceptions retain
their redacted class/message. Missing, unexpected, self-released, or
policy-owning jobs are worker failures. Job payloads are not projected into
human, JSON, JUnit, or Studio reports. Studio participant cards show only the
bounded queue metadata listed above.

## Deliberate exclusions

Queue races currently reject:

- class strings, closures, reused job objects, and non-`ShouldQueue` values;
- job-selected connections, queue names, delays, or after-commit dispatch;
- unique, encrypted, chained, batched, or `ShouldQueueAfterCommit` jobs;
- job-owned tries, max-exception, backoff, timeout, retry-until, or
  fail-on-timeout policy, whether declared by property, method, or Laravel 13
  queue attribute;
- jobs that explicitly release or fail themselves;
- `sync`, `null`, custom, and non-clearable queue drivers.

These shapes can change dispatch cardinality, timing, visibility, ownership, or
cleanup semantics. Supporting one requires a separate reviewed contract and
evidence; RaceProof does not silently approximate it.

## Safety, CI, and rollback

All normal RaceProof environment and database guards run before job
construction or dispatch. Queue payloads can contain application data, so keep
the disposable queue private, avoid secrets in job properties, restrict CI
artifacts, and use short retention.

Repository evidence covers native database-queue checkpoints, success, retry,
exhaustion, missing/unexpected jobs, policy bypasses, independent consumer
installation, MySQL/PostgreSQL invariants, and a real Redis service. Redis CI
publishes bounded queue evidence without job payloads.

To roll back, stop starting queue races, let active runs settle, clear only
their recorded `raceproof:<run-id>:pN` queues if automated cleanup failed, and
return tests to the HTTP builder. No queue or database migration is required by
the RaceProof package itself.
