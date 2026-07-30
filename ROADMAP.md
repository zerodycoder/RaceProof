# RaceProof roadmap

## Mission

RaceProof aims to become the default Laravel toolkit for turning real intermittent race conditions into controlled, reproducible regression tests.

The north-star metric is the number of real race conditions converted into stable regression tests in real Laravel applications.

## Release gates

A stable v1 requires all of the following:

- a first useful race test can be written in under five minutes;
- independent Laravel workers share a dedicated test database safely;
- explicit checkpoints reliably expose broken behavior and verify the fix;
- failures include actionable participant, timing, status, exception, and artifact evidence;
- MySQL and PostgreSQL run as first-class CI targets;
- critical broken scenarios fail 100/100 repetitions and their fixes pass 100/100;
- the supported matrix, public API, upgrade policy, and security policy are documented;
- at least four complete broken/fixed demonstrations exist;
- beta feedback includes at least ten invited projects and five confirmed adopters.

## Milestones

### v0.1 — quality foundation

- [x] Technical MVP with independent workers, barriers, results, and safety guards
- [x] Enforced 90% line coverage
- [x] Contribution, security, support, review, and issue workflows
- [x] Evidence-backed quality policy and release checklist

### v0.2 — reliable core

- [x] Worker liveness, orphan cleanup, and crash diagnostics
- [x] Actionable console and retained timeline reporting
- [x] Participant bootstrap contract
- [x] Resolve runtime checkpoint packaging without requiring the dev package in production

### v0.3 — database fidelity

- [x] MySQL CI and deterministic broken/fixed scenarios
- [x] PostgreSQL CI and deterministic broken/fixed scenarios
- [x] Coupon, wallet, quote, constraint, lock misuse, and timeout/deadlock examples
- [x] 100-repetition reliability evidence for release-critical scenarios

### v0.4 — developer experience

- [x] Per-participant payload, headers, identity, and setup
- [x] Session, token, and Sanctum authentication coverage
- [x] PHPUnit and Pest documentation
- [x] Human, JSON, and JUnit-compatible reports
- [x] Five-minute guides and troubleshooting playbook
- [x] Idempotent installer and machine-readable child-process diagnostics

### v0.5 — visual evidence

- [x] Opt-in local/testing-only report archive with bounded retention
- [x] Browser-validated responsive Studio dashboard and participant/checkpoint lanes
- [x] Safe PHPUnit/Pest test scaffolding and report inspection commands
- [x] Production route, read, and write rejection with redacted JSON export
- [x] Isolated Laravel consumer acceptance for installation, auth, CLI, and Studio DX
- [x] Continuous macOS/Windows consumer smoke and targeted mutation-quality gates

### v1.0 — trusted stable release

- [x] Stable public API and semantic-versioning policy
- [x] Automated tagged releases and Packagist publication
- [x] Four polished end-to-end demonstrations
- [ ] Public beta evidence and adoption gates
- [ ] Final security, compatibility, reliability, and documentation audit

### Enterprise foundations â€” pre-adoption hardening

- [x] Backend-neutral coordinator lifecycle with fail-closed driver selection
- [x] Redis coordination with atomic operations, namespaces, TTLs, and CI
- [x] Remote worker transport with authenticated, bounded control messages
- [x] Queue race orchestration with explicit job and retry invariants
- [ ] Interleaving exploration and exact schedule controls with honest limits

This track can harden architecture while external adoption is unavailable, but
it does not satisfy or weaken the stable-v1 adoption gate. Each distributed
capability requires its own evidence before support claims expand.

## Enterprise sequencing

Pluggable file/Redis coordination, local/remote worker transport, and bounded
database/Redis queue races now share orchestration, worker boot, diagnostics,
cleanup, and evidence semantics. Schedule exploration remains separate so it
can be reviewed, rolled back, and evidenced independently.
