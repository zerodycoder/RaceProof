# RaceProof private-beta onboarding packet

Use this packet only after a maintainer has selected a real Laravel application
with a concurrency-sensitive write path and has assigned it an opaque
participant ID. Keep the participant-to-project mapping and every contact detail
in the agreed private system, never in the RaceProof repository.

## Maintainer handoff

Fill these values in the private copy before sending it:

- Opaque participant ID:
- Pinned RaceProof release: `v1.0.0-beta.1`
- Private feedback channel:
- Private security channel: GitHub private vulnerability reporting

The public beta is prerelease software with no response-time SLA. Participation
does not grant permission to publish the application identity, its feedback, or
an anonymized case.

## 1. Isolate the experiment

Create a short-lived test branch and use a dedicated non-production
environment. Before installing RaceProof, confirm all of the following:

- [ ] `APP_ENV` is `testing`; production execution is forbidden.
- [ ] The database is disposable, contains no production data, and can be
      dropped or recreated after the experiment.
- [ ] Every RaceProof worker connects to that same database.
- [ ] No parent test transaction hides seed data from independent workers.
- [ ] PHP and Laravel satisfy the published compatibility policy.
- [ ] `proc_open` and a CLI PHP binary are available.

MySQL 8.4 and PostgreSQL 17 on Ubuntu are the release-evidence database targets.
File-backed SQLite can verify process mechanics but cannot substantiate locking,
deadlock, or isolation behavior. Do not weaken an environment or database
refusal merely to continue onboarding.

## 2. Install the pinned signed beta

Run these commands in the isolated application:

```bash
composer require raceproof/runtime:^1.0.0-beta.1@beta
composer require raceproof/laravel:^1.0.0-beta.1@beta --dev
php artisan raceproof:install
```

The runtime package is required outside `require-dev` only when application code
contains `race_point()` calls. The Laravel orchestration package stays a
development dependency. The installer publishes configuration but never edits
an environment file.

Set the disposable database name explicitly:

```dotenv
APP_ENV=testing
RACEPROOF_ENABLED=true
RACEPROOF_REQUIRE_DATABASE_ALLOWLIST=true
RACEPROOF_ALLOWED_DATABASES=replace_with_the_disposable_database_name
```

Then run the fail-closed preflight:

```bash
php artisan raceproof:doctor --self-test
```

Stop and report the bounded diagnostic if Doctor fails. For a
machine-readable diagnostic, use
`php artisan raceproof:doctor --json --self-test`; review it before sharing and
never send `.env` content, connection strings, credentials, or raw logs.

## 3. Exercise one bounded scenario

Start from one suspected race or an existing concurrency regression test. A
scaffold is available when useful:

```bash
php artisan make:race-test ProjectRace /api/example --participants=2
```

Adapt the generated test to committed seed data and the application's real
invariant. Add named checkpoints only around the concurrency decision being
tested. Run a small bounded trial first, then record the exact iteration count;
do not convert a single successful run into a reliability claim.

Useful references:

- [five-minute race test](../five-minute-guide.md);
- [database testing](../database-testing.md);
- [testing workflows](../testing-workflows.md);
- [troubleshooting](../troubleshooting.md).

## 4. Return bounded feedback

Complete the [private-beta feedback template](private-beta-feedback.md) through
the agreed private channel. Send only the opaque participant ID, pinned package
version, bounded environment categories, scenario category, exact iteration
counts, and a minimized sanitized reproduction.

Do not send retained RaceProof directories, raw reports, stack traces with local
paths, database rows, request headers, repository URLs, or environment dumps.
Use the private security channel for a suspected vulnerability.

Publication is a later, separate decision. A maintainer must show the exact
proposed `beta/evidence.json` record and obtain affirmative
[anonymized-evidence consent](anonymized-evidence-consent.md). Silence is not consent.

## 5. Clean up and roll back

After collecting the bounded result:

```bash
php artisan raceproof:clean
```

Use the application's normal branch/revert workflow to remove test-only
instrumentation and dependencies. Drop or recreate the disposable database
according to the application's own test-environment procedure. Do not reuse the
experiment database or its credentials in production.
