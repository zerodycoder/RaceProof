# RaceProof Runtime

Lightweight PHP checkpoints for applications tested with RaceProof. During
normal application traffic they return immediately; a validated RaceProof
worker activates them only for a controlled concurrency test.

The package contains no Laravel integration, process runner, command,
coordinator, or network behavior. Signed prerelease `v1.0.0-beta.1` is available
from [Packagist](https://packagist.org/packages/raceproof/runtime).

```bash
composer require raceproof/runtime:^1.0.0-beta.1@beta
```

```php
race_point('after-read');

// Equivalent:
RaceProof\Runtime\Checkpoint::sync('after-read');
```

Calls return immediately unless a validated `raceproof/laravel` worker activates an in-memory handler. See the main project's [runtime deployment guide](https://github.com/zerodycoder/RaceProof/blob/main/docs/runtime-checkpoints.md) and [ADR 0001](https://github.com/zerodycoder/RaceProof/blob/main/docs/adr/0001-separate-runtime-checkpoint-package.md).
