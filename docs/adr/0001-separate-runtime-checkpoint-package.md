# ADR 0001: separate runtime checkpoint package

- Status: accepted
- Date: 2026-07-18
- Deciders: RaceProof maintainers
- Supersedes: [RFC 0001](../rfcs/0001-runtime-checkpoint-packaging.md)

## Decision

Ship production-safe checkpoint instrumentation as a separate `raceproof/runtime` Composer package. The runtime source lives in `runtime/` in this monorepo and is released to its own package/repository through a subtree split. `raceproof/laravel` depends on the matching runtime minor and remains a development dependency in applications.

The runtime contains only:

- a no-op-by-default `Checkpoint::sync()` API;
- the global `race_point()` helper;
- a minimal handler contract;
- a process-local activation capability.

It has no Laravel, Symfony Process, console command, coordinator, filesystem, network, or test dependency.

## Activation and threat model

The runtime never reads environment variables, headers, cookies, request input, files, or network state. It becomes active only when the main package's validated worker command installs an in-memory handler. Deactivation requires the exact capability object returned during activation.

This prevents an external request from forging worker mode through a header or environment value. It does not attempt to defend against arbitrary PHP code execution inside the application process; code execution already controls the process. Participant bootstrap classes are application-authored trusted test code and run only after environment and plan validation.

Plans and bootstrap configuration cross the process boundary as JSON. Closures, objects, resources, non-finite numbers, PHP serialization, and `unserialize()` are not accepted. Coordinator directories remain local, permission-restricted test artifacts.

## Packaging proof

The runtime manifest requires only PHP 8.2+. CI validates it independently, includes its source in PHPStan and coverage, scans for forbidden framework/process/serialization dependencies, and executes a no-op checkpoint in bare PHP with extensions disabled. Real worker integration tests prove activation and cleanup through the main package.

## Compatibility and release ownership

- Both packages share release versions; `raceproof/laravel` requires the matching
  runtime release as the floor of a compatible major-version range.
- Runtime is tagged and published before the matching main package.
- The monorepo is the source of truth; the runtime release repository is generated, not edited directly.
- Runtime API removals require a major version. Additive handler/runtime behavior follows the main package's release policy.

## Alternatives rejected

1. Full package in production: safe when disabled, but ships process execution and console surface unnecessarily.
2. Guarded dev-only helper: operationally valid but leaves instrumentation conditional and easy to misconfigure.
3. Generated application shim: creates unowned copied code and upgrade drift.

## Consequences

Applications with checkpoints add one tiny production dependency. Applications without checkpoints need only the main dev package. Existing main-package facade imports must migrate to the runtime helper/class before moving the main package to `require-dev`.
