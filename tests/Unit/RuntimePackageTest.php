<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use RaceProof\Runtime\Checkpoint;
use RaceProof\Runtime\CheckpointActivation;
use RaceProof\Runtime\Contracts\CheckpointHandler;
use Symfony\Component\Process\Process;

final class RuntimePackageTest extends TestCase
{
    public function test_checkpoint_is_inactive_by_default_and_requires_its_exact_capability_to_deactivate(): void
    {
        $handler = new RecordingCheckpointHandler;
        Checkpoint::sync('inactive');
        self::assertSame([], $handler->calls);

        $activation = Checkpoint::activate($handler);
        race_point('inside-request', 123);
        self::assertTrue(Checkpoint::active());
        self::assertSame([['inside-request', 123]], $handler->calls);

        try {
            Checkpoint::activate(new RecordingCheckpointHandler);
            self::fail('Expected nested activation to be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already active', $exception->getMessage());
        }

        try {
            Checkpoint::deactivate(new CheckpointActivation('forged'));
            self::fail('Expected a forged capability to be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('capability is invalid', $exception->getMessage());
        } finally {
            Checkpoint::deactivate($activation);
        }

        self::assertFalse(Checkpoint::active());
    }

    public function test_runtime_manifest_and_bare_php_smoke_test_have_no_framework_or_process_dependency(): void
    {
        $runtime = dirname(__DIR__, 2).'/runtime';
        $manifest = json_decode(
            (string) file_get_contents($runtime.'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(['php' => '^8.2'], $manifest['require']);

        $sourcePaths = array_merge(
            glob($runtime.'/src/*.php') ?: [],
            glob($runtime.'/src/Contracts/*.php') ?: [],
        );
        $source = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            $sourcePaths,
        ));
        self::assertStringNotContainsString('Illuminate\\', $source);
        self::assertStringNotContainsString('Symfony\\Component\\Process', $source);
        self::assertStringNotContainsString('unserialize(', $source);
        self::assertStringNotContainsString('curl_', $source);
        self::assertStringNotContainsString('fsockopen(', $source);
        self::assertStringNotContainsString('stream_socket_client(', $source);

        $script = sprintf(
            'require %s; require %s; require %s; require %s; race_point("production-no-op"); echo RaceProof\\Runtime\\Checkpoint::active() ? "active" : "inactive";',
            var_export($runtime.'/src/Contracts/CheckpointHandler.php', true),
            var_export($runtime.'/src/CheckpointActivation.php', true),
            var_export($runtime.'/src/Checkpoint.php', true),
            var_export($runtime.'/src/helpers.php', true),
        );
        $process = new Process([PHP_BINARY, '-n', '-r', $script]);

        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertSame('inactive', $process->getOutput());
    }
}

final class RecordingCheckpointHandler implements CheckpointHandler
{
    /** @var list<array{string, int|null}> */
    public array $calls = [];

    public function sync(string $name, ?int $timeoutMs = null): void
    {
        $this->calls[] = [$name, $timeoutMs];
    }
}
