# Known limitations

These boundaries are part of the product contract. They are not hidden roadmap
promises and must not be overstated in release material.

## Distribution and evidence status

- Both packages are registered on Packagist and expose `dev-main`, but no signed,
  tagged GitHub or Packagist release exists yet.
- The generated runtime repository is a public subtree split. The main RaceProof
  repository remains its only source of truth.
- The published-artifact upgrade path cannot be tested because there is no prior
  published release.
- Private-beta evidence currently records 0/10 invited projects and 0/5
  confirmed adopters.
- Stable publication is blocked by the package-publication and beta-adoption
  gates in [`audit/release-audit.json`](../audit/release-audit.json) and the
  missing published-upgrade baseline; the `stable-release` gate tracks the
  eventual workflow outcome.

The stable release workflow enforces the evidence and upgrade boundaries with
`composer release:gate`; documentation alone is not the control. The issue
named by `stable-release` is closed only after that workflow publishes
successfully.

The deterministic artifact dry-run proves a clean local Composer artifact
installation. It is not evidence of GitHub/Packagist publication or an upgrade
from a real prior version.

## Concurrency model

RaceProof coordinates request entry and named application checkpoints. It does
not control the operating-system scheduler, database lock scheduler, network,
or code after a checkpoint is released. It cannot discover races automatically,
replay an exact global schedule, prove the absence of races, or provide a formal
correctness proof.

It is a regression-test tool, not a production traffic generator, benchmark,
load tester, queue fuzzer, or distributed test coordinator.

## Storage and process boundaries

The coordinator uses local files and independent CLI PHP processes. Every worker
must see the same local coordination directory and database. Network filesystems,
multi-host execution, Redis coordination, queues, and remote workers are outside
v1. Process termination is best effort at the operating-system boundary; CI
tests stop and reap workers, but host failure can still require manual cleanup.

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
