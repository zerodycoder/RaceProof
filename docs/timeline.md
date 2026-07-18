# Timeline and failure evidence

Each race writes `timeline.jsonl` inside its run artifact directory. The file is append-only JSON Lines: one complete event per line, written under an exclusive file lock. Independent event IDs make concurrent writers safe without a shared in-memory sequence counter.

## Schema version 1

Every event has the same envelope:

```json
{
  "schema_version": 1,
  "event_id": "8f65d3d910c1490c8a630a8ea3809ca2",
  "run_id": "5df8e3efb8eb49d19e26a6423f7d57e7",
  "type": "checkpoint.reached",
  "occurred_at_ns": 3819204412345,
  "participant_id": "p2",
  "checkpoint": "after-read",
  "data": {
    "attempt": 1
  }
}
```

- `schema_version` is currently `1`; unsupported versions are not interpreted.
- `event_id` and `run_id` are 128-bit lowercase hexadecimal identifiers.
- `type` is a namespaced event name.
- `occurred_at_ns` is a host-local monotonic timestamp. It is useful for ordering and durations on one machine, not for comparing distributed hosts.
- `participant_id` and `checkpoint` are nullable, path-safe identifiers.
- `data` accepts only scalar JSON values or `null`. Nested arbitrary payloads are rejected to keep evidence bounded and auditable.

Version 1 records these event families:

- run lifecycle: `run.created`, `run.spawn_timed_out`, `run.timed_out`, `run.completed`, `run.failed`, `run.cleanup_started`;
- participant lifecycle: `participant.spawned`, `participant.ready`, `participant.finished`, `participant.early_exit`, `participant.exited`;
- coordination: `barrier.start_released`, `checkpoint.reached`, `checkpoint.released`.

## Failure tolerance

The reader streams the file one line at a time. A blank, partial, malformed, unsupported, or cross-run line is ignored and added to `RaceTimeline::warnings`; valid lines before and after it remain available. File order is append order, while `occurred_at_ns` records when each process created its event.

`RaceResult` includes the parsed timeline in its JSON representation. `RaceResult::failureReport()` renders a concise participant, status, timing, checkpoint, timeline-warning, and artifact summary. Assertion failures append the same report. Parent-side orchestration failures throw `RaceExecutionFailed`, whose `result` property exposes partial participant and timeline evidence.

## Sensitive data

Diagnostic text is redacted before it enters participant failures or timeline events. Header-like authorization/cookie values, bearer tokens, and configured credential keys are replaced with `[REDACTED]`; diagnostic and worker-output byte limits are then applied. This is defense in depth, not a reason to print secrets. Response bodies and retained artifacts may still contain application data and require restricted access and short retention.
