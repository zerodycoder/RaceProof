<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'raceproof:install
        {--force : Replace an existing RaceProof configuration file}';

    protected $description = 'Publish RaceProof configuration and show safe test-environment setup';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = dirname(__DIR__, 2).'/config/raceproof.php';
        $target = config_path('raceproof.php');
        $force = (bool) $this->option('force');

        if ($this->files->exists($target) && ! $force) {
            $this->components->info("RaceProof configuration already exists at [{$target}]; left unchanged.");
        } else {
            try {
                $contents = $this->files->get($source);
                $this->files->ensureDirectoryExists(dirname($target));
                $this->files->replace($target, $contents);
            } catch (Throwable) {
                $this->components->error('Unable to publish RaceProof configuration.');

                return self::FAILURE;
            }

            if (
                ! $this->files->exists($target)
                || hash_file('sha256', $target) !== hash('sha256', $contents)
            ) {
                $this->components->error('RaceProof configuration did not pass its post-write integrity check.');

                return self::FAILURE;
            }

            $this->components->info("Published RaceProof configuration to [{$target}].");
        }

        $this->newLine();
        $this->line('Add these values to your dedicated test environment (for example .env.testing):');
        $this->newLine();
        $this->line('RACEPROOF_ENABLED=true');
        $this->line('RACEPROOF_REQUIRE_DATABASE_ALLOWLIST=true');
        $this->line('RACEPROOF_ALLOWED_DATABASES=your_dedicated_test_database');
        $this->newLine();
        $this->warn('RaceProof did not modify any environment file.');
        $this->line('Next: php artisan raceproof:doctor --self-test');
        $this->line('Then: php artisan make:race-test InventoryRace /api/inventory/claim --participants=3');

        return self::SUCCESS;
    }
}
