<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Exceptions\InvalidRacePlan;

final class RequestData
{
    /** @param array<string, mixed> $payload */
    public static function validatePayload(array $payload, string $path = 'payload'): void
    {
        JsonValue::assertObject($payload, $path, 'Request field');
    }

    /** @param array<string, string> $headers */
    public static function validateHeaders(array $headers, string $path = 'headers'): void
    {
        foreach ($headers as $name => $value) {
            if (! is_string($name) || $name === '' || ! preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name)) {
                throw new InvalidRacePlan("Request field [{$path}] contains an invalid header name.");
            }

            if (! is_string($value) || preg_match('/[\r\n]/', $value)) {
                throw new InvalidRacePlan("Request field [{$path}.{$name}] must be a string without line breaks.");
            }
        }
    }

    /** @param array<string, string> $cookies */
    public static function validateCookies(array $cookies, string $path = 'cookies'): void
    {
        foreach ($cookies as $name => $value) {
            if (! is_string($name) || $name === '' || ! preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name)) {
                throw new InvalidRacePlan("Request field [{$path}] contains an invalid cookie name.");
            }

            if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidRacePlan("Request field [{$path}.{$name}] must be a string without control characters.");
            }
        }
    }

    public static function authorization(string $token, string $type): string
    {
        $token = trim($token);
        $type = trim($type);

        if ($token === '') {
            throw new InvalidRacePlan('Authentication token must not be empty.');
        }

        if ($type === '' || ! preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $type)) {
            throw new InvalidRacePlan('Authentication token type is invalid.');
        }

        if (preg_match('/[\r\n]/', $token)) {
            throw new InvalidRacePlan('Authentication token must not contain line breaks.');
        }

        return $type.' '.$token;
    }
}
