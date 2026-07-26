# Contributing to RaceProof

RaceProof accepts focused changes that make Laravel concurrency tests safer, more reproducible, or easier to understand. A pull request should solve one coherent problem and include evidence that it works.

## Development setup

Requirements:

- PHP 8.2 or newer;
- Composer 2;
- an operating system with `proc_open`;
- a file-backed SQLite database for package smoke tests;
- Docker when working on MySQL or PostgreSQL behavior.

```bash
composer install
composer check
composer consumer:check
```

`composer check` runs PHPUnit, the real-process Pest contract, Pint, and PHPStan
at level max. The full CI matrix runs on the oldest and newest supported
PHP/Laravel combinations. Coverage is measured separately and must remain at or
above 90% line coverage.

`composer consumer:check` installs a separate Laravel application and exercises
package discovery, authentication, real workers, CLI commands, and Studio
routes without relying on the package's own Testbench bootstrap or development
autoloading.

CI also runs `composer release:dry-run`, which builds each package twice,
compares hashes, installs the artifact pair in a clean Composer project, and
checks the production no-op runtime contract. Changes to frozen symbols must
follow [the public API review process](docs/public-api.md).

Before pushing, scan both current files and reachable Git history with a current
Gitleaks release:

```bash
gitleaks dir .
gitleaks git .
```

`.gitleaks.toml` excludes generated dependencies and build output, not source,
configuration, documentation, fixtures, or lock files.

Private-beta feedback follows [the evidence and consent runbook](docs/private-beta.md).
Never put participant identities, contacts, repository URLs, credentials, raw
environment dumps, or unreviewed logs in a pull request. `composer beta:check`
validates the bounded public registry and its generated report; it does not prove
that an invitation, adoption, consent record, or test run occurred.

README presentation assets are checked in so GitHub renders without a build
step. To regenerate the demo GIF and social preview in a disposable Python
environment:

```bash
python -m pip install -r tools/demo/requirements.txt
python tools/demo/render.py
```

The animation must continue to describe the assertions in
`tests/Integration/OversellingDemoTest.php`; it must not imitate unrecorded
terminal output or claim results that the fixture does not prove.

## Making a change

1. Open or reference an issue for behavior changes and large refactors.
2. Branch from `main` and keep the branch limited to one reviewable outcome.
3. Add a failing regression test before changing concurrency behavior.
4. Run `composer check`.
5. Update user-facing documentation and `CHANGELOG.md` when behavior changes.
6. Open a draft pull request, complete the template, and wait for CI.

Contributions are distributed under the project's
[MIT License](docs/licensing.md). Submit only material you have the right to
license.

Concurrency fixes need two kinds of evidence:

- a deterministic broken case that demonstrates the invariant violation; and
- a fixed case that passes the documented repetition target on every supported database.

Do not weaken synchronization, reduce repetitions, or relax assertions to hide a flaky result.

## Design boundaries

RaceProof is a controlled concurrency test tool. It is not a load tester, production traffic generator, automatic race detector, or formal proof system. New features must preserve the production refusal and database safety model.

Public APIs require tests, documentation, and a compatibility note.
Serialization formats shared with workers are treated as
compatibility-sensitive. Do not refresh the API baseline merely to make CI pass;
review the SemVer impact first.

## Reporting security issues

Do not open public issues for vulnerabilities that could enable production execution, unsafe database access, command injection, path traversal, secret leakage, or dependency compromise. Follow [SECURITY.md](SECURITY.md).
