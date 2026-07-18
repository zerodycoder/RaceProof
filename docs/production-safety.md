# Production safety

RaceProof refuses to execute in Laravel's `production` environment even when its enabled flag is set. Outside `testing`, a second explicit `RACEPROOF_ALLOW_NON_TESTING=1` gate is required. The worker command is hidden, accepts only validated run/participant identifiers, and reads plans from the configured local coordinator.

## Dev dependency and application checkpoints

PHP `use` statements do not load a class, but executing `RacePoint::sync()` does. If the package was installed with `--dev`, that class is absent after a production `composer install --no-dev` and the application will fail when the line executes.

Choose one model:

1. Keep the package installed as a normal dependency. `RacePoint` is a no-op without an active worker and the runner stays blocked in production.
2. Keep RaceProof dev-only and use a guarded helper:

   ```php
   function_exists('race_point') && race_point('stock-read');
   ```

3. Hide instrumentation behind an application-owned abstraction whose production implementation is a no-op.

The project may later extract a tiny runtime bridge, but the MVP does not pretend a missing class can be handled by a service provider.

## Secrets

Only configured response headers are captured. Authorization, cookies, and set-cookie headers are redacted if explicitly allowlisted. Response bodies are capped.

Exception diagnostics and worker stdout/stderr are redacted before their byte limits are applied. RaceProof removes authorization/cookie header values, bearer tokens, and values whose keys match `raceproof.capture.redact_keys`. Pattern-based redaction is defense in depth and cannot classify every application-specific secret or sensitive response body. Workers must not print credentials or tokens. Retained run directories should be treated as CI artifacts with restricted access and short retention.
