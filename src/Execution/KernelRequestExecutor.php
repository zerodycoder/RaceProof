<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Data\AuthSpec;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\Clock;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class KernelRequestExecutor implements RequestExecutor
{
    public function __construct(
        private Kernel $kernel,
        private AuthFactory $auth,
        private Config $config,
        private SensitiveDataRedactor $redactor,
    ) {}

    public function execute(RacePlan $plan, ParticipantContext $context): ParticipantResult
    {
        $request = $this->makeRequest($plan, $context);
        $this->applyAuthentication($plan->authFor($context->participantId), $request);
        $startedAt = Clock::nowNs();
        $response = null;

        try {
            $response = $this->kernel->handle($request);

            return new ParticipantResult(
                runId: $context->runId,
                participantId: $context->participantId,
                status: $response->getStatusCode(),
                startedAtNs: $startedAt,
                finishedAtNs: Clock::nowNs(),
                body: $this->limitedBody($response),
                headers: $this->capturedHeaders($response),
            );
        } catch (Throwable $exception) {
            return new ParticipantResult(
                runId: $context->runId,
                participantId: $context->participantId,
                status: null,
                startedAtNs: $startedAt,
                finishedAtNs: Clock::nowNs(),
                exceptionClass: $exception::class,
                exceptionMessage: $this->redactor->diagnostic($exception->getMessage()),
            );
        } finally {
            if ($response instanceof Response) {
                $this->kernel->terminate($request, $response);
            }
        }
    }

    private function makeRequest(RacePlan $plan, ParticipantContext $context): Request
    {
        $spec = $plan->requestFor($context->participantId);
        $content = $spec->json ? json_encode($spec->payload, JSON_THROW_ON_ERROR) : null;
        $parameters = $spec->json ? [] : $spec->payload;
        $request = Request::create(
            uri: $spec->uri,
            method: strtoupper($spec->method),
            parameters: $parameters,
            cookies: $spec->cookies,
            content: $content,
        );

        foreach ($spec->headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        if ($spec->json) {
            $request->headers->set('Content-Type', 'application/json');
            $request->headers->set('Accept', 'application/json');
        }

        $request->headers->set('X-RaceProof-Run', $plan->runId);

        return $request;
    }

    private function applyAuthentication(?AuthSpec $auth, Request $request): void
    {
        if ($auth === null) {
            return;
        }

        $modelClass = $auth->model;

        if (! is_a($modelClass, Model::class, true)) {
            throw new RaceProofException("Authentication model [{$modelClass}] is not an Eloquent model.");
        }

        $model = new $modelClass;
        $user = $model->newQuery()->find($auth->key);

        if ($user === null) {
            throw new RaceProofException("Authentication model [{$modelClass}] was not found.");
        }

        if (! $user instanceof Authenticatable) {
            throw new RaceProofException("Authentication model [{$modelClass}] must implement Authenticatable.");
        }

        $guard = $this->auth->guard($auth->guard);
        $guard->setUser($user);
        $request->setUserResolver(fn () => $user);
    }

    private function limitedBody(Response $response): string
    {
        $limit = max(0, ConfigValue::integer($this->config, 'raceproof.capture.response_body_bytes', 16_384));

        return substr((string) $response->getContent(), 0, $limit);
    }

    /** @return array<string, string> */
    private function capturedHeaders(Response $response): array
    {
        $captured = [];
        $allowed = ConfigValue::stringList($this->config, 'raceproof.capture.headers');
        $redacted = array_map(static fn (string $name): string => strtolower($name), ConfigValue::stringList($this->config, 'raceproof.capture.redact_headers'));

        foreach ($allowed as $name) {
            $normalized = strtolower($name);

            if (in_array($normalized, $redacted, true)) {
                $captured[$normalized] = '[REDACTED]';
            } elseif ($response->headers->has($normalized)) {
                $captured[$normalized] = (string) $response->headers->get($normalized);
            }
        }

        return $captured;
    }
}
