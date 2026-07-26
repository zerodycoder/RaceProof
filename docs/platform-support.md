# Platform support

RaceProof depends on PHP `proc_open`, independent CLI processes, writable local
coordination files, and a database reachable from every worker. Support levels
reflect observed evidence, not assumptions about Symfony Process portability.

| Platform | Level | Evidence and boundary |
| --- | --- | --- |
| Ubuntu Linux | Primary, continuously verified | Every pull request runs the full PHPUnit suite, the real-process Pest contract, targeted mutation testing, PHP 8.2/Laravel 12, PHP 8.5/Laravel 13, coverage, MySQL 8.4, and PostgreSQL 17 on GitHub-hosted Ubuntu runners. |
| WSL2 | Primary development target | Uses the Linux process model, but is not a separate CI target. Docker networking, mounted-drive performance, and Windows-host antivirus remain environment-specific. |
| macOS | Best-effort compatible, continuous smoke | A GitHub-hosted macOS runner installs the independent PHP 8.4 consumer app and continuously exercises discovery, installer/Doctor, authentication, CLI, a real multi-process file-backed SQLite invariant, and Studio HTTP behavior. It provides no native MySQL/PostgreSQL evidence. |
| Native Windows | Experimental, continuous smoke | A GitHub-hosted Windows runner executes the same independent PHP 8.4 consumer acceptance flow and fixture-cleanliness check. Windows process mechanics are continuously observed, but native MySQL/PostgreSQL behavior, antivirus policies, and user-specific path restrictions remain unverified. |

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
composer consumer:check
php artisan raceproof:doctor --self-test
```

Then run one application race against a disposable database matching production.
A passing file-backed SQLite smoke test validates process mechanics only, not
row-lock, deadlock, isolation, or timeout behavior.

## Reporting a platform defect

Include `PHP_OS_FAMILY`, `PHP_VERSION`, the PHP executable path, Laravel and
database versions, whether the path is local or network-mounted, and sanitized
worker diagnostics. Do not include credentials, session cookies, bearer tokens,
or an unreviewed retained run directory.
