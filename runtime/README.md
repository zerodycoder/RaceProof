# RaceProof Runtime

Lightweight PHP checkpoints for applications tested with RaceProof. During
normal application traffic they return immediately; a validated RaceProof
worker activates them only for a controlled concurrency test.

The package contains no Laravel integration, process runner, command,
coordinator, or network behavior. Packagist currently exposes `dev-main`; the
install command below becomes resolvable after the first tagged beta.

```bash
composer require raceproof/runtime:^0.1
```

```php
race_point('after-read');

// Equivalent:
RaceProof\Runtime\Checkpoint::sync('after-read');
```

Calls return immediately unless a validated `raceproof/laravel` worker activates an in-memory handler. See the main project's [runtime deployment guide](https://github.com/zerodycoder/RaceProof/blob/main/docs/runtime-checkpoints.md) and [ADR 0001](https://github.com/zerodycoder/RaceProof/blob/main/docs/adr/0001-separate-runtime-checkpoint-package.md).
