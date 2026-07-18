<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

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
}
