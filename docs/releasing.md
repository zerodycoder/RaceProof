# Release runbook

RaceProof is unreleased software. Do not create a public package or stable tag
until its applicable roadmap gates have evidence. The workflow is intentionally
fail-closed: missing secrets, an unsigned tag, a commit outside `main`, missing
CI, version drift, or absent Packagist metadata stops publication.

## One-time repository setup

Public Packagist requires public VCS repositories. Before the first beta:

1. make the reviewed source repository public;
2. create an empty generated runtime repository and set the `release`
   environment variable `RUNTIME_SPLIT_REPOSITORY` to its `owner/name`;
3. register both public repository URLs on Packagist;
4. configure `RUNTIME_SPLIT_TOKEN` with contents write access only to the
   generated runtime repository;
5. configure Packagist `PACKAGIST_USERNAME` and a safe update
   `PACKAGIST_API_TOKEN`;
6. configure `RELEASE_GPG_PRIVATE_KEY` and `RELEASE_GPG_PASSPHRASE`, publish the
   corresponding public key on the maintainer GitHub account, and protect the
   `release` environment;
7. enable `ENABLE_GITHUB_ATTESTATIONS=true` only when the repository visibility
   and GitHub plan support artifact attestations.

The runtime repository is generated output. Never edit it directly.

## Prepare the release PR

1. Choose a SemVer version using [the versioning policy](versioning.md).
2. Run `php tools/release/prepare.php X.Y.Z`. This aligns the local runtime path
   version, the Laravel-to-runtime constraint, and install documentation from one
   input.
3. Run `composer update raceproof/runtime --with-dependencies` and review the
   lock diff.
4. Move applicable `Unreleased` entries into
   `## [X.Y.Z] - YYYY-MM-DD`; add explicit upgrade/known-limitation notes.
5. Run:

   ```bash
   composer validate --strict
   composer --working-dir=runtime validate --strict
   composer audit --locked --no-interaction
   composer check
   composer release:audit
   composer release:dry-run
   php tools/release/validate.php X.Y.Z vX.Y.Z
   ```

6. Open a focused draft PR, record correctness/security/package-content review,
   and merge only when the exact head passes the PHP/Laravel matrix, coverage,
   release dry-run, MySQL, PostgreSQL, and dependent release-audit jobs.

`release:dry-run` builds both deterministic ZIP artifacts twice, compares their
SHA-256 hashes, installs them together through a Composer artifact repository,
and verifies that the production runtime checkpoint remains a no-op.

`release:audit` is a pre-release control audit, not permission to publish. It
keeps the published policies, matrix, mutation-risk registry, package evidence,
and open blockers synchronized. The dependent CI job runs only after every
other required job succeeds.

For a stable version, the release workflow also runs `composer release:gate`.
That command fails unless the prior published-artifact upgrade is verified,
the package-publication and beta gates named in
[`audit/release-audit.json`](../audit/release-audit.json) are marked with
audited evidence, and the beta registry itself satisfies its invitation,
adopter, consent, and resulting-fix gates. The `stable-release` issue tracks
the workflow outcome itself and is closed only after publication, so it is
reported but cannot be a pre-publication prerequisite.
Prerelease tags do not run the stable gate because a real beta release is needed
to collect that evidence.

## Publish

From a clean, current `main`, create and push an annotated GPG-signed tag:

```bash
git tag -s vX.Y.Z -m "RaceProof X.Y.Z"
git push origin vX.Y.Z
```

The release workflow then:

1. verifies the signature, main ancestry, aligned metadata, changelog, dependency
   audit, API snapshot, and successful required checks on the exact commit;
2. repeats the reproducible artifact install;
3. creates a runtime-only subtree commit, pushes it, and creates the matching
   signed runtime tag;
4. creates the runtime GitHub release, updates Packagist, and polls until the
   exact runtime version is visible;
5. signs `SHA256SUMS` and `provenance.json`, and creates a GitHub artifact
   attestation when explicitly enabled;
6. creates the Laravel GitHub release, updates Packagist, polls its exact
   metadata, then installs both packages from Packagist in a clean fixture.

The source monorepo remains authoritative. Runtime publication always precedes
Laravel publication.

## Verification after publication

- Verify both tag signatures and compare downloaded artifacts with
  `SHA256SUMS`.
- Verify GitHub attestations when enabled.
- Confirm Packagist source/dist references and dependency constraints point at
  the signed commits.
- Run the five-minute smoke scenario in a fresh supported Laravel application.
- Exercise an upgrade from the immediately previous published artifact. This
  remains blocked before the first real release; never simulate it by assigning
  two versions to the same source tree.
- Run `composer install --no-dev` in an instrumented fixture and confirm
  `race_point()` remains available and inactive.
- Record release links, CI run, hashes, compatibility evidence, and accepted
  limitations on the release issue.

## Rollback and partial failure

Published Composer versions and Git tags are immutable. Never force-move a tag or
replace a release asset under the same version.

- Before either Packagist version is visible: fix the release commit, delete only
  the unpublished failed tags/releases, and issue a new reviewed tag.
- If runtime is visible but Laravel is not: leave the runtime version immutable,
  diagnose the stopped workflow, and either complete the identical Laravel
  release or issue a new patch/pre-release for both packages. Record the partial
  release publicly.
- If Laravel is visible: do not delete or overwrite it. Mark the GitHub release
  and Packagist version as affected where supported, publish an advisory when
  security-relevant, and release a fixed patch.
- If credentials may be exposed: stop publication, rotate/revoke them, preserve
  audit evidence, and follow `SECURITY.md`.

Rerunning after an external publication step requires comparing the existing
tag commit and every artifact hash first. The workflow rejects a runtime tag that
does not resolve to the newly computed split commit.

## External specifications

- [Composer artifact repositories](https://getcomposer.org/doc/05-repositories.md#artifact)
  define the clean-install dry-run format.
- [Packagist API documentation](https://packagist.org/apidoc) defines authenticated
  package updates and the public metadata endpoint.
- [GitHub artifact attestations](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations)
  documents entitlement, permissions, generation, and verification.
