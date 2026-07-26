# Compatibility policy

RaceProof supports only combinations that satisfy its Composer constraints and
the operational requirements below. "Supported" means defects can be reported
and will be evaluated; it does not mean every cross-product receives an
independent CI job or that untested engine behavior is guaranteed.

## Language and framework

The package constraints are PHP `^8.2` and Laravel components
`^12.0 || ^13.0`. CI continuously verifies the two release edges:

| PHP | Laravel | Testbench | Purpose |
| --- | --- | --- | --- |
| 8.2 | 12 | 10 | Oldest supported edge |
| 8.5 | 13 | 11 | Newest supported edge |

Compatible intermediate PHP patch/minor versions and Composer-resolvable
cross-pairs are in support scope, but the two rows above are the continuously
verified evidence. A reported defect on another resolvable pair must include a
minimal reproduction and may result in a new matrix job before a compatibility
claim is expanded.

## Databases

MySQL 8.4 and PostgreSQL 17 on Ubuntu are release-evidence engines. Each runs
the complete deterministic database suite with an exact database-name allowlist
and 100/100 critical broken/fixed repetitions.

File-backed SQLite is a process-mechanics smoke target. It does not substantiate
row locks, deadlocks, isolation levels, or timeout behavior. In-memory SQLite is
rejected by default because independent workers cannot share it. Other database
versions and drivers are unverified until CI evidence is added.

## Operating systems and process model

The detailed levels live in [the platform matrix](platform-support.md):

- Ubuntu Linux is continuously verified;
- WSL2 is a primary development target but has no separate CI job;
- macOS is best-effort compatible with continuous consumer smoke evidence;
- native Windows is experimental with continuous consumer smoke evidence.

All platforms require `proc_open`, a usable CLI PHP binary, local writable
coordination storage, and one disposable database reachable by every worker.
Network filesystems, containers, antivirus, and database networking can change
timing and are environment-specific.

The macOS and Windows jobs use PHP 8.4, Laravel 12, and file-backed SQLite to
verify installation and process mechanics. They do not expand the database
release matrix: MySQL 8.4 and PostgreSQL 17 evidence remains Ubuntu-only.

## Compatibility promise

The frozen symbols in [the public API contract](public-api.md) follow
[semantic versioning and deprecation policy](versioning.md). Worker plans,
timeline events, and reports carry independent schema versions; package-version
compatibility never implies that an unknown schema version can be interpreted.

Support may be narrowed only through the documented versioning process, except
when an upstream release is unsupported or a security boundary cannot be
maintained safely. Every such change needs an upgrade note and release evidence.
