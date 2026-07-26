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

### v0.5 — visual evidence

- [x] Opt-in local/testing-only report archive with bounded retention
- [x] Browser-validated responsive Studio dashboard and participant/checkpoint lanes
- [x] Safe PHPUnit/Pest test scaffolding and report inspection commands
- [x] Production route, read, and write rejection with redacted JSON export
- [x] Isolated Laravel consumer acceptance for installation, auth, CLI, and Studio DX

### v1.0 — trusted stable release

- [x] Stable public API and semantic-versioning policy
- [ ] Automated tagged releases and Packagist publication
- [x] Four polished end-to-end demonstrations
- [ ] Public beta evidence and adoption gates
- [ ] Final security, compatibility, reliability, and documentation audit

## Later, based on adoption

Redis coordination, network mode, queue races, interleaving fuzzing, and exact
schedule control are intentionally post-v1 candidates. They should be
prioritized from user evidence, not added to inflate scope.
