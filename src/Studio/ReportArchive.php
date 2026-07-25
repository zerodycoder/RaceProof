<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Studio;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Reports\RaceReportFactory;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\ConfigValue;
use Throwable;

/**
 * @internal Studio owns bounded, redacted report persistence.
 */
final readonly class ReportArchive
{
    public function __construct(
        private Config $config,
        private RaceReportFactory $factory,
    ) {}

    public function available(): bool
    {
        if (! ConfigValue::boolean($this->config, 'raceproof.studio.enabled', false)) {
            return false;
        }

        return in_array(
            ConfigValue::string($this->config, 'app.env'),
            ['local', 'testing'],
            true,
        );
    }

    public function store(RaceResult $result): void
    {
        if (! $this->available()) {
            return;
        }

        $report = $this->factory->make($result)->jsonSerialize();
        $run = $report['run'] ?? null;

        if (! is_array($run)) {
            throw new RaceProofException('Studio could not normalize the report run.');
        }

        $run['artifact_path'] = null;
        $report['run'] = $run;
        $document = [
            'archive_schema' => 1,
            'captured_at' => gmdate(DATE_ATOM),
            'report' => $report,
        ];
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        )."\n";
        $maximumBytes = max(1, ConfigValue::integer(
            $this->config,
            'raceproof.studio.max_report_bytes',
            1_048_576,
        ));

        if (strlen($json) > $maximumBytes) {
            throw new RaceProofException("Studio report exceeds the configured {$maximumBytes} byte limit.");
        }

        $directory = $this->directory();
        $this->ensureDirectory($directory);
        $target = $directory.'/'.$result->runId.'.json';
        $temporary = $directory.'/.'.$result->runId.'.'.bin2hex(random_bytes(8)).'.tmp';

        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RaceProofException('Unable to write the Studio report.');
        }

        @chmod($temporary, 0600);

        if (is_file($target) && ! unlink($target)) {
            @unlink($temporary);

            throw new RaceProofException('Unable to replace the existing Studio report.');
        }

        if (! rename($temporary, $target)) {
            @unlink($temporary);

            throw new RaceProofException('Unable to publish the Studio report atomically.');
        }

        $this->prune();
    }

    /** @return list<StudioRun> */
    public function all(): array
    {
        if (! $this->available() || ! is_dir($this->directory())) {
            return [];
        }

        $runs = [];

        foreach ($this->reportPaths() as $path) {
            $run = $this->read($path);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        usort(
            $runs,
            static fn (StudioRun $left, StudioRun $right): int => $right->capturedAt <=> $left->capturedAt,
        );

        return $runs;
    }

    public function find(string $runId): ?StudioRun
    {
        if (
            ! $this->available()
            || preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1
        ) {
            return null;
        }

        return $this->read($this->directory().'/'.$runId.'.json');
    }

    public function clear(): int
    {
        if (! $this->available() || ! is_dir($this->directory())) {
            return 0;
        }

        $removed = 0;

        foreach ($this->reportPaths() as $path) {
            if (! @unlink($path)) {
                throw new RaceProofException('Unable to remove a RaceProof Studio report.');
            }

            $removed++;
        }

        return $removed;
    }

    public function routePrefix(): string
    {
        $prefix = trim(ConfigValue::string($this->config, 'raceproof.studio.route_prefix'), '/');

        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/D', $prefix) !== 1) {
            throw new RaceProofException('RaceProof Studio route prefix must be one path-safe segment.');
        }

        return $prefix;
    }

    private function read(string $path): ?StudioRun
    {
        $maximumBytes = max(1, ConfigValue::integer(
            $this->config,
            'raceproof.studio.max_report_bytes',
            1_048_576,
        ));
        $size = @filesize($path);

        if (! is_int($size) || $size <= 0 || $size > $maximumBytes) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return StudioRun::fromDocument($decoded);
        } catch (Throwable) {
            return null;
        }
    }

    private function prune(): void
    {
        $maximumReports = max(1, ConfigValue::integer(
            $this->config,
            'raceproof.studio.max_reports',
            50,
        ));
        $paths = $this->reportPaths();

        usort($paths, static function (string $left, string $right): int {
            $leftTime = filemtime($left);
            $rightTime = filemtime($right);

            return (is_int($rightTime) ? $rightTime : 0) <=> (is_int($leftTime) ? $leftTime : 0);
        });

        foreach (array_slice($paths, $maximumReports) as $path) {
            if (! @unlink($path)) {
                throw new RaceProofException('Unable to prune an expired RaceProof Studio report.');
            }
        }
    }

    /** @return list<string> */
    private function reportPaths(): array
    {
        return array_values(array_filter(
            glob($this->directory().'/*.json') ?: [],
            static fn (string $path): bool => preg_match(
                '/^[a-f0-9]{32}\.json$/D',
                basename($path),
            ) === 1,
        ));
    }

    private function directory(): string
    {
        $directory = rtrim(ConfigValue::string($this->config, 'raceproof.studio.path'), '/\\');

        if (
            $directory === ''
            || preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $directory) !== 1
        ) {
            throw new RaceProofException('RaceProof Studio requires an absolute archive path.');
        }

        return $directory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RaceProofException('Unable to create the Studio archive directory.');
        }

        @chmod($directory, 0700);
    }
}
