<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;

final readonly class RequestSpec implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $cookies
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $payload = [],
        public array $headers = [],
        public array $cookies = [],
        public bool $json = true,
    ) {
        if ($uri === '' || ! str_starts_with($uri, '/')) {
            throw new InvalidRacePlan('The request URI must start with /.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            method: strtoupper(Input::string($data, 'method')),
            uri: Input::string($data, 'uri'),
            payload: Input::map($data, 'payload'),
            headers: Input::stringMap($data, 'headers'),
            cookies: Input::stringMap($data, 'cookies'),
            json: Input::boolean($data, 'json', true),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'method' => strtoupper($this->method),
            'uri' => $this->uri,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'cookies' => $this->cookies,
            'json' => $this->json,
        ];
    }
}
