<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Support\Input;
use RaceProof\Laravel\Support\RequestData;

final readonly class ParticipantSpec implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $cookies
     */
    public function __construct(
        public ?array $payload = null,
        public array $headers = [],
        public array $cookies = [],
        public ?AuthSpec $auth = null,
        public ?BootstrapSpec $bootstrap = null,
    ) {
        if ($payload !== null) {
            RequestData::validatePayload($payload, 'participant.payload');
        }

        RequestData::validateHeaders($headers, 'participant.headers');
        RequestData::validateCookies($cookies, 'participant.cookies');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? null;
        $auth = $data['auth'] ?? null;
        $bootstrap = $data['bootstrap'] ?? null;

        return new self(
            payload: $payload === null ? null : Input::mapValue($payload, 'participant.payload'),
            headers: Input::stringMap($data, 'headers'),
            cookies: Input::stringMap($data, 'cookies'),
            auth: $auth === null ? null : AuthSpec::fromArray(Input::mapValue($auth, 'participant.auth')),
            bootstrap: $bootstrap === null
                ? null
                : BootstrapSpec::fromArray(Input::mapValue($bootstrap, 'participant.bootstrap')),
        );
    }

    public function request(RequestSpec $default): RequestSpec
    {
        return new RequestSpec(
            method: $default->method,
            uri: $default->uri,
            payload: $this->payload ?? $default->payload,
            headers: array_merge($default->headers, $this->headers),
            cookies: array_merge($default->cookies, $this->cookies),
            json: $default->json,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'payload' => $this->payload,
            'headers' => (object) $this->headers,
            'cookies' => (object) $this->cookies,
            'auth' => $this->auth,
            'bootstrap' => $this->bootstrap,
        ];
    }
}
