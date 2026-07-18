# Security policy

## Supported versions

RaceProof is pre-release software. Until the first stable release, security fixes are applied to the latest commit on `main`. A version support table will be published with v1.0.

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

RaceProof deliberately refuses production execution and is disabled by default. Reports that bypass those controls, escape the coordinator directory, expose secrets, or permit unintended database writes are treated as high priority.
