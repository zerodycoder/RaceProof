# Evidence reporters

RaceProof reporters turn one `RaceResult` into bounded human text, versioned JSON, or JUnit XML. Every reporter uses the same redacted `RaceReport` snapshot, so participant outcomes and evidence limits do not drift by format.

## CLI and CI usage

Reporters are container-resolvable and implement `RaceProof\Laravel\Contracts\Reporter`:

```php
use RaceProof\Laravel\Reports\HumanReporter;
use RaceProof\Laravel\Reports\JsonReporter;
use RaceProof\Laravel\Reports\JUnitReporter;

$result = race()
    ->participants(3)
    ->postJson('/api/checkout')
    ->run();

fwrite(STDERR, $result->report(app(HumanReporter::class)).PHP_EOL);

file_put_contents(
    base_path('build/raceproof/report.json'),
    $result->report(app(JsonReporter::class)),
);

file_put_contents(
    base_path('build/raceproof/junit.xml'),
    $result->report(app(JUnitReporter::class)),
);
```

Create the output directory before writing it and publish the JSON/JUnit files as restricted CI artifacts. Most CI test-report integrations can ingest the JUnit file directly.

The stable model is also available when a custom integration needs structured PHP data:

```php
use RaceProof\Laravel\Reports\RaceReportFactory;

$report = app(RaceReportFactory::class)->make($result);

echo $report->schemaVersion;       // 1
echo $report->failedParticipants;
```

Custom formatters should implement the reporter contract and should derive their data through `RaceReportFactory` to retain the standard redaction and bounds.

## Shared outcome model

A report has one run outcome:

- `passed`: every expected participant produced a successful 2xx/3xx result and the run did not time out;
- `failed`: a participant is missing, has a non-success HTTP status, throws an application exception, or reports a worker error;
- `timed_out`: the run timeout fired, regardless of any partial responses.

Each expected participant has one outcome:

- `success`;
- `http_failure`;
- `application_exception`;
- `worker_error`;
- `missing`.

Missing results are synthesized in the report model so automation does not silently treat an absent worker as success.

## JSON schema version 1

`JsonReporter` emits pretty-printed UTF-8 JSON with a trailing newline:

```json
{
  "schema_version": 1,
  "run": {
    "run_id": "5df8e3efb8eb49d19e26a6423f7d57e7",
    "outcome": "failed",
    "expected_participants": 2,
    "completed_participants": 2,
    "failed_participants": 1,
    "statuses": {
      "201": 1,
      "409": 1
    },
    "start_spread_ms": 0.42,
    "duration_ms": 8.17,
    "timed_out": false,
    "artifact_path": null
  },
  "participants": [
    {
      "participant_id": "p1",
      "outcome": "success",
      "status": 201,
      "started_at_ns": 3819204412000,
      "finished_at_ns": 3819204419000,
      "duration_ms": 7,
      "diagnostic": "",
      "body": "{\"created\":true}",
      "body_truncated": false,
      "headers": {},
      "headers_truncated": false,
      "exception_class": null
    },
    {
      "participant_id": "p2",
      "outcome": "http_failure",
      "status": 409,
      "started_at_ns": 3819204412420,
      "finished_at_ns": 3819204420170,
      "duration_ms": 7.75,
      "diagnostic": "HTTP 409",
      "body": "{\"conflict\":true}",
      "body_truncated": false,
      "headers": {},
      "headers_truncated": false,
      "exception_class": null
    }
  ],
  "coordination_summary": "ready 2/2; after-read 2/2 released",
  "timeline": {
    "event_count": 14,
    "warning_count": 0,
    "warnings": [],
    "warnings_truncated": false
  }
}
```

All keys shown above are part of schema version 1. Participant entries contain status/timing, outcome, bounded diagnostic/body/header evidence, truncation flags, and the exception class. A breaking key removal, type change, or semantic change requires a new `schema_version`.

## JUnit mapping

`JUnitReporter` emits a UTF-8 `<testsuites>` document with one testcase per expected participant:

- non-2xx/3xx HTTP responses become `<failure type="http_status">`;
- application exceptions, worker failures, and missing results become `<error>`;
- a timed-out run adds a `RaceProof.Run` timeout testcase with an `<error>`;
- bounded redacted headers and response bodies are included in `<system-out>`.

The `tests`, `failures`, and `errors` attributes exactly match the emitted testcase elements. XML 1.0-invalid control characters are replaced, and all text/attributes are escaped. A business response such as HTTP 409 is still a JUnit failure because the report model does not know the test's expected status distribution.

## Bounds and redaction

Report construction re-applies `SensitiveDataRedactor` even when executor diagnostics were already sanitized. The defaults are:

```php
'reporting' => [
    'human_output_bytes' => 16_384,
    'diagnostic_text_bytes' => 4_096,
    'response_body_bytes' => 4_096,
    'header_limit' => 32,
    'timeline_warning_limit' => 100,
],
```

Human output is capped as a whole. JSON and XML stay structurally valid: RaceProof bounds individual fields and collection counts instead of cutting the encoded document. Participant count remains bounded by the RaceProof plan limit.

Configured credential keys, bearer tokens, and authorization/cookie headers are redacted. Pattern redaction cannot identify every application-specific secret; captured response bodies and artifact paths still require restricted access and short retention.
