<?php

declare(strict_types=1);

namespace RaceProof\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use RaceProof\Laravel\Data\AuthSpec;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Execution\RaceOrchestrator;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\RunId;

final class RaceBuilder
{
    private int $participants;

    private ?string $method = null;

    private ?string $uri = null;

    /** @var array<string, mixed> */
    private array $payload = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $cookies = [];

    private bool $json = true;

    private ?AuthSpec $auth = null;

    private ?BootstrapSpec $bootstrap = null;

    /** @var list<string> */
    private array $checkpoints = [];

    public function __construct(
        private readonly RaceOrchestrator $orchestrator,
        private readonly Config $config,
    ) {
        $this->participants = ConfigValue::integer($config, 'raceproof.runner.participants', 5);
    }

    public function participants(int $participants): self
    {
        $this->participants = $participants;

        return $this;
    }

    /** @param array<string, mixed> $payload */
    public function postJson(string $uri, array $payload = []): self
    {
        $this->method = 'POST';
        $this->uri = $uri;
        $this->payload = $payload;
        $this->json = true;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /** @param array<string, string> $cookies */
    public function withCookies(array $cookies): self
    {
        $this->cookies = array_merge($this->cookies, $cookies);

        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        $this->headers['Authorization'] = trim($type.' '.$token);

        return $this;
    }

    public function actingAs(Model $user, string $guard = 'web'): self
    {
        $key = $user->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidRacePlan('actingAs() requires a persisted Eloquent model.');
        }

        $this->auth = new AuthSpec($user::class, $key, $guard);

        return $this;
    }

    /** @param array<string, mixed> $configuration */
    public function withBootstrap(string $bootstrap, array $configuration = []): self
    {
        $this->bootstrap = new BootstrapSpec($bootstrap, $configuration);

        return $this;
    }

    public function startTogether(): self
    {
        return $this;
    }

    public function releaseWhenAllReach(string $checkpoint): self
    {
        if (! in_array($checkpoint, $this->checkpoints, true)) {
            $this->checkpoints[] = $checkpoint;
        }

        return $this;
    }

    public function run(): RaceResult
    {
        if ($this->method === null || $this->uri === null) {
            throw new InvalidRacePlan('Define a request before calling run().');
        }

        $plan = new RacePlan(
            runId: RunId::generate(),
            participants: $this->participants,
            request: new RequestSpec(
                method: $this->method,
                uri: $this->uri,
                payload: $this->payload,
                headers: $this->headers,
                cookies: $this->cookies,
                json: $this->json,
            ),
            auth: $this->auth,
            checkpoints: $this->checkpoints,
            spawnTimeoutMs: ConfigValue::integer($this->config, 'raceproof.runner.spawn_timeout_ms', 10_000),
            runTimeoutMs: ConfigValue::integer($this->config, 'raceproof.runner.run_timeout_ms', 15_000),
            pollIntervalMs: ConfigValue::integer($this->config, 'raceproof.runner.poll_interval_ms', 5),
            bootstrap: $this->bootstrap,
        );

        return $this->orchestrator->run($plan);
    }
}
