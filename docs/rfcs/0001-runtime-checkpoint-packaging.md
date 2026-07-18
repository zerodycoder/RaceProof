# RFC 0001: runtime checkpoint packaging

- Status: accepted and superseded by ADR
- Decision target: v0.2
- Decision: [ADR 0001](../adr/0001-separate-runtime-checkpoint-package.md)

## Context

An application that calls the `RacePoint` facade must be able to load that symbol in production even though no race is active. Installing all of RaceProof as a development dependency cannot provide that guarantee. Installing the full orchestration package in production works, but unnecessarily exposes test-only dependencies and commands.

## Proposed direction

Publish a tiny runtime package containing only the no-op-safe checkpoint API and its minimal contracts. The main `raceproof/laravel` package remains a development dependency and activates the runtime API only inside an authenticated local worker context.

## Required properties

- production calls are no-ops unless an unforgeable worker context is active;
- no process spawning, console commands, coordinator cleanup, or test dependencies ship in runtime;
- the bridge has no network behavior and does not deserialize executable data;
- applications can deploy without conditional `class_exists` or `function_exists` guards;
- version compatibility between runtime and orchestrator is explicit and tested.

## Alternatives to evaluate

1. Keep the full package as a production dependency.
2. Require guarded helper calls for dev-only installation.
3. Generate an application-owned shim during installation.

The v0.2 decision must include a threat model, upgrade path, package ownership plan, and a working integration test before this RFC becomes an ADR.

All required properties were proven in #10 and the separate runtime-package direction was accepted on 2026-07-18.
