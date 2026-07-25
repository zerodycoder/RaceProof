<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use Illuminate\Contracts\Config\Repository as Config;

final readonly class SensitiveDataRedactor
{
    public function __construct(private Config $config) {}

    public function diagnostic(string $value): string
    {
        $limit = max(0, ConfigValue::integer($this->config, 'raceproof.capture.diagnostic_text_bytes', 4_096));

        return $this->bounded($value, $limit);
    }

    public function workerOutput(string $errorOutput, string $output): string
    {
        $limit = max(0, ConfigValue::integer($this->config, 'raceproof.capture.worker_output_bytes', 4_096));

        return $this->bounded(trim($errorOutput."\n".$output), $limit);
    }

    public function bounded(string $value, int $limit): string
    {
        return $this->limit($this->redact($value), max(0, $limit));
    }

    public function redact(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u',
            "\u{FFFD}",
            $value,
        ) ?? '[REDACTED]';
        $redacted = preg_replace(
            '/\b((?:proxy-)?authorization|cookie|set-cookie)\s*:\s*[^\r\n]*/i',
            '$1: [REDACTED]',
            $value,
        );

        if ($redacted === null) {
            return '[REDACTED]';
        }

        $keys = array_values(array_unique(array_filter(array_map(
            static fn (string $key): string => trim($key),
            ConfigValue::stringList($this->config, 'raceproof.capture.redact_keys'),
        ), static fn (string $key): bool => $key !== '')));

        if ($keys !== []) {
            $pattern = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys));
            $credentialRedacted = preg_replace(
                '/((?:["\']?(?:'.$pattern.')["\']?)\s*[:=]\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;}\]]+)/i',
                '$1[REDACTED]',
                $redacted,
            );

            if ($credentialRedacted === null) {
                return '[REDACTED]';
            }

            $redacted = $credentialRedacted;
        }

        return preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $redacted) ?? '[REDACTED]';
    }

    private function limit(string $value, int $limit): string
    {
        if ($value === '' || $limit === 0 || strlen($value) <= $limit) {
            return $limit === 0 ? '' : $value;
        }

        $marker = ' [truncated]';

        if ($limit <= strlen($marker)) {
            return $this->utf8Prefix($value, $limit);
        }

        return $this->utf8Prefix($value, $limit - strlen($marker)).$marker;
    }

    private function utf8Prefix(string $value, int $limit): string
    {
        $prefix = substr($value, 0, $limit);

        while ($prefix !== '' && ! mb_check_encoding($prefix, 'UTF-8')) {
            $prefix = substr($prefix, 0, -1);
        }

        return $prefix;
    }
}
