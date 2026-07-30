# Laravel consumer acceptance app

This directory is a deliberately small, independent Laravel 12 application. It
installs `raceproof/runtime` and `raceproof/laravel` through Composer path
repositories and exercises RaceProof from the perspective of an application
developer rather than through the package's Testbench suite.

It has its own bootstrap, routes, models, migration, SQLite database,
environment, storage, dependency tree, and PHPUnit configuration. The package
provider is not registered manually: successful execution therefore verifies
Laravel package discovery as well as runtime behavior.

From the repository root:

```bash
composer consumer:check
```

The command creates a clean consumer dependency tree, runs Doctor in both normal
and JSON child-process modes, and verifies:

- session, legacy bearer-token, and Sanctum authentication in real workers;
- per-participant payload, header, cookie, token, identity, and bootstrap
  overrides;
- a three-process coupon redemption race with a database invariant;
- a bounded runtime/discovery/race contract reused before and after the
  published-beta upgrade rehearsal;
- a separate Laravel CLI bootstrap through `raceproof:doctor --self-test`;
- report listing, JSON inspection, Studio URL, scaffold generation, and Studio
  cleanup commands;
- Studio index, detail, JSON export, response security headers, and retained
  report content.

For a manual browser check after the acceptance test:

```bash
php -S 127.0.0.1:8021 -t tests/ConsumerApp/public tests/ConsumerApp/public/index.php
```

Then open `http://127.0.0.1:8021/raceproof`. The Studio routes are enabled only
by this app's local/testing environment configuration.

This fixture is synthetic package acceptance evidence. It does not represent an
external adopter, beta invitation, consented case study, or a production test.

`composer release:upgrade-dry-run` copies this application without its
dependencies, lock file, environment file, SQLite database, or RaceProof path
repositories. It installs the published beta from Packagist, runs only the
bounded upgrade smoke, upgrades both packages from candidate archives, and runs
the same smoke again.
