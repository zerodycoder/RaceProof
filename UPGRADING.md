# Upgrading RaceProof

No tagged release exists yet. This file becomes the authoritative migration
index when the first beta is published.

## Toward 1.0

- Application checkpoints belong to `raceproof/runtime` in production
  `require`; keep `raceproof/laravel` in `require-dev`.
- Use `race_point()` or `RaceProof\Runtime\Checkpoint::sync()` in application
  code. The Laravel facade and internal activation bridge are not part of the
  frozen v1 contract.
- Treat JSON and timeline `schema_version` independently from the Composer
  package version.
- Review [the frozen public API](docs/public-api.md), [versioning policy](docs/versioning.md),
  and [runtime deployment guide](docs/runtime-checkpoints.md) before adopting a
  pre-release.

Every future release section must list required code/configuration changes,
deprecated paths, and safe rollback constraints. “No migration required” must be
stated explicitly when true.
