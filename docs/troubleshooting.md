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
- **Only Windows fails:** use an absolute PHP path, avoid shell-only quoting, and
  reproduce with `composer test:pest`; native Windows is experimental.

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
