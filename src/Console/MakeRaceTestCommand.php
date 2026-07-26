<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use RaceProof\Laravel\Support\ConfigValue;

final class MakeRaceTestCommand extends Command
{
    protected $signature = 'make:race-test
        {name : Test class name}
        {uri : Application URI exercised by every participant}
        {--participants=3 : Number of concurrent participants}
        {--pest : Generate a Pest test}
        {--force : Replace an existing test}';

    protected $description = 'Create a code-first RaceProof feature test';

    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        $uri = $this->argument('uri');
        $participants = $this->option('participants');

        if (
            ! is_string($name)
            || preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name) !== 1
        ) {
            $this->components->error('The test name must be a path-safe PHP class name.');

            return self::INVALID;
        }

        if (
            ! is_string($uri)
            || ! str_starts_with($uri, '/')
            || strlen($uri) > 2_048
            || preg_match('/[\x00-\x1F\x7F]/', $uri) === 1
        ) {
            $this->components->error('The URI must start with / and contain no control characters.');

            return self::INVALID;
        }

        $participantCount = filter_var($participants, FILTER_VALIDATE_INT);

        if (! is_int($participantCount) || $participantCount < 2 || $participantCount > 100) {
            $this->components->error('Participants must be an integer from 2 through 100.');

            return self::INVALID;
        }

        if ((bool) $this->option('pest') && ! class_exists('Pest\\TestSuite')) {
            $this->components->error('Pest is not installed.');
            $this->line('Install a compatible version with: composer require pestphp/pest --dev');

            return self::FAILURE;
        }

        $class = Str::studly($name);

        if (! str_ends_with($class, 'Test')) {
            $class .= 'Test';
        }

        $directory = rtrim(ConfigValue::string($this->config, 'raceproof.scaffolding.test_path'), '/\\');

        if (
            $directory === ''
            || preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $directory) !== 1
        ) {
            $this->components->error('RaceProof scaffolding requires an absolute test path.');

            return self::FAILURE;
        }

        $target = $directory.'/'.$class.'.php';

        if (is_file($target) && ! (bool) $this->option('force')) {
            $this->components->error("Test already exists: {$target}");

            return self::FAILURE;
        }

        $stub = dirname(__DIR__, 2).'/stubs/'.((bool) $this->option('pest')
            ? 'race-test.pest.php.stub'
            : 'race-test.phpunit.php.stub');
        $template = file_get_contents($stub);

        if (! is_string($template)) {
            $this->components->error('The RaceProof test stub is unavailable.');

            return self::FAILURE;
        }

        $contents = str_replace(
            ['{{ class }}', '{{ uri }}', '{{ participants }}'],
            [$class, var_export($uri, true), (string) $participantCount],
            $template,
        );

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Unable to create test directory: {$directory}");

            return self::FAILURE;
        }

        $temporary = $directory.'/.'.$class.'.'.bin2hex(random_bytes(8)).'.tmp';

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            $this->components->error('Unable to write the generated test.');

            return self::FAILURE;
        }

        if (is_file($target) && ! unlink($target)) {
            @unlink($temporary);
            $this->components->error('Unable to replace the existing test.');

            return self::FAILURE;
        }

        if (! rename($temporary, $target)) {
            @unlink($temporary);
            $this->components->error('Unable to publish the generated test atomically.');

            return self::FAILURE;
        }

        $this->components->info("Created {$target}");
        $this->line('Run it with: php artisan test --filter='.$class);

        return self::SUCCESS;
    }
}
