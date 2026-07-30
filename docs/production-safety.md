# Production safety

RaceProof refuses to execute in Laravel's `production` environment even when its enabled flag is set. Outside `testing`, a second explicit `RACEPROOF_ALLOW_NON_TESTING=1` gate is required. The worker command is hidden, accepts validated run/participant identifiers plus a non-secret driver name for parent/worker parity, and resolves its coordinator through application configuration; coordinator paths and connection details are not accepted as command arguments.

## Production checkpoints

Applications containing `race_point()` calls must list `raceproof/runtime` directly in Composer `require`. Keep `raceproof/laravel` in `require-dev`. The runtime is no-op by default and has no Laravel, process, command, coordinator, filesystem, deserialization, or network surface.

A header or environment flag cannot activate checkpoints. Only the validated worker command installs a process-local handler, and cleanup requires its exact activation capability. Arbitrary PHP execution is outside this boundary because it already controls the process.

See [runtime checkpoint deployment](runtime-checkpoints.md) and [ADR 0001](adr/0001-separate-runtime-checkpoint-package.md).

## Secrets

Only configured response headers are captured. Authorization, cookies, and set-cookie headers are redacted if explicitly allowlisted. Response bodies are capped.

Exception diagnostics and worker stdout/stderr are redacted before their byte limits are applied. RaceProof removes authorization/cookie header values, bearer tokens, and values whose keys match `raceproof.capture.redact_keys`. Pattern-based redaction is defense in depth and cannot classify every application-specific secret or sensitive response body. Workers must not print credentials or tokens. Retained run directories should be treated as CI artifacts with restricted access and short retention.

Request credentials cannot be redacted from `plan.json` because workers need their exact header and cookie values. Coordinator files are permissioned to `0600` where supported, but failed runs are retained. Authentication tests should use disposable, least-privilege tokens and sessions, and retained artifacts must be removed promptly after diagnosis.

The Redis driver stores that same exact plan in the selected Redis service.
Use a dedicated test connection with least-privilege ACLs and transport security,
restrict backup and monitoring access, and keep the bounded TTL short. The
namespace prevents collisions but is not a security boundary. Redis connection
credentials remain in Laravel's database configuration and are never accepted
as RaceProof command arguments or emitted in coordinator diagnostics.

Human, JSON, and JUnit [evidence reporters](reporters.md) re-apply configured redaction and field limits. Human output is capped as a whole; structured formats bound their fields and collection counts so truncation never corrupts JSON or XML. Response-body redaction remains pattern-based and cannot replace restricted artifact access.

## Queue payloads and cleanup

[Queue races](queue-races.md) run only after the normal environment and
database guards pass. Their parent-local factory creates real Laravel job
objects, so job properties can contain application data even though RaceProof
does not serialize those payloads into `plan.json` or project them into
reports. Use disposable database/Redis queues, least-privilege credentials, and
non-sensitive fixture data.

For database queues, those guards validate both the application's default
database and the queue connection's actual database. A separate queue database
must independently pass the transaction, SQLite, and exact-name allowlist
rules before Laravel resolves the queue connection.

RaceProof clears only its random `raceproof:<run-id>:pN` queue names before
dispatch and after every run outcome. It never purges the whole connection,
default queue, failed-job storage, or unrelated jobs. A cleanup error is a
retained run failure, not a warning. If manual rollback is required, resolve
the exact run and participant queue names from bounded evidence before clearing
them; never flush a shared broker.

## Studio boundary

[RaceProof Studio](studio.md) requires explicit opt-in and accepts only
`local` and `testing` as runtime environments. The service provider does not
register Studio routes in production. The archive refuses both reads and
writes there even if `RACEPROOF_STUDIO_ENABLED=true` is accidentally present.
Registered routes also compare the direct peer address, not proxy-forwarded
headers, with `raceproof.studio.allowed_ips`; loopback is the only default.

Studio persists the bounded, redacted report projection under a separate
permission-restricted directory. It does not copy `plan.json`, request
credentials, coordinator files, raw worker output, or absolute artifact paths.
Response bodies can still contain application-specific sensitive data that
pattern redaction cannot classify, so retention should remain short and the
directory must not be published as a CI artifact.
