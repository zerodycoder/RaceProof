# Remote worker transport

RaceProof can keep orchestration in one Laravel test process while executing
participants on explicitly registered worker agents. The transport is opt-in:
`local` remains the default, and switching back does not migrate coordinator
data.

This is a bounded control plane for RaceProof's fixed
`raceproof:worker` command. It is not a remote shell, Laravel Queue backend,
service-discovery system, autoscaler, or retry scheduler.

## Supported topology

Remote transport requires:

- the `redis` coordinator and one single-node Redis service reachable by the
  parent and every agent;
- the same reviewed application revision, Composer lock, Laravel configuration,
  and RaceProof version on every host;
- one disposable test database reachable with equivalent credentials from
  every host;
- a static list of 1–32 agent IDs;
- a shared random authentication secret of at least 32 bytes;
- low-latency connectivity to Redis within the configured clock-sync RTT limit;
- `proc_open`, CLI PHP, and Artisan on each agent host.

CI continuously proves this topology on Ubuntu with two independently running
agent processes, Redis 7.4, and the isolated Laravel consumer. It does not claim
Redis Cluster, Sentinel, cross-region, Windows-agent, macOS-agent, autoscaling,
or host-failover support.

## Configuration

Keep coordination and transport selection separate:

```dotenv
RACEPROOF_COORDINATOR_DRIVER=redis
RACEPROOF_REDIS_CONNECTION=default

RACEPROOF_WORKER_TRANSPORT=remote
RACEPROOF_REMOTE_NAMESPACE=raceproof:remote
RACEPROOF_REMOTE_AGENTS=agent-a,agent-b
RACEPROOF_REMOTE_SECRET=<random value distributed through a secret manager>

RACEPROOF_REMOTE_MESSAGE_TTL_MS=15000
RACEPROOF_REMOTE_MAX_CLOCK_SKEW_MS=2000
RACEPROOF_REMOTE_CLOCK_SYNC_MAX_RTT_MS=100
RACEPROOF_REMOTE_POLL_INTERVAL_MS=25
RACEPROOF_REMOTE_STATE_TTL_SECONDS=300
RACEPROOF_REMOTE_HEARTBEAT_TTL_MS=5000
RACEPROOF_REMOTE_SHUTDOWN_TIMEOUT_MS=2000
RACEPROOF_REMOTE_MAX_CONCURRENCY=8
RACEPROOF_REMOTE_MAX_PENDING_CONTROLS=1000
RACEPROOF_REMOTE_CONTROL_MESSAGE_BYTES=2048
RACEPROOF_REMOTE_OUTPUT_BYTES=4096
```

Generate the secret outside the repository, for example with
`openssl rand -hex 32`, and distribute it through the existing CI or
infrastructure secret manager. Do not commit it, print it, put it in a URL, or
pass it as a command option. A namespace prevents accidental collisions; it is
not an authorization boundary.

Agent IDs are lowercase path-safe identifiers. The parent assigns `p1` to the
first configured agent, `p2` to the second, and continues round-robin in the
declared order. Duplicate, empty, unknown, or more than 32 agents fail before a
run is created. The heartbeat TTL must be at least three times the polling
interval so registration cannot flap under the configured cadence. Set the
state TTL above the longest configured spawn, run, and shutdown lifecycle;
reads intentionally do not extend retention.

## Starting agents

Start the same application on each registered host with its non-secret ID:

```bash
php artisan raceproof:worker-agent --id=agent-a
```

For bounded CI helpers, `--idle-timeout-ms=120000` exits after two idle minutes.
Zero is the default and keeps the agent polling. The option is limited to ten
minutes; a process supervisor should own longer-running lifecycle and restart
policy.

Run Doctor from the parent only after every agent is running:

```bash
php artisan raceproof:doctor --json
```

The `worker-transport` check validates configuration, Redis control-plane
health, and a live heartbeat for every registered agent without launching a
race. A missing heartbeat fails rather than silently falling back to local
execution.

## Authenticated control protocol

The parent issues only `start` and `stop`. Each version-1 JSON envelope contains:

- a random 128-bit message ID;
- the exact remote-control namespace as a signed deployment context;
- issue and expiry times in milliseconds;
- the action and exact target agent;
- the generated run and participant IDs;
- an HMAC-SHA256 signature over the canonical ordered fields.

The shared secret is never part of the envelope, Redis state, worker command
line, timeline, report, or diagnostic. Agents reject unknown fields, malformed
types, invalid identifiers, oversized messages, future messages beyond the
clock-skew bound, expired messages, overlong lifetimes, cross-namespace or
wrong-target messages, and invalid signatures with one generic bounded
diagnostic.

Envelope issue/expiry validation uses wall-clock milliseconds across hosts.
Heartbeat throttling, agent idle bounds, polling, and shutdown deadlines use
host-local monotonic time so wall-clock adjustments cannot extend those waits.

Start and stop have separate Redis inboxes, so a capacity-saturated start queue
cannot block shutdown controls. A valid message is atomically recorded in the
agent's expiring replay key before its state transition. Both inboxes have an
explicit pending-control cap. Replaying the same message ID or duplicating a
start therefore cannot launch a second worker, and an unavailable agent cannot
accumulate an unbounded queue.
Remote state, inboxes, replay IDs, heartbeats, and bounded redacted output all
expire.

## Lifecycle and failure behavior

Each agent advertises a TTL-bound heartbeat and launches at most
`RACEPROOF_REMOTE_MAX_CONCURRENCY` local Artisan workers. Additional starts
remain queued; they are not dropped or automatically rerouted. The parent uses
the existing spawn and run deadlines. A missing agent, expired launch, vanished
state, rejected control, worker exit, or unacknowledged shutdown becomes an
actionable worker failure and retains normal coordinator evidence.

A parent timeout sends a signed stop. The agent stops and waits for its local
process before publishing terminal status. If the agent host has failed, the
parent's shutdown wait remains bounded and reports the missing acknowledgement;
manual process cleanup may still be required on the failed host.

Agent-side stdout and stderr are redacted before entering Redis and are each
bounded by `RACEPROOF_REMOTE_OUTPUT_BYTES`. The parent applies the normal
worker-output redaction and bound again. Application response bodies and the
race plan remain potentially sensitive test evidence.

## Timing semantics

Host-local `hrtime()` values cannot be compared directly. In remote mode each
participant samples Redis `TIME` once, brackets that read with the local
monotonic clock, and aligns subsequent monotonic timestamps to the sample
midpoint. Synchronization fails when the round trip exceeds
`RACEPROOF_REMOTE_CLOCK_SYNC_MAX_RTT_MS`.

This makes participant duration monotonic and gives one shared low-latency
reference for start-spread diagnostics. Network asymmetry and Redis latency
still limit precision. Do not use remote results as a benchmark or claim exact
cross-host, cross-region, operating-system, or database schedule control.

## Rotation and rollback

The protocol accepts one active secret. To rotate it without converting valid
in-flight controls into authentication failures:

1. stop creating new races;
2. wait for active workers to settle;
3. stop all agents;
4. deploy the new secret to parent and agents together;
5. restart agents and rerun Doctor.

To roll back, stop new races, let active remote workers settle, set
`RACEPROOF_WORKER_TRANSPORT=local`, stop the agents, clear Laravel's
configuration cache, and rerun Doctor. Coordinator runs do not require
migration. Remote control keys may be removed after all active work ends or
left to their configured TTL.
