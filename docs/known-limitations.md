# Known limitations

These boundaries are part of the product contract. They are not hidden roadmap
promises and must not be overstated in release material.

## Distribution and evidence status

- Signed `v1.0.0-beta.1` releases exist for both packages on GitHub and
  Packagist, with verified checksums, provenance, attestations, source
  references, and clean installation.
- The generated runtime repository is a public subtree split. The main RaceProof
  repository remains its only source of truth.
- `v1.0.0-beta.1` is the first published upgrade baseline. The repository
  rehearses an upgrade from those Packagist packages to distinct local candidate
  artifacts, but final verification still requires the exact reviewed release
  candidate commit and version.
- Private-beta evidence currently records 0/10 invited projects and 0/5
  confirmed adopters.
- Stable publication is still blocked by beta-adoption evidence and a real
  upgrade from the published beta in
  [`audit/release-audit.json`](../audit/release-audit.json); the package
  publication gate is verified and the `stable-release` gate tracks the
  eventual workflow outcome.

The stable release workflow enforces the evidence and upgrade boundaries with
`composer release:gate`; documentation alone is not the control. The issue
named by `stable-release` is closed only after that workflow publishes
successfully.

The deterministic dry-run proves clean local artifact installation and
rehearses a real Packagist-beta-to-local-candidate upgrade. It does not prove the
final stable-candidate upgrade until the exact release commit/version is tested
and the generated evidence is reviewed.

## Concurrency model

RaceProof coordinates request entry and named application checkpoints. It does
not control the operating-system scheduler, database lock scheduler, network,
or code after a checkpoint is released. It cannot discover races automatically,
replay an exact global schedule, prove the absence of races, or provide a formal
correctness proof.

It is a regression-test tool, not a production traffic generator, benchmark,
load tester, queue fuzzer, general distributed test coordinator, or remote
shell.

The required database matrix exercises 10- and 25-participant exchange
contention. Scheduled evidence extends that bounded cohort to 50 and 100
participants. These runs prove the recorded invariants for one same-host cohort;
they do not establish production throughput, latency, capacity, or behavior
under hundreds of distributed clients.

## Storage and process boundaries

The coordinator contract supports fail-closed `file` and `redis` drivers.
File-backed workers must see the same local coordination directory. Redis-backed
workers must reach the same single-node, access-controlled test Redis service,
and the configured TTL must exceed the race lifecycle.

The opt-in remote transport supports a static set of authenticated agents that
run the same application and share Redis plus the disposable database. It does
not support Redis Cluster, Sentinel, cross-region timing, automatic discovery,
autoscaling, retries, failover, arbitrary commands, or queue orchestration.
CI evidence is limited to two Ubuntu agent processes. Remote timing uses a
bounded Redis-time alignment sample; network asymmetry still limits
start-spread precision and remote results are not benchmark evidence.

Process termination is best effort at the operating-system boundary. CI tests
stop and reap workers, but agent or host failure can still require manual
cleanup. There is one active HMAC secret; rotate it only after in-flight controls
settle.

## Database boundaries

Release-level engine evidence is limited to MySQL 8.4 and PostgreSQL 17 on
Ubuntu. SQLite validates process mechanics only. Database-specific deadlock,
lock-timeout, isolation, trigger, replication, and proxy behavior can differ.
Tests must assert business invariants rather than vendor exception text.

RaceProof performs concurrent writes. It refuses production, is disabled by
default, rejects shared in-memory SQLite and open parent transactions by default,
and supports an exact database-name allowlist. These controls do not make an
incorrectly configured disposable database recoverable.

## Diagnostics and sensitive data

Known credentials, authorization/cookie headers, and configured keys are
redacted and bounded in diagnostics and reporters. Pattern redaction cannot
identify every application-specific secret. Response bodies, stack traces, and
retained failure artifacts may contain application data and require restricted
access and short retention.

## Platform boundaries

Ubuntu Linux receives the complete CI and database evidence. WSL2 is a
development target; macOS is best-effort and native Windows is experimental,
although both now run continuous independent-consumer smoke tests. Those smoke
jobs use file-backed SQLite and do not verify native MySQL/PostgreSQL behavior.
Disabled `proc_open`, read-only storage, antivirus interference, path
translation, Docker networking, and slow mounted volumes can prevent or distort
a run.

See [the compatibility policy](compatibility.md), [production safety](production-safety.md),
and [troubleshooting guide](troubleshooting.md) for the evidence required when a
boundary becomes a reproducible defect.
