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
```

`composer check` runs PHPUnit, the real-process Pest contract, Pint, and PHPStan
at level max. The full CI matrix runs on the oldest and newest supported
PHP/Laravel combinations. Coverage is measured separately and must remain at or
above 90% line coverage.

## Making a change

1. Open or reference an issue for behavior changes and large refactors.
2. Branch from `main` and keep the branch limited to one reviewable outcome.
3. Add a failing regression test before changing concurrency behavior.
4. Run `composer check`.
5. Update user-facing documentation and `CHANGELOG.md` when behavior changes.
6. Open a draft pull request, complete the template, and wait for CI.

Concurrency fixes need two kinds of evidence:

- a deterministic broken case that demonstrates the invariant violation; and
- a fixed case that passes the documented repetition target on every supported database.

Do not weaken synchronization, reduce repetitions, or relax assertions to hide a flaky result.

## Design boundaries

RaceProof is a controlled concurrency test tool. It is not a load tester, production traffic generator, automatic race detector, or formal proof system. New features must preserve the production refusal and database safety model.

Public APIs require tests, documentation, and a compatibility note. Serialization formats shared with workers are treated as compatibility-sensitive.

## Reporting security issues

Do not open public issues for vulnerabilities that could enable production execution, unsafe database access, command injection, path traversal, secret leakage, or dependency compromise. Follow [SECURITY.md](SECURITY.md).
