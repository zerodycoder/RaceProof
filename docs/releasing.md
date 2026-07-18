# Release checklist

RaceProof is pre-release software. Do not publish a stable tag until every applicable gate in [ROADMAP.md](../ROADMAP.md) is supported by evidence.

## Prepare

- [ ] Choose the version according to semantic versioning and document any compatibility break.
- [ ] Move relevant entries from `Unreleased` into a dated changelog section.
- [ ] Confirm the supported PHP, Laravel, operating-system, and database matrix.
- [ ] Run `composer validate --strict`, `composer audit --locked`, and `composer check`.
- [ ] Confirm the coverage job is at or above 90% and retain its Clover artifact.
- [ ] Confirm every required MySQL/PostgreSQL repetition run meets its documented target.
- [ ] Review production refusal, database allowlist, path safety, process cleanup, and secret redaction.
- [ ] Verify installation and the five-minute example in a fresh Laravel application.
- [ ] Document known limitations and upgrade steps without overstating guarantees.

## Review

- [ ] Open a release pull request containing only version, changelog, compatibility, and release metadata.
- [ ] Record a correctness and security review.
- [ ] Require every CI check to pass on the exact release commit.
- [ ] Confirm the release commit is on `main` and the working tree is clean.

## Publish

- [ ] Create a signed or GitHub-verified `vX.Y.Z` tag on the reviewed commit.
- [ ] Generate GitHub release notes and include test, coverage, database, and compatibility evidence.
- [ ] Publish to Packagist only after the GitHub release is visible.
- [ ] Install the published version in a clean fixture and rerun the smoke scenario.

## After release

- [ ] Verify Packagist metadata and installation constraints.
- [ ] Open the next `Unreleased` changelog section.
- [ ] Announce security or migration requirements prominently.
- [ ] Record follow-up issues for every accepted limitation; do not hide release debt in prose.

Release automation planned for v1 should implement this checklist without removing the manual evidence review.
