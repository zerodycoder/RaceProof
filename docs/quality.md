# Quality policy

RaceProof coordinates destructive writes across independent processes, so trust is a product feature rather than a release afterthought.

## Required pull-request evidence

Every pull request must pass:

- the PHP 8.2 / Laravel 12 compatibility job;
- the PHP 8.5 / Laravel 13 compatibility job;
- PHPUnit unit and integration suites;
- the Pest real-process workflow contract;
- Pint formatting;
- PHPStan at level max;
- strict Composer validation and locked dependency audit;
- at least 90% executable-line coverage;
- the pre-release policy, matrix, mutation-hotspot, artifact, and blocker audit.

Coverage is a floor, not proof of correctness. Concurrency behavior also needs invariant assertions and repetition evidence.

The complete CI gate runs on Ubuntu Linux. MySQL 8.4 and PostgreSQL 17 are
release-evidence targets there. macOS and native Windows have the explicitly
weaker support levels documented in the [platform matrix](platform-support.md);
local smoke evidence must not be presented as continuous compatibility.

## Reliability evidence

Release-critical broken/fixed examples use a documented fixed repetition count:

- the broken implementation must expose the intended invariant violation on every repetition;
- the fixed implementation must preserve the invariant on every repetition;
- a timeout, missing result, or worker crash is a failure, not an acceptable retry.

The v1 release gate is 100/100 for each supported database and release-critical scenario.

## Review and merge

Large work is split into independently reviewable pull requests. A pull request is merged only when:

1. its scope and risk are documented;
2. the diff has been reviewed for correctness, safety, compatibility, and test quality;
3. all required CI checks are green;
4. review findings are resolved or explicitly accepted;
5. user-facing behavior and changelog entries are current.

When the repository has only one maintainer, GitHub does not permit that author to approve their own pull request. In that case, the maintainer records a `COMMENT` review with the same checklist and does not claim independent approval.

## Release evidence

Every release records its supported matrix, test counts, coverage percentage, database repetition results, known limitations, and migration notes. Artifacts that substantiate gates should remain attached to CI or the release record.

The frozen v1 surface is enforced by `composer api:check`. Release CI separately
builds reproducible Laravel/runtime archives, installs them together, and keeps
the runtime-first publication order explicit. See the [release runbook](releasing.md)
for signatures, provenance, Packagist verification, and rollback rules.

`composer beta:check` validates the bounded, consented public evidence registry
and verifies that its generated report is current. It deliberately does not make
the human evidence gate pass. `composer beta:gate` remains fail-closed until the
audited invitation, adoption, and resulting-fix thresholds in the
[private-beta runbook](private-beta.md) are actually met.

The final `release-audit` CI job depends on every PHP/Laravel, coverage,
release-dry-run, MySQL, and PostgreSQL job. It validates pinned workflow actions,
policy presence, the exact supported matrix, named tests for mutation-risk
hotspots, package evidence, and honest external blockers, then uploads
machine-readable evidence. See [the pre-release audit](release-audit.md).

`composer release:gate` is stricter and is invoked automatically for stable
tags. It cannot pass while the published upgrade, package publication, real beta
evidence, or resulting-fix gates remain blocked. Issue #20 records the release
workflow outcome and is deliberately not a circular pre-publication predicate.
