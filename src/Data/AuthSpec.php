<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Support\Input;

final readonly class AuthSpec implements JsonSerializable
{
    public function __construct(
        public string $model,
        public int|string $key,
        public string $guard = 'web',
    ) {
        if ($model === '') {
            throw new InvalidRacePlan('Authentication model must not be empty.');
        }

        if (trim($guard) === '') {
            throw new InvalidRacePlan('Authentication guard must not be empty.');
        }
    }

    public static function fromModel(Model $user, string $guard = 'web'): self
    {
        $key = $user->getKey();

        if (! $user->exists || (! is_int($key) && ! is_string($key))) {
            throw new InvalidRacePlan('actingAs() requires a persisted Eloquent model.');
        }

        if (! $user instanceof Authenticatable) {
            throw new InvalidRacePlan('actingAs() requires an Eloquent model that implements Authenticatable.');
        }

        return new self($user::class, $key, $guard);
    }

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
