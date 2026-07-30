<?php

declare(strict_types=1);

namespace RaceProof\Laravel;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use RaceProof\Laravel\Data\AuthSpec;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\ParticipantSpec;
use RaceProof\Laravel\Data\QueueSpec;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Execution\RaceOrchestrator;
use RaceProof\Laravel\Queue\QueueRaceDispatcher;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\ParticipantId;
use RaceProof\Laravel\Support\RequestData;
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

    /** @var array<string, ParticipantSpec> */
    private array $participantSpecs = [];

    /** @var list<string> */
    private array $checkpoints = [];

    /** @var (Closure(string): mixed)|null */
    private ?Closure $queueJobFactory = null;

    private string $queueConnection = 'default';

    private int $queueMaxAttempts = 1;

    private int $queueBackoffSeconds = 0;

    /** @internal Resolve the builder through race() or Laravel's container. */
    public function __construct(
        private readonly RaceOrchestrator $orchestrator,
        private readonly QueueRaceDispatcher $queueDispatcher,
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
        if ($this->queueJobFactory !== null) {
            throw new InvalidRacePlan('A race cannot define both an HTTP request and queue jobs.');
        }

        $this->method = 'POST';
        $this->uri = $uri;
        $this->payload = $payload;
        $this->json = true;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $this->ensureHttpOptionsAllowed();
        RequestData::validateHeaders($headers);
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /** @param array<string, string> $cookies */
    public function withCookies(array $cookies): self
    {
        $this->ensureHttpOptionsAllowed();
        RequestData::validateCookies($cookies);
        $this->cookies = array_merge($this->cookies, $cookies);

        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        $this->ensureHttpOptionsAllowed();
        $this->headers['Authorization'] = RequestData::authorization($token, $type);

        return $this;
    }

    public function actingAs(Model $user, string $guard = 'web'): self
    {
        $this->ensureHttpOptionsAllowed();
        $this->auth = AuthSpec::fromModel($user, $guard);

        return $this;
    }

    /** @param array<string, mixed> $configuration */
    public function withBootstrap(string $bootstrap, array $configuration = []): self
    {
        $this->bootstrap = new BootstrapSpec($bootstrap, $configuration);

        return $this;
    }

    /** @param Closure(ParticipantBuilder): mixed $configure */
    public function forParticipant(string $participantId, Closure $configure): self
    {
        ParticipantId::number($participantId);
        $participant = new ParticipantBuilder($this->participantSpecs[$participantId] ?? null);
        $configure($participant);
        $spec = $participant->spec();

        if ($this->queueJobFactory !== null && ! $this->queueCompatible($spec)) {
            throw new InvalidRacePlan('Queue participant overrides may configure bootstrap only.');
        }

        $this->participantSpecs[$participantId] = $spec;

        return $this;
    }

    /**
     * @param  Closure(string): mixed  $jobFactory
     */
    public function queue(Closure $jobFactory, string $connection = 'default'): self
    {
        if (
            $this->method !== null
            || $this->uri !== null
            || $this->payload !== []
            || $this->headers !== []
            || $this->cookies !== []
            || $this->auth !== null
        ) {
            throw new InvalidRacePlan('A race cannot define both an HTTP request and queue jobs.');
        }

        foreach ($this->participantSpecs as $spec) {
            if (! $this->queueCompatible($spec)) {
                throw new InvalidRacePlan('Queue participant overrides may configure bootstrap only.');
            }
        }

        $this->queueJobFactory = $jobFactory;
        $this->queueConnection = $connection;

        return $this;
    }

    public function queueAttempts(int $maxAttempts, int $backoffSeconds = 0): self
    {
        if ($this->queueJobFactory === null) {
            throw new InvalidRacePlan('Define queue jobs before configuring queue attempts.');
        }

        $this->queueMaxAttempts = $maxAttempts;
        $this->queueBackoffSeconds = $backoffSeconds;

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
        if ($this->queueJobFactory !== null) {
            $runId = RunId::generate();
            $queueSpec = new QueueSpec(
                connection: $this->queueConnection,
                maxAttempts: $this->queueMaxAttempts,
                backoffSeconds: $this->queueBackoffSeconds,
            );

            return $this->queueDispatcher->run(
                participants: $this->participants,
                queueSpec: $queueSpec,
                jobFactory: $this->queueJobFactory,
                planFactory: fn (QueueSpec $preparedQueue): RacePlan => new RacePlan(
                    runId: $runId,
                    participants: $this->participants,
                    request: null,
                    checkpoints: $this->checkpoints,
                    spawnTimeoutMs: ConfigValue::integer(
                        $this->config,
                        'raceproof.runner.spawn_timeout_ms',
                        10_000,
                    ),
                    runTimeoutMs: ConfigValue::integer(
                        $this->config,
                        'raceproof.runner.run_timeout_ms',
                        15_000,
                    ),
                    pollIntervalMs: ConfigValue::integer(
                        $this->config,
                        'raceproof.runner.poll_interval_ms',
                        5,
                    ),
                    bootstrap: $this->bootstrap,
                    participantSpecs: $this->participantSpecs,
                    queue: $preparedQueue,
                ),
            );
        }

        if ($this->method === null || $this->uri === null) {
            throw new InvalidRacePlan('Define a request or queue jobs before calling run().');
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
            participantSpecs: $this->participantSpecs,
        );

        return $this->orchestrator->run($plan);
    }

    private function ensureHttpOptionsAllowed(): void
    {
        if ($this->queueJobFactory !== null) {
            throw new InvalidRacePlan('Request authentication and transport options are unavailable in queue races.');
        }
    }

    private function queueCompatible(ParticipantSpec $spec): bool
    {
        return $spec->payload === null
            && $spec->headers === []
            && $spec->cookies === []
            && $spec->auth === null;
    }
}
