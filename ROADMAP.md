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

- [ ] Worker liveness, orphan cleanup, and crash diagnostics
- [ ] Actionable console and retained timeline reporting
- [ ] Participant bootstrap contract
- [ ] Resolve runtime checkpoint packaging without requiring the dev package in production

### v0.3 — database fidelity

- [ ] MySQL CI and deterministic broken/fixed scenarios
- [ ] PostgreSQL CI and deterministic broken/fixed scenarios
- [ ] Coupon, wallet, quote, constraint, lock misuse, and timeout/deadlock examples
- [ ] 100-repetition reliability evidence for release-critical scenarios

### v0.4 — developer experience

- [ ] Per-participant payload, headers, identity, and setup
- [ ] Session, token, and Sanctum authentication coverage
- [ ] PHPUnit and Pest documentation
- [ ] Human, JSON, and JUnit-compatible reports
- [ ] Five-minute guides and troubleshooting playbook

### v1.0 — trusted stable release

- [ ] Stable public API and semantic-versioning policy
- [ ] Automated tagged releases and Packagist publication
- [ ] Four polished end-to-end demonstrations
- [ ] Public beta evidence and adoption gates
- [ ] Final security, compatibility, reliability, and documentation audit

## Later, based on adoption

Redis coordination, network mode, queue races, interleaving fuzzing, exact schedule control, and an HTML timeline are intentionally post-v1 candidates. They should be prioritized from user evidence, not added to inflate scope.
