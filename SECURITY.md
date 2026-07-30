# Security policy

## Supported versions

RaceProof is pre-release software. Signed `v1.0.0-beta.1` source/runtime tags
and Packagist packages are public, but the beta is not a separately maintained
stable support line. Before v1, fixes target the latest commit on `main` on a
best-effort basis with no response-time SLA.

| Line | Security fixes |
| --- | --- |
| Latest commit on `main` | Supported |
| `v1.0.0-beta.1` | Published prerelease; not maintained as a separate line |
| Older commits and prereleases | Unsupported |
| Stable v1 | Not published |

After a stable release, this table and the release notes will name every
supported line and end-of-support date. No hypothetical version is treated as
supported.

## Reporting a vulnerability

Please use GitHub private vulnerability reporting for this repository. If that channel is unavailable, contact the repository owner privately through their GitHub profile and request a secure reporting channel.

Include:

- the affected commit or version;
- a minimal reproduction;
- the expected and observed safety boundary;
- the likely impact;
- any known workaround.

Do not include live credentials or target production systems. Please allow reasonable time for investigation before disclosure.

## Security boundaries

RaceProof deliberately refuses production execution and is disabled by default.
Reports that bypass those controls, escape the coordinator directory, forge or
replay remote worker controls, launch an arbitrary remote command, expose
secrets, or permit unintended database writes are treated as high priority.

Release and CI workflows pin third-party actions to full commits, audit locked
Composer dependencies, and keep publication credentials in a protected release
environment. See [the maintenance policy](docs/maintenance.md) and
[known limitations](docs/known-limitations.md).
