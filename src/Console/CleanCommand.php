<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Studio\ReportArchive;

final class CleanCommand extends Command
{
    protected $signature = 'raceproof:clean
        {--studio : Also remove retained Studio reports}';

    protected $description = 'Remove retained RaceProof run artifacts';

    public function __construct(
        private readonly FileCoordinatorStore $store,
        private readonly ReportArchive $archive,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = 0;

        foreach (glob(rtrim($this->store->basePath(), '/\\').'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $runId = basename($directory);

            if (preg_match('/^[a-f0-9]{32}$/', $runId)) {
                $this->store->cleanup($runId);
                $count++;
            }
        }

        $this->components->info("Removed {$count} RaceProof run(s).");

        if ((bool) $this->option('studio')) {
            $studioCount = $this->archive->clear();
            $this->components->info("Removed {$studioCount} RaceProof Studio report(s).");
        }

        return self::SUCCESS;
    }
}
