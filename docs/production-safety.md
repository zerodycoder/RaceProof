# Production safety

RaceProof refuses to execute in Laravel's `production` environment even when its enabled flag is set. Outside `testing`, a second explicit `RACEPROOF_ALLOW_NON_TESTING=1` gate is required. The worker command is hidden, accepts only validated run/participant identifiers, and reads plans from the configured local coordinator.

## Production checkpoints

Applications containing `race_point()` calls must list `raceproof/runtime` directly in Composer `require`. Keep `raceproof/laravel` in `require-dev`. The runtime is no-op by default and has no Laravel, process, command, coordinator, filesystem, deserialization, or network surface.

A header or environment flag cannot activate checkpoints. Only the validated worker command installs a process-local handler, and cleanup requires its exact activation capability. Arbitrary PHP execution is outside this boundary because it already controls the process.

See [runtime checkpoint deployment](runtime-checkpoints.md) and [ADR 0001](adr/0001-separate-runtime-checkpoint-package.md).

## Secrets

Only configured response headers are captured. Authorization, cookies, and set-cookie headers are redacted if explicitly allowlisted. Response bodies are capped.

Exception diagnostics and worker stdout/stderr are redacted before their byte limits are applied. RaceProof removes authorization/cookie header values, bearer tokens, and values whose keys match `raceproof.capture.redact_keys`. Pattern-based redaction is defense in depth and cannot classify every application-specific secret or sensitive response body. Workers must not print credentials or tokens. Retained run directories should be treated as CI artifacts with restricted access and short retention.
