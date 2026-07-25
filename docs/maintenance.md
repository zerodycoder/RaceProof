# Maintenance policy

RaceProof is currently pre-release software maintained on a best-effort basis.
There is no response-time SLA. Stable-version maintenance starts only after a
signed stable release is actually published.

## Supported lines

Before v1, fixes target the latest commit on `main`; old commits and hypothetical
prerelease versions are not maintained as separate lines. After v1, the latest
minor line receives compatible bug fixes and security fixes. An older line is
supported only when the release notes explicitly list an end-of-support date.

No release line is silently declared supported. `SECURITY.md`, release notes,
and the version table must agree before publication.

## Change intake

- Reproducible defects and focused feature proposals use GitHub issues.
- Security vulnerabilities use GitHub private vulnerability reporting.
- Compatibility defects must include the bounded environment evidence required
  by [the compatibility policy](compatibility.md).
- Concurrency fixes require a deterministic broken invariant and a fixed case at
  the documented repetition target.

Maintainers may close requests that are support questions, load testing,
production traffic generation, automatic race detection, or post-v1 ideas
without evidence. This keeps the safety and reliability surface reviewable.

## Dependencies and release hygiene

Dependency updates are validated by strict Composer metadata, locked advisory
audit, the PHP/Laravel matrix, PHPStan max, coverage, real database jobs, and the
release dry-run. GitHub Actions are pinned to full commit SHAs. Release artifacts
are reproducible, checksummed, signed, and immutable once published.

Security fixes take priority over feature work. If a supported dependency cannot
be updated safely, the affected release is documented and a compatible patch or
support change is published through the normal reviewed workflow.

## Deprecation and end of support

Public API deprecations follow [the versioning policy](versioning.md). A support
line is retired only in a dated release note and documentation update. When a
security issue makes continued support unsafe, maintainers may shorten the
window, explain the boundary without exposing exploit details, and direct users
to a fixed version.
