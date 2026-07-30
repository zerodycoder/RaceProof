# Redis coordination

RaceProof can coordinate its parent and worker processes through one
Laravel Redis connection. The `file` driver remains the zero-configuration
default. Select Redis when every RaceProof process can reach the same dedicated
test Redis service and local coordinator files are unsuitable.

## Requirements

- a single-node Redis endpoint;
- Laravel's configured `phpredis` or `predis` client;
- a disposable or access-controlled test service shared by every worker;
- a TTL longer than the maximum expected race lifecycle.

Redis Cluster, Sentinel, cross-region coordination, and queue orchestration are
not supported. Redis selection alone does not change worker placement; remote
execution is a separate opt-in described in
[remote worker transport](remote-workers.md).

## Configuration

Publish the current configuration and select a named Laravel Redis connection:

```dotenv
RACEPROOF_COORDINATOR_DRIVER=redis
RACEPROOF_REDIS_CONNECTION=default
RACEPROOF_REDIS_NAMESPACE=raceproof
RACEPROOF_REDIS_TTL_SECONDS=86400
RACEPROOF_REDIS_POLL_INTERVAL_MS=5
```

The connection must be defined under `database.redis`. Credentials, TLS, host,
port, and client options stay in Laravel's database configuration; RaceProof
accepts only the non-secret connection name. Use a dedicated Redis database or
ACL identity where practical. A namespace prevents accidental key collisions
but is not an authorization boundary.

Connection names and namespaces use restricted character sets. TTL is bounded
from 60 seconds through seven days, and polling is bounded from 1 through 1000
milliseconds. Invalid, missing, or unknown configuration fails closed.

## Lifecycle and retention

Each run is one Redis hash containing its plan, idempotent barrier transitions,
first-writer participant results, and a monotonically sequenced timeline. Lua
scripts atomically create runs and record transitions. A sorted-set index
supports retained-run discovery and prunes expired or missing entries.

Writes refresh the configured TTL; reads do not. Successful runs are removed by
the normal coordinator cleanup policy. Failed or interrupted runs remain
available to reporting and `raceproof:clean` until explicit cleanup or TTL
expiry. Request credentials needed by workers are stored in the plan, so the
Redis service and its backups must be treated as sensitive test evidence.

Run the same preflight used by orchestration:

```bash
php artisan raceproof:doctor --self-test
```

The health check performs a bounded `PING` plus an atomic write/read/delete
probe. Diagnostics report a generic unavailable-or-misconfigured error and do
not echo connection credentials.

## Rollback

Set `RACEPROOF_COORDINATOR_DRIVER=file`, clear Laravel's configuration cache,
and rerun Doctor. Existing Redis runs are not migrated to files. Remove retained
runs with `php artisan raceproof:clean` before switching, or let their bounded
TTL expire after access to the Redis backend is removed.
