# Isolated Laravel consumer acceptance

RaceProof includes a
[small Laravel 12 application](../tests/ConsumerApp/README.md) to test the package exactly where
developer experience failures tend to hide: outside the package's own
Testbench bootstrap and Composer autoloader.

Run the complete acceptance workflow from the repository root:

```bash
composer consumer:check
```

The consumer has a separate Composer dependency graph, application key,
bootstrap, routes, users, Sanctum tokens, file sessions, SQLite database, and
storage. Composer package discovery must register RaceProof; the application
does not register its provider manually.

The feature test drives real worker processes through session, legacy token,
Sanctum, and explicit participant identity modes. It also exercises participant
request overrides and bootstrap state, proves a coupon can be claimed only
once by three simultaneous participants, runs the report/Studio/scaffolding
commands, boots Doctor through a separate Laravel CLI process, and requests the
Studio HTML and JSON endpoints.

CI installs the consumer before installing the root package's development
dependencies. That ordering prevents the package's own `vendor` directory or
Testbench bootstrap from accidentally making the acceptance test pass.

This is synthetic first-party acceptance evidence. It improves release
confidence and developer experience, but it is intentionally not counted as
external beta adoption or a real-world case study.
