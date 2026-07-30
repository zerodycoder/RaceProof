# Troubleshooting decision guide

Start with the earliest failing phase. Later symptoms are often consequences of
an earlier setup problem.

## 1. Does `raceproof:doctor --self-test` pass?

- **No — environment rejected:** confirm `APP_ENV=testing`,
  `RACEPROOF_ENABLED=true`, and that the package is not running in production.
- **No — database rejected:** use a disposable database, enable the exact
  one-name allowlist, close any parent transaction, and replace SQLite
  `:memory:` with the production engine or a file-backed smoke database.
- **Yes:** continue to worker startup.

`--self-test` launches a separate Laravel CLI process, so it also catches PHP
binary, application bootstrap, package discovery, and child-process
configuration failures. Add `--json` for a schema-v1 diagnostic that contains
check identifiers and redacted failure messages without dumping environment
variables.

The coordinator check resolves `raceproof.coordinator.driver` (normally
`RACEPROOF_COORDINATOR_DRIVER`) through the same container path used by parent
and worker processes. Valid values are `file` and `redis`. An unknown, missing,
or malformed driver fails without echoing the configured value, and Redis
connection failures are reduced to a generic message so credentials cannot leak
through Doctor output.

The `worker-transport` check resolves `local` or `remote` through the same path
used before orchestration. Remote mode also checks control-plane health and a
live heartbeat for every configured agent. It never launches a race or prints
the authentication secret.

The JSON shape is intentionally small and versioned:

```json
{
  "schema_version": 1,
  "ok": true,
  "checks": [
    {
      "id": "laravel-child-process",
      "label": "Laravel child process",
      "status": "pass",
      "message": null
    }
  ]
}
```

## 2. Do all workers become ready?

- **A worker exits before READY:** inspect its bounded stderr/stdout and retained
  timeline. Run the same `PHP_BINARY` and Artisan command outside the test.
- **Spawn timeout with no worker output:** verify `proc_open`, the PHP executable,
  file permissions, antivirus/process policy, and that Composer dependencies are
  installed for the worker process.
- **Coordinator driver rejected:** publish the current configuration and select
  `file` or `redis`; custom drivers are not supported.
- **Redis coordinator unavailable:** verify the named connection under
  `database.redis`, client extension/package, TLS and ACL settings, network
  reachability from CLI PHP, and a single-node topology. Do not paste connection
  strings into diagnostics.
- **Redis run disappears:** increase the bounded TTL above the longest spawn,
  run, and diagnosis window. Reads intentionally do not refresh retention.
- **Parent/worker driver mismatch:** clear stale Laravel configuration caches and
  ensure the parent CLI and worker CLI load the same environment/configuration.
- **Remote transport requires Redis:** select the Redis coordinator; file
  coordination cannot be shared safely with remote agents.
- **Remote agent unavailable:** start every configured
  `raceproof:worker-agent --id=<agent>` process with the same application,
  configuration, secret, Redis connection, and database reachability, then wait
  for Doctor to observe all heartbeats.
- **Control message rejected or expires:** compare clocks, message TTL, agent
  ID/order, namespace, and secret deployment without printing the secret.
  Rotation requires draining active work and restarting parent/agents together.
- **Remote capacity exhausted:** increase the explicitly bounded per-agent
  capacity only after measuring the disposable database and host, or add a
  registered agent. Work remains queued; there is no automatic rerouting.
- **Remote clock synchronization fails:** reduce Redis latency or increase the
  bounded RTT limit only with reviewed evidence. Cross-region timing is not
  supported.
- **Remote stop acknowledgement times out:** inspect the bounded agent log and
  remote state; a failed host can require manual process cleanup. RaceProof does
  not silently fail over or retry the worker.
- **Only Windows fails:** use an absolute PHP path, avoid shell-only quoting,
  reproduce with `php artisan raceproof:doctor --self-test`, and compare the
  public `platform-smoke (windows-latest)` job. Package maintainers can run
  `composer consumer:check` from a RaceProof source checkout; native Windows
  remains experimental.

For queue races, READY means the worker has reserved its exact run-scoped job:

- **Connection rejected before the factory runs:** select an explicitly
  configured clearable `database` or `redis` queue connection; `sync`, `null`,
  custom, and unavailable drivers are unsupported.
- **Job shape rejected:** return one new plain `ShouldQueue` object per
  participant and remove job-owned connection, queue, delay, uniqueness,
  encryption, chain/batch, after-commit, and retry/timeout policy.
- **No queued job available:** verify the queue table/broker is shared with
  worker CLI processes, the jobs migration is committed, and no external worker
  consumes the random `raceproof:<run-id>:pN` queues.
- **Unexpected class or retry policy:** clear stale configuration/autoload
  caches and ensure every local or remote worker runs the same reviewed code.
- **Cleanup failed:** retain the result, resolve only its exact run-scoped queue
  names, fix backend connectivity, and clear those names without flushing the
  connection. See [queue races](queue-races.md).

## 3. Do all workers reach the checkpoint?

- **No:** the route may return, throw, authorize, or branch before
  `race_point()`. Use per-participant reports to identify the divergent worker.
- **Checkpoint name mismatch:** names are exact and must be registered with
  `releaseWhenAllReach()`.
- **Runtime checkpoint is inactive:** verify `raceproof/runtime` is installed in
  the application containing the checkpoint and that the worker boots the same
  code revision.

## 4. Can workers see the same data?

- **Parent sees rows, workers do not:** remove `RefreshDatabase`,
  `DatabaseTransactions`, or any open parent transaction; commit setup first.
- **Parent config works, workers use defaults:** move `DB_*`, auth, tenant, and
  other process-wide settings into environment/config loaded during worker boot,
  or use a participant bootstrap.
- **Connection refused:** containers may be reachable from the host but not from
  the worker's network namespace. Test the exact `DB_HOST` and port from PHP.

## 5. Is the race reproducible?

- **Broken case sometimes passes:** move the checkpoint directly after the stale
  read and require every participant at the barrier. Do not add sleeps as a
  substitute for coordination.
- **Fixed case has the right statuses but wrong state:** assert the database
  invariant and every side effect. HTTP counts are not proof.
- **Deadlock/timeout differs by engine:** assert portable outcomes and invariants,
  not vendor-specific exception text. See [database testing](database-testing.md).

## 6. Did a worker authenticate as expected?

- inspect the per-participant request/auth specification;
- remember that sessions, guard tokens, and Sanctum tokens must exist in shared
  persisted state before workers start;
- do not log raw cookies or tokens; reporters redact known patterns but cannot
  classify every application secret.

## 7. What evidence should be retained?

- use the human report for a concise local failure;
- publish JSON or JUnit only as restricted, short-lived CI artifacts;
- keep `plan.json` private because workers require exact credentials;
- run `raceproof:clean` only after resolving the intended test run and reviewing
  which retained artifacts will be removed.

If the earliest failing phase remains unclear, open a GitHub issue with the
RaceProof version, PHP/Laravel versions, OS, database engine, sanitized human
report, and a minimal route/test reproduction.
