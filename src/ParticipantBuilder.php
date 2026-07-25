<?php

declare(strict_types=1);

namespace RaceProof\Laravel;

use Illuminate\Database\Eloquent\Model;
use RaceProof\Laravel\Data\AuthSpec;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\ParticipantSpec;
use RaceProof\Laravel\Support\RequestData;

final class ParticipantBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $payload;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, string> */
    private array $cookies;

    private ?AuthSpec $auth;

    private ?BootstrapSpec $bootstrap;

    /** @internal RaceBuilder creates participant builders for forParticipant(). */
    public function __construct(?ParticipantSpec $spec = null)
    {
        if ($spec === null) {
            $this->payload = null;
            $this->headers = [];
            $this->cookies = [];
            $this->auth = null;
            $this->bootstrap = null;

            return;
        }

        $this->payload = $spec->payload;
        $this->headers = $spec->headers;
        $this->cookies = $spec->cookies;
        $this->auth = $spec->auth;
        $this->bootstrap = $spec->bootstrap;
    }

    /** @param array<string, mixed> $payload */
    public function withPayload(array $payload): self
    {
        RequestData::validatePayload($payload, 'participant.payload');
        $this->payload = $payload;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        RequestData::validateHeaders($headers, 'participant.headers');
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /** @param array<string, string> $cookies */
    public function withCookies(array $cookies): self
    {
        RequestData::validateCookies($cookies, 'participant.cookies');
        $this->cookies = array_merge($this->cookies, $cookies);

        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        $this->headers['Authorization'] = RequestData::authorization($token, $type);

        return $this;
    }

    public function actingAs(Model $user, string $guard = 'web'): self
    {
        $this->auth = AuthSpec::fromModel($user, $guard);

        return $this;
    }

    /** @param array<string, mixed> $configuration */
    public function withBootstrap(string $bootstrap, array $configuration = []): self
    {
        $this->bootstrap = new BootstrapSpec($bootstrap, $configuration);

        return $this;
    }

    /** @internal Used only to serialize the fluent participant configuration. */
    public function spec(): ParticipantSpec
    {
        return new ParticipantSpec(
            payload: $this->payload,
            headers: $this->headers,
            cookies: $this->cookies,
            auth: $this->auth,
            bootstrap: $this->bootstrap,
        );
    }
}
