<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Http;

use Illuminate\Http\Response;
use RaceProof\Laravel\Studio\ReportArchive;
use RaceProof\Laravel\Studio\StudioRenderer;

final readonly class StudioController
{
    public function __construct(
        private ReportArchive $archive,
        private StudioRenderer $renderer,
    ) {}

    public function index(): Response
    {
        return $this->html($this->renderer->index($this->archive->all()));
    }

    public function show(string $runId): Response
    {
        $run = $this->archive->find($runId);

        if ($run === null) {
            return $this->html($this->renderer->notFound($runId), Response::HTTP_NOT_FOUND);
        }

        return $this->html($this->renderer->show($run));
    }

    public function download(string $runId): Response
    {
        $run = $this->archive->find($runId);

        if ($run === null) {
            return new Response('RaceProof Studio report not found.', Response::HTTP_NOT_FOUND, $this->securityHeaders());
        }

        $json = json_encode(
            $run,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";

        return new Response($json, Response::HTTP_OK, [
            ...$this->securityHeaders(),
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="raceproof-'.$runId.'.json"',
        ]);
    }

    private function html(string $contents, int $status = Response::HTTP_OK): Response
    {
        return new Response($contents, $status, [
            ...$this->securityHeaders(),
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
