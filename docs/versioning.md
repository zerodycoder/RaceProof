# Versioning and deprecation policy

Both `raceproof/laravel` and `raceproof/runtime` follow Semantic Versioning and
share one release version. A release tag `vX.Y.Z` produces both package versions;
the runtime is tagged and visible on Packagist before the Laravel package is
published.

## Compatibility boundaries

For `1.x`:

- patch releases contain compatible fixes and documentation improvements;
- minor releases may add optional methods, result fields, configuration keys, or
  report fields without changing existing meaning;
- a major release is required to remove or rename a frozen symbol, add a
  required argument, narrow accepted input, widen thrown failure conditions in
  normal valid use, or change documented behavior incompatibly;
- PHP, Laravel, database, or operating-system support may be removed only in a
  major release unless the upstream version is unsupported and retaining it
  would create a documented security risk;
- JSON/timeline schema changes use their own explicit schema versions. A package
  minor must not silently mutate an existing schema version.

Bug fixes may reject input that was already documented as invalid or unsafe.
Security fixes may require a narrower exception, but the release notes must call
out the impact.

Symbols absent from [the public API contract](public-api.md) are internal and may
change in a minor release. Configuration keys documented for users and CLI
command names/options are treated as public even where PHP reflection cannot
snapshot them.

## Deprecation lifecycle

A supported API is deprecated before removal:

1. mark the symbol `@deprecated` with its replacement and target removal major;
2. document the migration in `UPGRADING.md` and the changelog;
3. emit at most one `E_USER_DEPRECATED` notice per process when the deprecated
   path is executed, without including secrets or request data;
4. retain tests for both the old and replacement paths;
5. remove it no earlier than the next major release.

An immediately exploitable security issue can bypass the normal window. The
security advisory and release notes must explain why and provide the safest
available migration.

## Pre-releases

Alpha, beta, and release-candidate identifiers follow SemVer, for example
`1.0.0-beta.1`. Pre-release users must allow the matching Composer stability.
No stable compatibility promise applies before `1.0.0`, but the frozen v1
candidate is still guarded so beta feedback produces explicit reviewed changes
instead of silent drift.
