# RaceProof private-beta invitation

Subject: Test a real Laravel race safely with RaceProof

We are validating RaceProof against real Laravel applications before v1. Your
project may be a fit if it has a concurrency-sensitive write path, an
intermittent race, or an existing concurrency regression test.

Participation means running a pinned RaceProof commit or signed prerelease
against a disposable non-production database and sharing a sanitized outcome.
It does not require sharing source code, production data, credentials, raw logs,
or the identity of your application publicly.

Before accepting, please confirm:

- [ ] the test can run only in a dedicated non-production environment;
- [ ] every worker can use the same disposable database safely;
- [ ] PHP, Laravel, database, operating-system, and architecture versions can be
      shared as bounded version/category values;
- [ ] at least one race scenario can be described without proprietary data;
- [ ] suspected security issues will use the private security-reporting channel.

If you participate, we will privately provide the pinned version, onboarding
steps, feedback template, and an opaque participant ID. Anonymous publication is
optional and requires a separate preview and explicit consent.

Reply privately with “interested” to begin. Please do not include `.env`
contents, connection strings, credentials, tokens, cookies, production data,
repository URLs, or unreviewed logs.
