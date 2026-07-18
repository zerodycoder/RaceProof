# Participant bootstrap

Participant bootstraps perform process-local setup after a worker validates and loads its plan, but before it publishes READY. Each worker resolves a fresh application-authored class through Laravel's container.

```php
use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\ParticipantContext;

final readonly class CheckoutParticipantBootstrap implements ParticipantBootstrap
{
    public function __construct(private Config $config) {}

    public function bootstrap(ParticipantContext $context, array $configuration): void
    {
        $this->config->set('services.inventory.tenant', $configuration['tenant']);
        auth()->guard()->setUser(User::query()->findOrFail($configuration['user_id']));
    }
}
```

Register it on a race:

```php
$result = race()
    ->participants(3)
    ->postJson('/api/checkout')
    ->withBootstrap(CheckoutParticipantBootstrap::class, [
        'tenant' => 'acme',
        'user_id' => 42,
        'feature_flags' => ['new_checkout' => true],
    ])
    ->run();
```

Configuration may contain only JSON-safe `null`, booleans, finite numbers, strings, lists, and string-keyed maps. Closures, objects, models, resources, and PHP-serialized values are rejected. Put identifiers in configuration and resolve models/services inside the worker.

Bootstrap can configure environment, Laravel config, authentication, fakes backed by process-local services, or tenant context. Checkpoints are deliberately inactive during bootstrap and become active only after START is released, immediately before request execution. A bootstrap exception produces redacted participant evidence and `participant.bootstrap_failed`; the worker never announces READY.

Runtime container mutations in the parent process still do not cross into workers. Use a bootstrap class when the setup is genuinely process-local; use the shared test database for state every participant must observe.
