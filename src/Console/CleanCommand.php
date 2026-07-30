<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Studio\ReportArchive;

final class CleanCommand extends Command
{
    protected $signature = 'raceproof:clean
        {--studio : Also remove retained Studio reports}';

    protected $description = 'Remove retained RaceProof run artifacts';

    public function __construct(
        private readonly CoordinatorResolver $coordinator,
        private readonly ReportArchive $archive,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = 0;
        $store = $this->coordinator->resolve();

        foreach ($store->retainedRunIds() as $runId) {
            $store->cleanup($runId);
            $count++;
        }

        $this->components->info("Removed {$count} RaceProof run(s).");

        if ((bool) $this->option('studio')) {
            $studioCount = $this->archive->clear();
            $this->components->info("Removed {$studioCount} RaceProof Studio report(s).");
        }

        return self::SUCCESS;
    }
}
