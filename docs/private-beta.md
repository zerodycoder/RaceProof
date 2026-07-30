# Private-beta runbook

The private beta exists to test RaceProof against real Laravel applications
before a stable release. Repository tests are necessary but do not count as
adoption. The current evidence and unmet gates are published in
[the generated beta report](beta-evidence.md).

## Definitions

- An **invited project** is a real Laravel application whose maintainer received
  the invitation template through a private channel. A mailing-list subscriber,
  repository star, synthetic fixture, or duplicate environment does not count.
- A **confirmed adopter** installed a specific RaceProof commit or release in
  that application, ran at least one controlled race scenario against a
  disposable non-production database, and shared enough sanitized evidence for
  a maintainer to review the outcome.
- **Actionable feedback** has a reproducible repository issue. It is considered
  resolved only when a linked pull request is merged.
- **Published evidence** is the bounded environment/scenario summary in
  `beta/evidence.json`. Every case and feedback record requires explicit consent
  for anonymized publication.

## Workflow

1. Select projects that already have a suspected race, concurrency-sensitive
   write path, or concurrency regression test. Do not invite projects merely to
   increase the counter.
2. Send [the invitation template](templates/private-beta-invitation.md) privately and
   keep the contact/project mapping in an access-controlled maintainer system,
   never in this repository.
3. Assign an opaque local identifier. Record only the invitation count in the
   public registry after reviewing the private source records.
4. Send the [private-beta onboarding packet](templates/private-beta-onboarding.md)
   with the opaque participant ID, pinned signed prerelease, private feedback
   channel, and security channel filled in. Ask the participant to use a
   disposable database and record the exact package version privately.
5. Collect feedback with [the feedback template](templates/private-beta-feedback.md).
   Route suspected vulnerabilities through `SECURITY.md`; never copy them into
   the beta registry or a public issue.
6. Create a focused public issue only after its reproduction has been minimized
   and scrubbed. Link a merged fix by issue/PR number in the public registry.
7. Obtain [explicit publication consent](templates/anonymized-evidence-consent.md). Then add a
   bounded anonymized case or feedback outcome to `beta/evidence.json`.
8. Run `composer beta:report` and review the diff. Run `composer beta:check`
   before committing. `composer beta:gate` is intentionally fail-closed until
   ten invitations, five consented adopters, and a resulting feedback fix exist.

Those Composer commands are maintainer tooling in a source checkout. Consumer
package manifests intentionally omit development scripts and the `tools`
directory; the published report and schema remain available in release archives.

Two maintainers should compare the invitation count and every public evidence
record with the private source records before a release-gate PR is approved.
Schema validation catches malformed or unsafe fields; it cannot prove that the
underlying human interaction happened.

## Data minimization

Allowed public fields are intentionally enumerated: opaque case ID, month,
supported version tokens, broad operating-system/architecture categories,
database driver/version, scenario categories, iteration counts, GitHub
issue/PR numbers, and the date of publication consent.

Never commit or paste:

- names, employers, email addresses, repository URLs, or contact handles;
- `.env` files, connection strings, database names, hostnames, IP addresses, or
  production identifiers;
- cookies, session IDs, bearer tokens, API keys, passwords, or request headers;
- raw database rows, production data, unreviewed logs, stack traces with local
  paths, or retained RaceProof run directories;
- private vulnerability details.

The validator rejects identity- and secret-shaped keys, unexpected fields, free
text, unsupported version shapes, duplicate IDs, unconsented public records, and
feedback that claims a fix without issue/PR references. This is defense in depth,
not a substitute for human redaction.

## Consent and withdrawal

Consent must be affirmative, specific to the proposed anonymized fields, and
recorded before publication. Silence and participation alone are not consent.
If consent is declined, the feedback may still inform private decisions but must
not appear in `beta/evidence.json`.

On withdrawal, remove the case and any linked feedback record, regenerate the
report, and note the evidence-count change in the release issue without naming
the participant. Git history cannot guarantee erasure from prior commits, so
show participants the exact proposed record before the first publication.

## Gate audit

`composer beta:gate` is a release decision check, not part of ordinary CI while
the program is incomplete. A passing command means only that the reviewed public
registry contains the required counts and relationships. Before declaring the
gate complete, reviewers must also verify:

- ten distinct real project invitations in the private tracker;
- five distinct applications with reviewed scenario results;
- consent matching every published case and feedback record;
- at least one actionable feedback issue and its merged fixing PR;
- no duplicate project hidden behind multiple opaque identifiers;
- the generated report matches the registry and makes no broader support claim.
