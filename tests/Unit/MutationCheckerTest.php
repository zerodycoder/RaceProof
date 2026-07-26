<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MutationCheckerTest extends TestCase
{
    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = dirname(__DIR__, 2).'/build/mutation-checker-tests';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->reportPath = $directory.'/'.bin2hex(random_bytes(8)).'.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function thresholdCases(): iterable
    {
        yield 'meets threshold without timeouts' => [
            'Mutations: 20 untested, 0 timeout, 80 tested',
            '80.00% (80/100 tested; 20 untested; 0 timeout)',
            0,
        ];
        yield 'exceeds threshold with ANSI formatting' => [
            "\e[32mMutations:\e[0m 19 untested, 0 timeout, 81 tested",
            '81.00% (81/100 tested; 19 untested; 0 timeout)',
            0,
        ];
        yield 'timeouts are not counted as tested' => [
            'Mutations: 19 untested, 2 timeout, 79 tested',
            '79.00% (79/100 tested; 19 untested; 2 timeout)',
            1,
        ];
    }

    #[DataProvider('thresholdCases')]
    public function test_it_enforces_the_strict_score(
        string $report,
        string $expectedSummary,
        int $expectedExitCode,
    ): void {
        file_put_contents($this->reportPath, $report);

        $process = $this->runChecker($this->reportPath, '80');

        self::assertSame($expectedExitCode, $process->getExitCode());
        self::assertStringContainsString($expectedSummary, $process->getOutput());
    }

    public function test_it_rejects_a_report_without_totals(): void
    {
        file_put_contents($this->reportPath, 'Mutation run did not finish.');

        $process = $this->runChecker($this->reportPath, '80');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString(
            'does not contain the expected mutation totals',
            $process->getErrorOutput(),
        );
    }

    public function test_it_rejects_an_empty_mutation_set(): void
    {
        file_put_contents($this->reportPath, 'Mutations: 0 untested, 0 timeout, 0 tested');

        $process = $this->runChecker($this->reportPath, '80');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('contains no mutations', $process->getErrorOutput());
    }

    public function test_it_rejects_an_unreadable_report(): void
    {
        $process = $this->runChecker($this->reportPath, '80');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Unable to read mutation report', $process->getErrorOutput());
    }

    public function test_it_rejects_an_invalid_threshold(): void
    {
        file_put_contents($this->reportPath, 'Mutations: 1 untested, 0 timeout, 9 tested');

        $process = $this->runChecker($this->reportPath, '101');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('minimum between 0 and 100', $process->getErrorOutput());
    }

    private function runChecker(string $path, string $minimum): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/tools/check-mutation.php',
            $path,
            $minimum,
        ]);
        $process->run();

        return $process;
    }
}
