<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Database;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EngineFidelityTest extends TestCase
{
    public function test_broken_and_fixed_races_are_proven_on_a_real_database_engine(): void
    {
        $engine = getenv('DB_CONNECTION');

        if (! is_string($engine) || ! in_array($engine, ['mysql', 'pgsql'], true)) {
            self::markTestSkipped('Set DB_CONNECTION=mysql or pgsql to run database evidence.');
        }

        $iterations = getenv('RACEPROOF_EVIDENCE_ITERATIONS');
        $iterations = is_string($iterations) && ctype_digit($iterations) ? (int) $iterations : 1;
        $exchangeParticipants = getenv('RACEPROOF_EXCHANGE_PARTICIPANTS');
        $exchangeParticipants = is_string($exchangeParticipants) && $exchangeParticipants !== ''
            ? $exchangeParticipants
            : '10,25';
        $script = dirname(__DIR__).'/Fixtures/database-app/run-evidence.php';
        $process = new Process(
            [
                PHP_BINARY,
                $script,
                '--iterations='.$iterations,
                '--exchange-participants='.$exchangeParticipants,
            ],
            dirname(__DIR__, 2),
            timeout: 1_800,
        );
        $process->mustRun();

        /** @var array<string, mixed> $evidence */
        $evidence = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $output = getenv('RACEPROOF_EVIDENCE_OUTPUT');

        if (is_string($output) && $output !== '') {
            $directory = dirname($output);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($output, $process->getOutput());
        }

        self::assertSame($engine, $evidence['engine']);
        self::assertSame(getenv('DB_DATABASE'), $evidence['database']);
        self::assertTrue($evidence['allowlist_enforced']);
        self::assertTrue($evidence['isolated_migration']);
        self::assertCount(8, $evidence['scenarios']);
        self::assertSame($iterations, $evidence['critical_evidence']['expected']);
        self::assertSame($iterations, $evidence['critical_evidence']['broken_passed']);
        self::assertSame($iterations, $evidence['critical_evidence']['fixed_passed']);

        $expectedParticipants = array_map('intval', explode(',', $exchangeParticipants));
        sort($expectedParticipants);
        self::assertSame(
            $expectedParticipants,
            array_column($evidence['exchange_contention'], 'participants'),
        );

        foreach ($evidence['exchange_contention'] as $exchangeEvidence) {
            self::assertSame(100, $exchangeEvidence['state']['original_quantity']);
            self::assertSame(
                100,
                $exchangeEvidence['state']['fill_quantity'] + $exchangeEvidence['state']['remaining_quantity'],
            );
            self::assertSame(0, $exchangeEvidence['state']['ledger_base_total']);
            self::assertSame(0, $exchangeEvidence['state']['ledger_quote_total']);
            self::assertSame(0, $exchangeEvidence['state']['negative_accounts']);
        }
    }
}
