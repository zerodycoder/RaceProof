<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Studio\ReportArchive;
use RaceProof\Laravel\Support\ConfigValue;

final class StudioCommand extends Command
{
    protected $signature = 'raceproof:studio {run? : Optional run ID to open}';

    protected $description = 'Print the local RaceProof Studio URL';

    public function __construct(
        private readonly ReportArchive $archive,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->archive->available()) {
            $this->components->error(
                'RaceProof Studio is unavailable. Set RACEPROOF_STUDIO_ENABLED=true in local or testing.',
            );

            return self::FAILURE;
        }

        $runId = $this->argument('run');

        if ($runId !== null && (! is_string($runId) || $this->archive->find($runId) === null)) {
            $this->components->error('The requested Studio report does not exist.');

            return self::FAILURE;
        }

        $url = rtrim(ConfigValue::string($this->config, 'app.url'), '/')
            .'/'.$this->archive->routePrefix();

        if (is_string($runId)) {
            $url .= '/runs/'.$runId;
        }

        $this->components->info('RaceProof Studio');
        $this->line($url);
        $this->line('If the application is not running, start its normal local development server first.');

        return self::SUCCESS;
    }
}
