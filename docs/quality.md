# Quality policy

RaceProof coordinates destructive writes across independent processes, so trust is a product feature rather than a release afterthought.

## Required pull-request evidence

Every pull request must pass:

- the PHP 8.2 / Laravel 12 compatibility job;
- the PHP 8.5 / Laravel 13 compatibility job;
- PHPUnit unit and integration suites;
- Pint formatting;
- PHPStan at level max;
- strict Composer validation and locked dependency audit;
- at least 90% executable-line coverage.

Coverage is a floor, not proof of correctness. Concurrency behavior also needs invariant assertions and repetition evidence.

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
