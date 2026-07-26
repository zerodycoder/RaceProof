# Public API

The v1 API candidate is frozen on `main` and published as `v1.0.0-beta.1`.
Packagist availability is verified, but the stable compatibility guarantee
begins at `1.0.0`; beta changes still require an explicit reviewed contract
update.

The machine-readable contract is [api/public-api.json](../api/public-api.json).
`composer api:check` reflects the installed code and fails when any frozen
signature changes. `composer check` and both supported CI matrix jobs run that
guard.

## Stable entry points

The global `race()` helper returns `RaceBuilder`. Its fluent API covers:

- participant count and the JSON request;
- shared headers, cookies, bearer tokens, model identity, and bootstrap;
- per-participant overrides through `ParticipantBuilder`;
- start/checkpoint release coordination;
- execution through `run()`.

`RaceResult` and its participant/timeline values expose the documented status,
timing, failure, assertion, JSON, and custom-reporter surfaces. The stable
extension contracts are `ParticipantBootstrap` and `Reporter`. Human, JSON, and
JUnit reporter `report()` methods are stable and reporters are resolved through
Laravel's container.

The exception hierarchy rooted at `RaceProofException` is stable so callers can
catch all package errors or a documented specific failure.

Application instrumentation uses the global `race_point()` helper or
`RaceProof\Runtime\Checkpoint::sync()`. `Checkpoint::active()` is a stable
diagnostic. The runtime activation handler and capability remain internal even
though the aligned Laravel package must access them.

The full property and method list is intentionally kept in the machine baseline
instead of duplicated here.

## Internal by default

Any symbol or public PHP method absent from the baseline is an implementation
detail. PHP visibility alone does not make it supported: framework construction,
serialization, worker coordination, commands, support utilities, and the
runtime activation bridge are internal unless explicitly listed.

Methods marked `@internal` are excluded from the snapshot. Removing that marker,
adding a method to a frozen type, or changing a parameter/property/return type
changes the generated snapshot and requires an intentional compatibility review.

## Changing the contract

1. Run `php tools/api/check.php --print` and review the exact signature delta.
2. Apply the rules in [versioning and deprecation](versioning.md).
3. Update documentation, upgrade notes, and tests in the same pull request.
4. Only after review, run `php tools/api/check.php --write`.
5. Record whether the change is additive, deprecated, or breaking in the
   changelog.

Refreshing the baseline is not evidence that a change is compatible. The pull
request review must make that determination.
