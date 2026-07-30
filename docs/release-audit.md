# Pre-release audit status

This is a reproducible pre-release audit, not stable-release approval. It inventories executable controls, supported evidence, policies, artifact checks, and unresolved external gates from [`audit/release-audit.json`](../audit/release-audit.json).

Audit definition prepared: 2026-07-30

## Automated controls and mutation-risk hotspots

| Control | Mutation hotspot | Scope | Test methods |
| --- | --- | --- | ---: |
| `environment-database-safety` | yes | Production refusal, explicit local opt-in, open transactions, shared SQLite, and exact database allowlists. | 11 |
| `worker-lifecycle` | yes | Spawn failures, early exits, timeouts, stop/wait ordering, orphan prevention, cleanup, and retained failure evidence. | 13 |
| `redaction-reporting` | yes | Credential patterns, invalid UTF-8, byte bounds, response/report projection, and valid JSON/XML after redaction. | 12 |
| `coordination-integrity` | yes | Fail-closed backend selection, parent/worker parity, credential-safe process arguments, atomic file and Redis transitions, bounded Redis retention, ordered timeline recovery, and backend-neutral cleanup boundaries. | 18 |
| `remote-worker-transport` | yes | Fail-closed transport selection, authenticated expiring controls, atomic replay protection, bounded routing/capacity/liveness/shutdown/output, Redis-aligned timing, and independent-agent evidence. | 13 |
| `queue-race-orchestration` | yes | Fail-closed database/Redis queue selection, distinct job cardinality, native bounded retries, reservation-before-release, exact run-scoped cleanup, redacted evidence, and real engine/consumer CI. | 30 |
| `production-runtime-boundary` | yes | Capability-scoped activation and a framework-free, process-free production no-op runtime. | 2 |
| `release-supply-chain` | yes | Reproducible consumer archives, a real published-beta upgrade rehearsal, pinned workflow actions, runtime-first publication, signatures, provenance, and clean artifact installation. | 4 |
| `published-contracts` | no | Frozen API signatures, documentation links, executable examples, public evidence status, and package-content boundaries. | 3 |
| `consumer-acceptance` | no | Clean Laravel installation, idempotent setup, child-process diagnostics, package discovery, participant identity, authentication, CLI workflows, real HTTP/database/Redis queue races, and Studio HTTP behavior. | 4 |

These entries identify mutation-sensitive branch, timeout, cleanup, redaction, serialization, and packaging decisions and bind each one to named tests. CI additionally enforces an 80% strict covered-code mutation score for nineteen selected safety, redaction, worker-lifecycle, orchestration, coordinator-selection, file/Redis-coordination, authenticated-remote-transport, queue-lifecycle, and report-projection classes through `composer test:mutation`; timeouts remain in its denominator and are never accepted as tested mutants. This remains targeted evidence, so do not claim a repository-wide mutation score.

## Compatibility evidence

| Dimension | Continuously verified evidence |
| --- | --- |
| PHP | 8.2, 8.5 |
| Laravel | 12, 13 |
| Database | mysql:8.4, pgsql:17 |

Platform levels:

- Ubuntu Linux: continuous.
- WSL2: development.
- macOS: best-effort.
- Native Windows: experimental.

GitHub-hosted macOS and native Windows runners continuously execute the independent PHP 8.4 consumer smoke. Database release evidence remains specific to Ubuntu.

The exact meaning and boundaries of these levels are in [the compatibility policy](compatibility.md).

## Published policies

| Policy | Document |
| --- | --- |
| compatibility | [`docs/compatibility.md`](compatibility.md) |
| upgrade | [`UPGRADING.md`](../UPGRADING.md) |
| security | [`SECURITY.md`](../SECURITY.md) |
| maintenance | [`docs/maintenance.md`](maintenance.md) |
| known limitations | [`docs/known-limitations.md`](known-limitations.md) |

## Artifact paths

- Fresh install from deterministic Laravel/runtime ZIP artifacts: **automated** by `composer release:dry-run`.
- Upgrade from a previously published artifact: **pending-from-published-beta**. `v1.0.0-beta.1` is now the published baseline; a subsequent release must exercise the real upgrade.

## External release gates and outcome

| Gate | Tracking issue | Status |
| --- | ---: | --- |
| `public-package-publication` | [#2](https://github.com/zerodycoder/RaceProof/issues/2) | verified |
| `beta-adoption-evidence` | [#3](https://github.com/zerodycoder/RaceProof/issues/3) | blocked |
| `stable-release` | [#4](https://github.com/zerodycoder/RaceProof/issues/4) | blocked |

Package publication gate #2 is **verified**. Stable publication remains prohibited until beta-adoption gate #3 is verified and the published-artifact upgrade path exists. Issue #4 records the stable workflow outcome and is closed only after publication succeeds; it is reported here but is not a circular pre-publication predicate.
