<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use JsonSerializable;
use RaceProof\Laravel\Support\Input;

final readonly class AuthSpec implements JsonSerializable
{
    public function __construct(
        public string $model,
        public int|string $key,
        public string $guard = 'web',
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Input::string($data, 'model'),
            Input::key($data, 'key'),
            array_key_exists('guard', $data) ? Input::string($data, 'guard') : 'web',
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['model' => $this->model, 'key' => $this->key, 'guard' => $this->guard];
    }
}
