<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Config\Repository;
use RaceProof\Laravel\Support\SensitiveDataRedactor;

final class SensitiveDataRedactorTest extends TestCase
{
    public function test_worker_output_is_redacted_before_it_is_byte_bounded(): void
    {
        $this->app['config']->set('raceproof.capture.worker_output_bytes', 96);
        $redactor = $this->app->make(SensitiveDataRedactor::class);

        $captured = $redactor->workerOutput(
            "Authorization: Bearer top-secret\nCookie: session=private",
            'password=hunter2 token=abc123 safe='.str_repeat('x', 100),
        );

        self::assertStringNotContainsString('top-secret', $captured);
        self::assertStringNotContainsString('private', $captured);
        self::assertStringNotContainsString('hunter2', $captured);
        self::assertStringNotContainsString('abc123', $captured);
        self::assertStringContainsString('[REDACTED]', $captured);
        self::assertStringEndsWith('[truncated]', $captured);
        self::assertLessThanOrEqual(96, strlen($captured));
    }

    public function test_diagnostics_redact_json_credentials_and_bearer_tokens(): void
    {
        $redactor = $this->app->make(SensitiveDataRedactor::class);
        $captured = $redactor->diagnostic(
            '{"access_token":"json-secret","safe":"visible"} Bearer standalone-secret',
        );

        self::assertStringNotContainsString('json-secret', $captured);
        self::assertStringNotContainsString('standalone-secret', $captured);
        self::assertStringContainsString('visible', $captured);
        self::assertSame(2, substr_count($captured, '[REDACTED]'));
    }

    public function test_invalid_utf8_and_empty_configured_keys_cannot_bypass_redaction(): void
    {
        $this->app['config']->set('raceproof.capture.redact_keys', ['', 'token']);
        $redactor = $this->app->make(SensitiveDataRedactor::class);

        $captured = $redactor->workerOutput('', "binary\xFF token=still-secret");

        self::assertTrue(mb_check_encoding($captured, 'UTF-8'));
        self::assertStringContainsString('binary', $captured);
        self::assertStringContainsString('token=[REDACTED]', $captured);
        self::assertStringNotContainsString('still-secret', $captured);
    }

    public function test_byte_limits_never_split_a_utf8_character(): void
    {
        $redactor = $this->app->make(SensitiveDataRedactor::class);
        $captured = $redactor->bounded("\x1B".str_repeat('é', 20), 16);

        self::assertTrue(mb_check_encoding($captured, 'UTF-8'));
        self::assertLessThanOrEqual(16, strlen($captured));
        self::assertStringNotContainsString("\x1B", $captured);
    }

    public function test_default_and_configured_limits_are_exact(): void
    {
        $config = new Repository;
        $redactor = new SensitiveDataRedactor($config);

        $defaultDiagnostic = $redactor->diagnostic(str_repeat('d', 5_000));
        $defaultWorkerOutput = $redactor->workerOutput(str_repeat('e', 5_000), 'stdout');

        self::assertSame(4_096, strlen($defaultDiagnostic));
        self::assertSame(4_096, strlen($defaultWorkerOutput));
        self::assertStringEndsWith(' [truncated]', $defaultDiagnostic);
        self::assertStringEndsWith(' [truncated]', $defaultWorkerOutput);

        $config->set('raceproof.capture.diagnostic_text_bytes', 16);
        $config->set('raceproof.capture.worker_output_bytes', 64);

        self::assertSame('dddd [truncated]', $redactor->diagnostic(str_repeat('d', 17)));
        self::assertSame("stderr\nstdout", $redactor->workerOutput(' stderr', 'stdout '));
    }

    public function test_configured_keys_are_trimmed_deduplicated_and_case_insensitive(): void
    {
        $this->app['config']->set('raceproof.capture.redact_keys', [
            ' token ',
            'token',
            '',
            'PASSWORD',
        ]);
        $redactor = $this->app->make(SensitiveDataRedactor::class);

        self::assertSame(
            'token=[REDACTED] TOKEN=[REDACTED] password=[REDACTED] safe=visible',
            $redactor->redact('token=first TOKEN=second password=\'third\' safe=visible'),
        );
    }

    public function test_bounding_handles_every_marker_and_utf8_boundary(): void
    {
        $redactor = $this->app->make(SensitiveDataRedactor::class);

        self::assertSame('', $redactor->bounded('value', 0));
        self::assertSame('', $redactor->bounded('', 20));
        self::assertSame('exact', $redactor->bounded('exact', 5));
        self::assertSame('abc', $redactor->bounded('abcdef', 3));
        self::assertSame('abcdefghijkl', $redactor->bounded('abcdefghijklmnop', 12));
        self::assertSame('a [truncated]', $redactor->bounded('abcdefghijklmnop', 13));
        self::assertSame(' [truncated]', $redactor->bounded('éééééééé', 13));
        self::assertSame('é [truncated]', $redactor->bounded('éééééééé', 14));
    }

    public function test_negative_limits_and_header_credentials_have_exact_safe_output(): void
    {
        $config = new Repository([
            'raceproof' => [
                'capture' => [
                    'diagnostic_text_bytes' => -1,
                    'worker_output_bytes' => -1,
                    'redact_keys' => [],
                ],
            ],
        ]);
        $redactor = new SensitiveDataRedactor($config);

        self::assertSame('', $redactor->diagnostic('diagnostic'));
        self::assertSame('', $redactor->workerOutput('stderr', 'stdout'));
        self::assertSame('', $redactor->bounded('bounded', -1));
        self::assertSame(
            "Proxy-Authorization: [REDACTED]\nCookie: [REDACTED]\nSet-Cookie: [REDACTED]",
            $redactor->redact(
                "Proxy-Authorization: Bearer proxy-secret\n".
                "Cookie: session=cookie-secret\n".
                'Set-Cookie: session=set-cookie-secret',
            ),
        );
        self::assertSame("safe\u{FFFD}text", $redactor->redact("safe\x01text"));
        self::assertSame('', $redactor->bounded("\u{00E9}", 1));
        self::assertSame("\u{00C3}", $redactor->bounded("\u{00C3}\u{00A9}", 2));
    }
}
