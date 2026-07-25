# Support

Use GitHub issues for reproducible bugs and focused feature proposals. Use the templates and include the RaceProof version, PHP/Laravel versions, operating system, database engine, and a minimal reproduction.

RaceProof is currently a pre-release project maintained on a best-effort basis. There is no private SLA or production support contract. Security reports must follow [SECURITY.md](SECURITY.md), not the public issue tracker.

General Laravel application debugging, database schema design, and production load testing are outside the project's support scope unless they demonstrate a RaceProof defect.

Before opening a bug, follow the [troubleshooting decision guide](docs/troubleshooting.md)
and include the evidence requested by the [platform support matrix](docs/platform-support.md).
Ubuntu Linux is the continuously verified target. WSL2 is a primary development
target, macOS is best-effort compatible, and native Windows remains experimental.
Those levels do not imply support for an untested database or PHP/Laravel version.

The [compatibility policy](docs/compatibility.md) defines the supported
language/framework/database combinations. The [maintenance policy](docs/maintenance.md)
defines supported lines, dependency handling, deprecation, and end of support.
Review [known limitations](docs/known-limitations.md) before treating a request
as a product defect.
