<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Support\DoctorSelfTest;
use RuntimeException;

final class DoctorSelfTestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = dirname(__DIR__, 2).'/build/doctor-self-test/'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_accepts_a_successful_schema_v1_child_doctor_result(): void
    {
        $this->expectNotToPerformAssertions();
        $this->artisan(<<<'PHP'
            <?php
            echo json_encode(['schema_version' => 1, 'ok' => true, 'checks' => []]);
            PHP);

        $this->selfTest()->run();
    }

    public function test_it_rejects_a_non_zero_child_exit_without_exposing_output(): void
    {
        $this->artisan(<<<'PHP'
            <?php
            fwrite(STDERR, 'token=child-secret');
            exit(7);
            PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Child Doctor exited with status 7.');

        $this->selfTest()->run();
    }

    public function test_it_rejects_invalid_or_unsuccessful_child_json(): void
    {
        $this->artisan("<?php echo 'not-json';");

        try {
            $this->selfTest()->run();
            self::fail('Invalid child JSON should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Child Doctor returned invalid JSON.', $exception->getMessage());
        }

        $this->artisan(<<<'PHP'
            <?php
            echo json_encode(['schema_version' => 1, 'ok' => false, 'checks' => []]);
            PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Child Doctor did not report a successful schema-v1 result.');

        $this->selfTest()->run();
    }

    public function test_it_rejects_a_missing_artisan_file_and_invalid_timeout(): void
    {
        try {
            $this->selfTest()->run();
            self::fail('A missing artisan file should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Laravel artisan was not found in the application base path.',
                $exception->getMessage(),
            );
        }

        $this->artisan('<?php exit(0);');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Doctor self-test timeout must be from 100 through 120000 milliseconds.');

        $this->selfTest(99)->run();
    }

    public function test_it_rejects_an_invalid_or_exceeded_child_output_limit(): void
    {
        $this->artisan('<?php exit(0);');

        try {
            $this->selfTest(outputBytes: 1_023)->run();
            self::fail('An invalid child output limit should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Doctor self-test output limit must be from 1024 through 1048576 bytes.',
                $exception->getMessage(),
            );
        }

        $this->artisan(<<<'PHP'
            <?php
            fwrite(STDERR, str_repeat('ignored-stderr', 131_072));
            echo str_repeat('x', 1025);
            PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Child Doctor output exceeded 1024 bytes.');

        $this->selfTest(outputBytes: 1_024)->run();
    }

    private function selfTest(
        int $timeoutMilliseconds = 1_000,
        int $outputBytes = 65_536,
    ): DoctorSelfTest {
        return new DoctorSelfTest(
            new Application($this->directory),
            new Repository([
                'raceproof' => [
                    'doctor' => [
                        'self_test_timeout_ms' => $timeoutMilliseconds,
                        'self_test_output_bytes' => $outputBytes,
                    ],
                ],
            ]),
        );
    }

    private function artisan(string $contents): void
    {
        file_put_contents($this->directory.'/artisan', $contents, LOCK_EX);
    }
}
