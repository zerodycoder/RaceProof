# Upgrading RaceProof

Signed `v1.0.0-beta.1` packages are published on GitHub and Packagist and form
the first real upgrade baseline. Stable v1 is not published.

## Toward 1.0

- Application checkpoints belong to `raceproof/runtime` in production
  `require`; keep `raceproof/laravel` in `require-dev`.
- Use `race_point()` or `RaceProof\Runtime\Checkpoint::sync()` in application
  code. The Laravel facade and internal activation bridge are not part of the
  frozen v1 contract.
- Treat JSON and timeline `schema_version` independently from the Composer
  package version.
- Review [the frozen public API](docs/public-api.md), [versioning policy](docs/versioning.md),
  and [runtime deployment guide](docs/runtime-checkpoints.md) before adopting a
  pre-release.

Every future release section must list required code/configuration changes,
deprecated paths, and safe rollback constraints. "No migration required" must
be stated explicitly when true.

### Queue race orchestration

Queue races are additive and require no migration for existing HTTP races.
Applications that use them must provide an explicitly named clearable
`database` or `redis` connection in `config/queue.php`; RaceProof does not
publish or select one automatically. Database queues also require Laravel's
normal committed jobs table in a disposable database visible to child workers.

Replace neither application queue workers nor production queue configuration.
Pass the dedicated connection to `RaceBuilder::queue()` and keep job-owned
connection, queue, delay, uniqueness, encryption, chain/batch, after-commit,
retry, and timeout policy out of the participating jobs. Configure the bounded
RaceProof policy with `queueAttempts()` instead. Review
[the queue race guide](docs/queue-races.md) before enabling Redis or remote
agents.

To roll back, stop creating queue races, allow active runs to settle, clear only
recorded run-scoped queues if automated cleanup failed, and return those tests
to the HTTP builder. No RaceProof database migration or queue-wide purge is
required.

### Coordinator driver configuration

The default coordinator remains local files and requires no application change
when the published configuration is current:

```php
'coordinator' => [
    'driver' => env('RACEPROOF_COORDINATOR_DRIVER', 'file'),
    'path' => storage_path('framework/raceproof'),
    'redis' => [
        'connection' => env('RACEPROOF_REDIS_CONNECTION', 'default'),
        'namespace' => env('RACEPROOF_REDIS_NAMESPACE', 'raceproof'),
        'ttl_seconds' => (int) env('RACEPROOF_REDIS_TTL_SECONDS', 86_400),
        'poll_interval_ms' => (int) env('RACEPROOF_REDIS_POLL_INTERVAL_MS', 5),
    ],
],
```

Applications with a previously published configuration file must add the
`driver` key before adopting a version that includes pluggable coordination.
Add the nested `redis` keys before selecting that driver. Missing, malformed,
empty, or unknown values fail closed. The file path must be absolute and cannot
target a filesystem root.

Redis coordination requires a named single-node connection under
`database.redis` and either `ext-redis` or `predis/predis`. Selecting Redis alone
does not enable remote execution. Existing file runs are not migrated. To roll
back, clean retained Redis runs, select `file`, clear Laravel's configuration
cache, and rerun Doctor; otherwise the Redis keys expire at their bounded TTL.

### Worker transport configuration

The default worker transport remains local Symfony processes, so existing
applications require no migration:

```php
'worker_transport' => [
    'driver' => env('RACEPROOF_WORKER_TRANSPORT', 'local'),
    'remote' => [
        // Publish the complete current configuration before selecting remote.
    ],
],
```

Remote execution is opt-in and requires the Redis coordinator, a strong secret,
and a static agent list. Older published configuration files must be republished
or manually aligned with `config/raceproof.php` before setting
`RACEPROOF_WORKER_TRANSPORT=remote`; every missing or malformed bound fails
before orchestration. Follow [the remote worker guide](docs/remote-workers.md)
for the complete keys, agent startup, secret rotation, timing limits, and CI
preflight.

To roll back worker placement, stop new runs, let active remote workers settle,
set `RACEPROOF_WORKER_TRANSPORT=local`, stop agents, clear configuration caches,
and rerun Doctor. Coordinator data does not require migration; remote control
state expires at its configured TTL.

## Published beta upgrade rehearsal

Maintainers can exercise the current upgrade control with:

```bash
composer release:upgrade-dry-run
```

The command creates an isolated Laravel application without RaceProof path
repositories, installs exact `v1.0.0-beta.1` packages from Packagist, records
their immutable source/dist references, and runs a bounded Doctor/runtime/race
smoke. It then builds `1.0.0-rc.1` candidate archives from the current source
commit, upgrades both packages together through a Composer artifact repository,
and repeats the same smoke.

Bounded machine-readable output is written to
`build/release/upgrade/1.0.0-rc.1/evidence.json`. Generated applications,
archives, locks, and evidence stay outside version control.

This is a repeatable rehearsal of the control, not final stable-candidate
evidence. The release PR must rerun it on the exact candidate commit and version,
review the applicable migration and rollback notes, and only then update the
[pre-release audit](docs/release-audit.md). Repackaging one source tree as both
the baseline and candidate remains prohibited.
