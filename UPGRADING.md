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
`database.redis` and either `ext-redis` or `predis/predis`. It coordinates the
existing local worker processes; it does not enable remote execution. Existing
file runs are not migrated. To roll back, clean retained Redis runs, select
`file`, clear Laravel's configuration cache, and rerun Doctor; otherwise the
Redis keys expire at their bounded TTL.

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
