# Platform support

RaceProof depends on PHP `proc_open`, independent CLI processes, writable local
coordination files, and a database reachable from every worker. Support levels
reflect observed evidence, not assumptions about Symfony Process portability.

| Platform | Level | Evidence and boundary |
| --- | --- | --- |
| Ubuntu Linux | Primary, continuously verified | Every pull request runs the full PHPUnit suite, the real-process Pest contract, PHP 8.2/Laravel 12, PHP 8.5/Laravel 13, coverage, MySQL 8.4, and PostgreSQL 17 on GitHub-hosted Ubuntu runners. |
| WSL2 | Primary development target | Uses the Linux process model, but is not a separate CI target. Docker networking, mounted-drive performance, and Windows-host antivirus remain environment-specific. |
| macOS | Best-effort compatible | Symfony Process and PHP filesystem primitives are expected to work, but RaceProof has no continuous macOS runner or database evidence. Run the verification commands below before relying on it. |
| Native Windows | Experimental | A maintainer smoke run on Windows 10 build 19045 with PHP 8.4 exercises the non-engine PHPUnit suite and real multi-process Pest contract; the database suite remains explicitly gated. Windows is not a continuous CI target and has no native MySQL/PostgreSQL evidence. |

The supported application matrix is PHP 8.2+ with Laravel 12 or 13. Database
release evidence is specific to MySQL 8.4 and PostgreSQL 17 on Linux. Compatible
engine releases may work, but are not release-gate evidence until added to CI.
See [the compatibility policy](compatibility.md) for the distinction between
Composer-resolvable combinations, continuously verified edges, and support scope.

## Platform verification

Run these commands with the same PHP binary and database environment used by the
application:

```bash
php -r "exit(function_exists('proc_open') ? 0 : 1);"
composer validate --strict
composer check
php artisan raceproof:doctor
```

Then run one application race against a disposable database matching production.
A passing file-backed SQLite smoke test validates process mechanics only, not
row-lock, deadlock, isolation, or timeout behavior.

## Reporting a platform defect

Include `PHP_OS_FAMILY`, `PHP_VERSION`, the PHP executable path, Laravel and
database versions, whether the path is local or network-mounted, and sanitized
worker diagnostics. Do not include credentials, session cookies, bearer tokens,
or an unreviewed retained run directory.
