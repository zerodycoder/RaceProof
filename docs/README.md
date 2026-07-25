# RaceProof documentation

This page routes readers to the shortest document that answers their question.
The repository treats executable tests, machine-readable contracts, and
generated evidence as the source of truth; prose explains those controls but
does not replace them.

## Start here

| Goal | Read |
| --- | --- |
| Reproduce and fix a race in five minutes | [Five-minute guide](five-minute-guide.md) |
| Understand the process and coordination model | [Architecture](architecture.md) |
| Add checkpoints safely | [Runtime checkpoint deployment](runtime-checkpoints.md) |
| Configure individual requests or identities | [Participant requests and authentication](participant-specs.md) |
| Prepare process-local state | [Participant bootstrap](participant-bootstrap.md) |
| Use PHPUnit or Pest | [Testing workflows](testing-workflows.md) |
| Diagnose a failed run | [Troubleshooting](troubleshooting.md) |
| Export human, JSON, or JUnit evidence | [Reporters](reporters.md) |

## Safety and database behavior

| Topic | Read |
| --- | --- |
| Environment, database, and credential boundaries | [Production safety](production-safety.md) |
| MySQL/PostgreSQL setup and engine evidence | [Database testing](database-testing.md) |
| Supported versions and platforms | [Compatibility policy](compatibility.md) and [platform matrix](platform-support.md) |
| Deliberate exclusions and current blockers | [Known limitations](known-limitations.md) |

## Maintainers and contributors

| Topic | Read |
| --- | --- |
| Frozen symbols and signatures | [Public API contract](public-api.md) |
| Compatibility and deprecation rules | [Versioning policy](versioning.md) and [upgrade guide](../UPGRADING.md) |
| Required local and CI checks | [Quality policy](quality.md) |
| Release sequence and fail-closed gates | [Release runbook](releasing.md) |
| Current automated and external evidence | [Pre-release audit](release-audit.md) |
| Beta recruitment and consent boundaries | [Private beta runbook](private-beta.md) |
| Current beta evidence | [Beta evidence](beta-evidence.md) |
| Maintenance expectations | [Maintenance policy](maintenance.md) |
| License and redistribution | [Licensing guide](licensing.md) |

## Design records

- [ADR 0001: separate runtime checkpoint package](adr/0001-separate-runtime-checkpoint-package.md)
- [RFC 0001: runtime checkpoint packaging](rfcs/0001-runtime-checkpoint-packaging.md)
- [Timeline evidence model](timeline.md)

## Executable examples

- [Overselling](../examples/overselling/README.md)
- [Coupon redemption](../examples/coupon-redemption/README.md)
- [Wallet debit](../examples/wallet-debit/README.md)
- [Quote acceptance](../examples/quote-acceptance/README.md)

Every example is checked by `tests/Unit/PublishedExamplesTest.php` and exercised
by the database evidence suite.
