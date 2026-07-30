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
- isolated Laravel consumer installation and acceptance;
- a real single-node Redis coordinator contract plus an independent
  multi-process consumer using that backend;
- native database/Redis queue races with isolated cleanup and payload-free
  evidence;
- an authenticated remote-control contract plus the same consumer race through
  two independently running, capacity-bounded agents;
- independent consumer smoke acceptance on GitHub-hosted macOS and Windows;
- at least 90% executable-line coverage;
- at least 80% strict covered-code mutation score across the selected
  fail-closed safety/redaction boundaries and the orchestration, coordination,
  and report-projection paths, with timeouts counted in the denominator but
  never as tested mutants;
- the pre-release policy, matrix, mutation-hotspot, artifact, and blocker audit.

Coverage is a floor, not proof of correctness. Concurrency behavior also needs invariant assertions and repetition evidence.

The complete CI gate and database release evidence run on Ubuntu Linux. The
independent Laravel consumer flow also runs continuously on GitHub-hosted macOS
and native Windows. Those smoke jobs verify installation, CLI process mechanics,
file-backed SQLite, and Studio behavior; they do not constitute native
MySQL/PostgreSQL release evidence or upgrade either platform's documented
support level.

`composer test:mutation` requires Xdebug or PCOV and mutates nineteen explicitly
selected files: the environment/database/redaction fail-closed boundaries,
worker process and orchestration lifecycle, coordinator selection,
file/Redis coordination integrity, authenticated remote transport selection,
message/configuration/control-state/process boundaries, queue specification,
connection/job validation, dispatch/execution lifecycle, and stable report
projection. The command uses
repository-relative paths so it
selects the same files on Windows and POSIX shells. It uses only covered lines,
fails below 80%, and does not ignore an empty mutation set. A second fail-closed
checker parses the retained report and divides tested mutants by all tested,
untested, and timed-out mutants, so a slow mutant cannot inflate the accepted
score. It also handles Pest's compact output when zero-value categories are
omitted, while still rejecting missing, duplicate, or empty totals. CI retains
the complete text report for 30 days. This is a targeted quality gate, not a
repository-wide mutation score, and production code is never annotated merely
to suppress surviving mutants.

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

The release dry-run also installs the published beta packages in an isolated
Laravel application, upgrades both to candidate artifacts built from the exact
head, and repeats a bounded Doctor/runtime/race smoke. This remains rehearsal
evidence until the final candidate version is selected and reviewed.

`composer beta:check` validates the bounded, consented public evidence registry
and verifies that its generated report is current. It deliberately does not make
the human evidence gate pass. `composer beta:gate` remains fail-closed until the
audited invitation, adoption, and resulting-fix thresholds in the
[private-beta runbook](private-beta.md) are actually met.

The isolated consumer job also requires a clean tracked fixture after its real
worker, scaffold, session, and Studio cleanup paths run. Generated consumer
state may not be hidden by accepting a dirty worktree.

The Redis job additionally generates its remote authentication secret at
runtime, starts two registered agent processes, waits for Doctor to verify both
heartbeats, executes the isolated consumer workflow through the remote
transport, and retains agent logs, Doctor JSON, and JUnit evidence for 30 days.
The same job repeats the consumer's native queued-job invariant through Redis
and retains bounded payload-free queue evidence. This proves the bounded
Ubuntu/single-node topology only.

The final `release-audit` CI job depends on every PHP/Laravel, coverage,
targeted-mutation, Linux/macOS/Windows consumer, Redis, release-dry-run, MySQL,
and PostgreSQL job. It validates pinned workflow actions, policy presence, the
exact supported matrix, named tests for mutation-risk hotspots, package
evidence, and honest external blockers, then uploads machine-readable evidence.
See [the pre-release audit](release-audit.md).

`composer release:gate` is stricter and is invoked automatically for stable
tags. It cannot pass while the published upgrade, package publication, real beta
evidence, or resulting-fix gates remain blocked. The `stable-release` gate in
[`audit/release-audit.json`](../audit/release-audit.json) records the workflow
outcome and is deliberately not a circular pre-publication predicate.
