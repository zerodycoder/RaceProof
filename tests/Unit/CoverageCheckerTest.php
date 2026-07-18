<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoverageCheckerTest extends TestCase
{
    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = dirname(__DIR__, 2).'/build/coverage-checker-tests';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->reportPath = $directory.'/'.bin2hex(random_bytes(8)).'.xml';
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);

        parent::tearDown();
    }

    /** @return iterable<string, array{int, int, string, int}> */
    public static function thresholdCases(): iterable
    {
        yield 'meets threshold' => [90, 100, '90.00%', 0];
        yield 'exceeds threshold' => [91, 100, '91.00%', 0];
        yield 'below threshold' => [89, 100, '89.00%', 1];
    }

    #[DataProvider('thresholdCases')]
    public function test_it_enforces_the_configured_statement_threshold(
        int $covered,
        int $statements,
        string $expectedPercentage,
        int $expectedExitCode,
    ): void {
        file_put_contents($this->reportPath, $this->clover($covered, $statements));

        $process = $this->runChecker($this->reportPath, '90');

        self::assertSame($expectedExitCode, $process->getExitCode());
        self::assertStringContainsString($expectedPercentage, $process->getOutput());
    }

    public function test_it_rejects_invalid_project_metrics(): void
    {
        foreach ([[0, 0], [101, 100], [-1, 100]] as [$covered, $statements]) {
            file_put_contents($this->reportPath, $this->clover($covered, $statements));

            $process = $this->runChecker($this->reportPath, '90');

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString(
                'invalid project statement metrics',
                $process->getErrorOutput(),
            );
        }
    }

    public function test_it_rejects_an_unreadable_report(): void
    {
        $process = $this->runChecker($this->reportPath, '90');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Unable to read Clover coverage report', $process->getErrorOutput());
    }

    public function test_it_rejects_an_invalid_threshold(): void
    {
        file_put_contents($this->reportPath, $this->clover(9, 10));

        $process = $this->runChecker($this->reportPath, '101');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('minimum between 0 and 100', $process->getErrorOutput());
    }

    private function runChecker(string $path, string $minimum): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/tools/check-coverage.php',
            $path,
            $minimum,
        ]);
        $process->run();

        return $process;
    }

    private function clover(int $covered, int $statements): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage>
              <project>
                <package name="fixture">
                  <file name="first.php">
                    <metrics statements="9" coveredstatements="0"/>
                  </file>
                </package>
                <metrics statements="{$statements}" coveredstatements="{$covered}"/>
              </project>
            </coverage>
            XML;
    }
}
