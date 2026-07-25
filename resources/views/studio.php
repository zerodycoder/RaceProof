<?php

declare(strict_types=1);

use RaceProof\Laravel\Studio\StudioRun;

$escape = static fn (string|int|float $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
$tone = static fn (string $outcome): string => match ($outcome) {
    'passed', 'success' => 'success',
    'timed_out' => 'warning',
    default => 'danger',
};
$outcomeLabel = static fn (string $outcome): string => ucwords(str_replace('_', ' ', $outcome));
$dateLabel = static function (string $value): string {
    try {
        return (new DateTimeImmutable($value))->format('M j, Y · H:i:s T');
    } catch (Throwable) {
        return $value;
    }
};
$routeBase = '/'.$routePrefix;
$title = match ($page) {
    'show' => 'Run '.substr((string) $run?->runId, 0, 8),
    'not-found' => 'Run not found',
    default => 'Runs',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= $escape($title) ?> · RaceProof Studio</title>
    <style>
        :root {
            --bg: #08100f;
            --surface: #0d1816;
            --surface-raised: #12211e;
            --surface-soft: #10201c;
            --line: rgba(157, 198, 184, .15);
            --line-strong: rgba(157, 198, 184, .26);
            --text: #f2f7f5;
            --muted: #8fa8a0;
            --faint: #60756e;
            --accent: #55e6ad;
            --accent-soft: rgba(85, 230, 173, .13);
            --blue: #76a9ff;
            --danger: #ff7c78;
            --danger-soft: rgba(255, 124, 120, .12);
            --warning: #f6c667;
            --warning-soft: rgba(246, 198, 103, .12);
            --shadow: 0 24px 80px rgba(0, 0, 0, .28);
            --radius: 18px;
        }

        * { box-sizing: border-box; }

        html { background: var(--bg); }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 12% -10%, rgba(85, 230, 173, .11), transparent 33rem),
                radial-gradient(circle at 92% 7%, rgba(118, 169, 255, .07), transparent 28rem),
                var(--bg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 15px;
            line-height: 1.55;
        }

        a { color: inherit; text-decoration: none; }

        code, pre, .mono {
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
        }

        .shell {
            width: min(1180px, calc(100% - 40px));
            margin: 0 auto;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            border-bottom: 1px solid var(--line);
            background: rgba(8, 16, 15, .84);
            backdrop-filter: blur(18px);
        }

        .topbar-inner {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 720;
            letter-spacing: -.02em;
        }

        .mark {
            width: 34px;
            height: 34px;
            border: 1px solid rgba(85, 230, 173, .42);
            border-radius: 11px;
            display: grid;
            place-items: center;
            background: linear-gradient(145deg, rgba(85, 230, 173, .17), rgba(85, 230, 173, .03));
            box-shadow: inset 0 1px rgba(255, 255, 255, .07);
        }

        .mark svg { width: 21px; height: 21px; }

        .brand span:last-child { color: var(--muted); font-weight: 540; }

        .local-badge, .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 6px 10px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 650;
            letter-spacing: .02em;
            background: rgba(255, 255, 255, .025);
        }

        .local-badge::before {
            width: 7px;
            height: 7px;
            content: "";
            border-radius: 999px;
            background: var(--accent);
            box-shadow: 0 0 0 5px rgba(85, 230, 173, .08);
        }

        main { padding: 70px 0 80px; }

        .eyebrow {
            margin: 0 0 14px;
            color: var(--accent);
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        h1, h2, h3, p { margin-top: 0; }

        h1 {
            max-width: 760px;
            margin-bottom: 18px;
            font-size: clamp(42px, 7vw, 76px);
            line-height: .98;
            letter-spacing: -.064em;
        }

        h2 {
            margin-bottom: 8px;
            font-size: 22px;
            letter-spacing: -.035em;
        }

        h3 { font-size: 15px; letter-spacing: -.015em; }

        .lede {
            max-width: 650px;
            color: var(--muted);
            font-size: 18px;
        }

        .hero { margin-bottom: 56px; }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 34px;
        }

        .metric, .panel, .run-card, .participant-card {
            border: 1px solid var(--line);
            background: linear-gradient(145deg, rgba(18, 33, 30, .92), rgba(12, 25, 22, .92));
            box-shadow: inset 0 1px rgba(255, 255, 255, .025);
        }

        .metric {
            min-height: 104px;
            padding: 18px;
            border-radius: 15px;
        }

        .metric-label {
            display: block;
            margin-bottom: 11px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 650;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .metric-value {
            font-size: 25px;
            font-weight: 720;
            letter-spacing: -.04em;
        }

        .section-head {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 18px;
        }

        .section-head p { margin-bottom: 0; color: var(--muted); }

        .run-list { display: grid; gap: 11px; }

        .run-card {
            position: relative;
            display: grid;
            grid-template-columns: minmax(230px, 1.6fr) repeat(3, minmax(110px, .65fr)) 30px;
            align-items: center;
            gap: 18px;
            padding: 19px 20px;
            border-radius: 15px;
            transition: border-color .18s ease, transform .18s ease, background .18s ease;
        }

        .run-card:hover {
            transform: translateY(-2px);
            border-color: rgba(85, 230, 173, .34);
            background: var(--surface-raised);
        }

        .run-primary { min-width: 0; }

        .run-id {
            display: block;
            margin-top: 7px;
            overflow: hidden;
            color: var(--muted);
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .data-label {
            display: block;
            color: var(--faint);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .data-value { display: block; margin-top: 4px; font-weight: 650; }

        .arrow {
            color: var(--faint);
            font-size: 21px;
            text-align: right;
        }

        .badge.success { color: var(--accent); border-color: rgba(85, 230, 173, .24); background: var(--accent-soft); }
        .badge.danger { color: var(--danger); border-color: rgba(255, 124, 120, .24); background: var(--danger-soft); }
        .badge.warning { color: var(--warning); border-color: rgba(246, 198, 103, .24); background: var(--warning-soft); }

        .empty {
            padding: 54px 24px;
            border: 1px dashed var(--line-strong);
            border-radius: var(--radius);
            color: var(--muted);
            text-align: center;
            background: rgba(255, 255, 255, .015);
        }

        .empty strong { display: block; margin-bottom: 7px; color: var(--text); font-size: 17px; }

        .back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 32px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 650;
        }

        .back:hover { color: var(--accent); }

        .run-hero {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 30px;
            margin-bottom: 32px;
        }

        .run-hero h1 {
            margin-bottom: 10px;
            font-size: clamp(34px, 5vw, 56px);
            letter-spacing: -.055em;
        }

        .run-hero .mono { color: var(--muted); font-size: 13px; word-break: break-all; }

        .actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 15px;
            border: 1px solid var(--line-strong);
            border-radius: 10px;
            color: var(--text);
            font-size: 13px;
            font-weight: 680;
            background: rgba(255, 255, 255, .035);
        }

        .button:hover { border-color: rgba(85, 230, 173, .4); color: var(--accent); }

        .panel {
            margin-top: 18px;
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 24px;
            margin-bottom: 24px;
        }

        .panel-head p { margin: 0; color: var(--muted); font-size: 13px; }

        .coordination {
            margin: 0 0 20px;
            padding: 12px 14px;
            border-left: 2px solid var(--accent);
            border-radius: 0 8px 8px 0;
            color: #bfcec9;
            background: rgba(85, 230, 173, .055);
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 12px;
        }

        .timeline {
            overflow-x: auto;
            padding: 7px 0 2px;
        }

        .lane {
            min-width: 720px;
            display: grid;
            grid-template-columns: 74px 1fr;
            align-items: center;
            gap: 14px;
            min-height: 57px;
        }

        .lane + .lane { border-top: 1px solid rgba(157, 198, 184, .08); }

        .lane-name {
            color: var(--muted);
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 12px;
            font-weight: 700;
        }

        .track {
            position: relative;
            height: 26px;
        }

        .track::before {
            position: absolute;
            top: 12px;
            right: 0;
            left: 0;
            height: 1px;
            content: "";
            background: linear-gradient(90deg, rgba(143, 168, 160, .08), rgba(143, 168, 160, .35), rgba(143, 168, 160, .08));
        }

        .event {
            position: absolute;
            top: 5px;
            width: 15px;
            height: 15px;
            margin-left: -7px;
            border: 3px solid var(--surface);
            border-radius: 99px;
            background: var(--muted);
            box-shadow: 0 0 0 1px rgba(143, 168, 160, .32);
            cursor: help;
        }

        .event.accent { background: var(--blue); box-shadow: 0 0 0 1px rgba(118, 169, 255, .5), 0 0 16px rgba(118, 169, 255, .2); }
        .event.success { background: var(--accent); box-shadow: 0 0 0 1px rgba(85, 230, 173, .5), 0 0 16px rgba(85, 230, 173, .16); }
        .event.danger { background: var(--danger); box-shadow: 0 0 0 1px rgba(255, 124, 120, .5); }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 18px;
            color: var(--muted);
            font-size: 12px;
        }

        .legend span { display: inline-flex; align-items: center; gap: 7px; }

        .legend i {
            width: 8px;
            height: 8px;
            border-radius: 99px;
            background: var(--muted);
        }

        .legend .accent { background: var(--blue); }
        .legend .success { background: var(--accent); }
        .legend .danger { background: var(--danger); }

        .participant-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 13px;
        }

        .participant-card {
            min-width: 0;
            padding: 20px;
            border-radius: 15px;
        }

        .participant-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .participant-id {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-weight: 760;
        }

        .participant-id::before {
            width: 8px;
            height: 8px;
            content: "";
            border-radius: 99px;
            background: var(--accent);
        }

        .participant-card.danger-card .participant-id::before { background: var(--danger); }
        .participant-card.warning-card .participant-id::before { background: var(--warning); }

        .participant-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .diagnostic {
            margin: 15px 0 0;
            padding: 11px 12px;
            border-radius: 9px;
            color: #e5b6b3;
            background: var(--danger-soft);
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        details {
            margin-top: 14px;
            border-top: 1px solid var(--line);
            padding-top: 12px;
        }

        summary {
            color: var(--muted);
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
        }

        pre {
            max-height: 260px;
            margin: 12px 0 0;
            padding: 13px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 9px;
            color: #c5d4cf;
            background: #08110f;
            font-size: 11px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .warning-list { margin: 0; padding-left: 20px; color: var(--warning); }

        .not-found {
            max-width: 680px;
            margin: 80px auto;
            padding: 48px;
            border: 1px solid var(--line);
            border-radius: 24px;
            text-align: center;
            background: var(--surface);
        }

        .not-found h1 { margin: 0 auto 16px; font-size: 46px; }
        .not-found p { color: var(--muted); }

        footer {
            margin-top: 64px;
            padding-top: 22px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 20px;
            color: var(--faint);
            font-size: 12px;
        }

        @media (max-width: 860px) {
            .metric-grid { grid-template-columns: repeat(2, 1fr); }
            .run-card { grid-template-columns: 1fr 1fr; }
            .run-primary { grid-column: 1 / -1; }
            .arrow { display: none; }
            .participant-grid { grid-template-columns: 1fr; }
            .run-hero { align-items: start; flex-direction: column; }
        }

        @media (max-width: 560px) {
            .shell { width: min(100% - 24px, 1180px); }
            .topbar-inner { min-height: 62px; }
            .brand span:last-child { display: none; }
            main { padding-top: 46px; }
            h1 { font-size: 44px; }
            .lede { font-size: 16px; }
            .metric-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .metric { min-height: 92px; padding: 14px; }
            .metric-value { font-size: 20px; }
            .run-card { padding: 16px; gap: 14px; }
            .panel { padding: 17px; }
            .panel-head { flex-direction: column; }
            .participant-meta { grid-template-columns: 1fr 1fr; }
            footer { flex-direction: column; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell topbar-inner">
        <a class="brand" href="<?= $escape($routeBase) ?>">
            <span class="mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M5 5v14M19 5v14M5 9h6l2 3-2 3H5M19 9h-3M19 15h-3" stroke="#55e6ad" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            RaceProof <span>/ Studio</span>
        </a>
        <span class="local-badge">Local evidence</span>
    </div>
</header>

<main class="shell">
<?php if ($page === 'index') { ?>
    <?php
    $totalRuns = count($runs);
    $passedRuns = count(array_filter($runs, static fn (StudioRun $item): bool => $item->outcome === 'passed'));
    $failedRuns = $totalRuns - $passedRuns;
    $passRate = $totalRuns === 0 ? 0 : (int) round(($passedRuns / $totalRuns) * 100);
    ?>
    <section class="hero">
        <p class="eyebrow">Concurrency evidence, not guesswork</p>
        <h1>Make every race visible.</h1>
        <p class="lede">
            Inspect real Laravel workers, checkpoint coordination, response outcomes, and timing evidence
            without moving a single assertion out of your test suite.
        </p>

        <div class="metric-grid">
            <div class="metric">
                <span class="metric-label">Retained runs</span>
                <span class="metric-value"><?= $escape($totalRuns) ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Passed</span>
                <span class="metric-value"><?= $escape($passedRuns) ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Needs attention</span>
                <span class="metric-value"><?= $escape($failedRuns) ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Response pass rate</span>
                <span class="metric-value"><?= $escape($passRate) ?>%</span>
            </div>
        </div>
    </section>

    <section>
        <div class="section-head">
            <div>
                <h2>Recent runs</h2>
                <p>Protocol outcomes are evidence, not the final Pest/PHPUnit verdict.</p>
            </div>
        </div>

        <?php if ($runs === []) { ?>
            <div class="empty">
                <strong>No evidence has been archived yet.</strong>
                Run a RaceProof test, then refresh this page. Studio never executes an endpoint itself.
            </div>
        <?php } else { ?>
            <div class="run-list">
            <?php foreach ($runs as $item) { ?>
                <a class="run-card" href="<?= $escape($routeBase.'/runs/'.$item->runId) ?>">
                    <div class="run-primary">
                        <span class="badge <?= $escape($tone($item->outcome)) ?>">
                            <?= $escape($outcomeLabel($item->outcome)) ?>
                        </span>
                        <span class="run-id mono"><?= $escape($item->runId) ?></span>
                    </div>
                    <div>
                        <span class="data-label">Participants</span>
                        <span class="data-value"><?= $escape($item->completedParticipants) ?>/<?= $escape($item->expectedParticipants) ?></span>
                    </div>
                    <div>
                        <span class="data-label">Duration</span>
                        <span class="data-value"><?= $escape(number_format($item->durationMs, 2)) ?> ms</span>
                    </div>
                    <div>
                        <span class="data-label">Captured</span>
                        <span class="data-value"><?= $escape($dateLabel($item->capturedAt)) ?></span>
                    </div>
                    <span class="arrow" aria-hidden="true">→</span>
                </a>
            <?php } ?>
            </div>
        <?php } ?>
    </section>

<?php } elseif ($page === 'show' && $run instanceof StudioRun) { ?>
    <a class="back" href="<?= $escape($routeBase) ?>">← All retained runs</a>

    <section class="run-hero">
        <div>
            <p class="eyebrow">Run evidence</p>
            <h1><?= $escape($outcomeLabel($run->outcome)) ?></h1>
            <div class="mono"><?= $escape($run->runId) ?></div>
        </div>
        <div class="actions">
            <span class="badge <?= $escape($tone($run->outcome)) ?>"><?= $escape($outcomeLabel($run->outcome)) ?></span>
            <a class="button" href="<?= $escape($routeBase.'/runs/'.$run->runId.'/report.json') ?>">Download JSON</a>
        </div>
    </section>

    <div class="metric-grid">
        <div class="metric">
            <span class="metric-label">Participants</span>
            <span class="metric-value"><?= $escape($run->completedParticipants) ?>/<?= $escape($run->expectedParticipants) ?></span>
        </div>
        <div class="metric">
            <span class="metric-label">Failed</span>
            <span class="metric-value"><?= $escape($run->failedParticipants) ?></span>
        </div>
        <div class="metric">
            <span class="metric-label">Start spread</span>
            <span class="metric-value"><?= $escape(number_format($run->startSpreadMs, 2)) ?> ms</span>
        </div>
        <div class="metric">
            <span class="metric-label">Total duration</span>
            <span class="metric-value"><?= $escape(number_format($run->durationMs, 2)) ?> ms</span>
        </div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Execution lanes</h2>
                <p><?= $escape($run->timelineEventCount) ?> lifecycle and coordination events.</p>
            </div>
            <?php if ($run->eventsTruncated) { ?>
                <span class="badge warning">Display bounded</span>
            <?php } ?>
        </div>

        <?php if ($run->coordinationSummary !== null) { ?>
            <p class="coordination"><?= $escape($run->coordinationSummary) ?></p>
        <?php } ?>

        <?php if ($timelineRows === []) { ?>
            <div class="empty">No timeline events were available for this run.</div>
        <?php } else { ?>
            <div class="timeline" aria-label="RaceProof execution timeline">
            <?php foreach ($timelineRows as $row) { ?>
                <div class="lane">
                    <div class="lane-name"><?= $escape($row['lane']) ?></div>
                    <div class="track">
                    <?php foreach ($row['events'] as $event) { ?>
                        <span
                            class="event <?= $escape($event['tone']) ?>"
                            style="left: <?= $escape(number_format($event['left'], 3, '.', '')) ?>%"
                            title="<?= $escape($event['title']) ?>"
                            aria-label="<?= $escape($event['label']) ?>"
                        ></span>
                    <?php } ?>
                    </div>
                </div>
            <?php } ?>
            </div>
            <div class="legend" aria-hidden="true">
                <span><i></i> lifecycle</span>
                <span><i class="accent"></i> checkpoint</span>
                <span><i class="success"></i> completed / released</span>
                <span><i class="danger"></i> failure / timeout</span>
            </div>
        <?php } ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Participant outcomes</h2>
                <p>A non-2xx/3xx response is flagged here even when the test intentionally expects it.</p>
            </div>
        </div>

        <div class="participant-grid">
        <?php foreach ($run->participants as $participant) { ?>
            <?php
            $cardTone = $participant->outcome === 'success'
                ? ''
                : ($participant->outcome === 'missing' ? 'warning-card' : 'danger-card');
            ?>
            <article class="participant-card <?= $escape($cardTone) ?>">
                <div class="participant-head">
                    <span class="participant-id"><?= $escape($participant->id) ?></span>
                    <span class="badge <?= $escape($tone($participant->outcome)) ?>">
                        <?= $escape($outcomeLabel($participant->outcome)) ?>
                    </span>
                </div>
                <div class="participant-meta">
                    <div>
                        <span class="data-label">HTTP status</span>
                        <span class="data-value"><?= $participant->status === null ? '—' : $escape($participant->status) ?></span>
                    </div>
                    <div>
                        <span class="data-label">Duration</span>
                        <span class="data-value"><?= $escape(number_format($participant->durationMs, 2)) ?> ms</span>
                    </div>
                </div>

                <?php if ($participant->diagnostic !== '') { ?>
                    <p class="diagnostic"><?= $escape($participant->diagnostic) ?></p>
                <?php } ?>

                <?php if ($participant->body !== '') { ?>
                    <details>
                        <summary>Response body<?= $participant->bodyTruncated ? ' · truncated' : '' ?></summary>
                        <pre><?= $escape($participant->body) ?></pre>
                    </details>
                <?php } ?>

                <?php if ($participant->headers !== []) { ?>
                    <details>
                        <summary>Response headers<?= $participant->headersTruncated ? ' · truncated' : '' ?></summary>
                        <pre><?php foreach ($participant->headers as $name => $value) { ?><?= $escape($name.': '.$value)."\n" ?><?php } ?></pre>
                    </details>
                <?php } ?>
            </article>
        <?php } ?>
        </div>
    </section>

    <?php if ($run->warnings !== []) { ?>
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Timeline warnings</h2>
                    <p><?= $escape($run->warningCount) ?> warning(s) were reported while reconstructing evidence.</p>
                </div>
            </div>
            <ul class="warning-list">
            <?php foreach ($run->warnings as $warning) { ?>
                <li><?= $escape($warning) ?></li>
            <?php } ?>
            </ul>
        </section>
    <?php } ?>

<?php } else { ?>
    <section class="not-found">
        <p class="eyebrow">404 / Missing evidence</p>
        <h1>Run not found.</h1>
        <p>The requested report is missing, malformed, over its configured size limit, or has been pruned.</p>
        <p class="mono"><?= $escape($missingRunId) ?></p>
        <a class="button" href="<?= $escape($routeBase) ?>">Return to Studio</a>
    </section>
<?php } ?>

<footer>
    <span>RaceProof Studio · local/testing only</span>
    <span>Assertions remain in Pest and PHPUnit.</span>
</footer>
</main>
</body>
</html>
