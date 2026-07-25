<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Studio;

use RaceProof\Laravel\Exceptions\RaceProofException;

/**
 * @internal Studio ships one server-rendered, no-build interface.
 */
final class StudioRenderer
{
    public function __construct(private readonly ReportArchive $archive) {}

    /** @param list<StudioRun> $runs */
    public function index(array $runs): string
    {
        return $this->render('index', $runs);
    }

    public function show(StudioRun $run): string
    {
        return $this->render('show', [], $run, $this->timelineRows($run));
    }

    public function notFound(string $runId): string
    {
        return $this->render('not-found', [], null, [], $runId);
    }

    /**
     * @return list<array{
     *     lane:string,
     *     events:list<array{left:float, label:string, title:string, tone:string}>
     * }>
     */
    private function timelineRows(StudioRun $run): array
    {
        if ($run->events === []) {
            return [];
        }

        $times = array_map(
            static fn (StudioEvent $event): int => $event->occurredAtNs,
            $run->events,
        );
        $minimum = min($times);
        $maximum = max($times);
        $span = max(1, $maximum - $minimum);
        $lanes = ['system' => []];

        foreach ($run->participants as $participant) {
            $lanes[$participant->id] = [];
        }

        foreach ($run->events as $event) {
            $lane = $event->participantId ?? 'system';
            $lanes[$lane] ??= [];
            $label = $event->checkpoint ?? substr((string) strrchr($event->type, '.'), 1);
            $details = [];

            foreach ($event->data as $key => $value) {
                $details[] = $key.'='.match (true) {
                    $value === null => 'null',
                    $value === true => 'true',
                    $value === false => 'false',
                    default => (string) $value,
                };
            }

            $lanes[$lane][] = [
                'left' => 3.0 + ((($event->occurredAtNs - $minimum) / $span) * 94.0),
                'label' => $label === '' ? $event->type : $label,
                'title' => $event->type
                    .($event->checkpoint === null ? '' : ' · '.$event->checkpoint)
                    .($details === [] ? '' : ' · '.implode(', ', $details)),
                'tone' => $this->eventTone($event->type),
            ];
        }

        $rows = [];

        foreach ($lanes as $lane => $events) {
            $rows[] = ['lane' => $lane, 'events' => $events];
        }

        return $rows;
    }

    private function eventTone(string $type): string
    {
        return match (true) {
            str_contains($type, 'failed'),
            str_contains($type, 'timed_out'),
            str_contains($type, 'early_exit') => 'danger',
            str_contains($type, 'checkpoint') => 'accent',
            str_contains($type, 'completed'),
            str_contains($type, 'finished'),
            str_contains($type, 'released') => 'success',
            default => 'neutral',
        };
    }

    /**
     * @param  list<StudioRun>  $runs
     * @param  list<array{
     *     lane:string,
     *     events:list<array{left:float, label:string, title:string, tone:string}>
     * }>  $timelineRows
     */
    private function render(
        string $page,
        array $runs = [],
        ?StudioRun $run = null,
        array $timelineRows = [],
        string $missingRunId = '',
    ): string {
        $template = dirname(__DIR__, 2).'/resources/views/studio.php';
        $routePrefix = $this->archive->routePrefix();

        if (! is_file($template)) {
            throw new RaceProofException('RaceProof Studio view is unavailable.');
        }

        ob_start();
        require $template;
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RaceProofException('RaceProof Studio view could not be rendered.');
        }

        return $contents;
    }
}
