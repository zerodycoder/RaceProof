# Runtime checkpoint deployment

No Packagist release exists yet. The constraints below become resolvable after
the first published beta.

Applications that call `race_point()` or `RaceProof\Runtime\Checkpoint::sync()` must install the tiny runtime as a production dependency and the orchestration package as a development dependency:

```bash
composer require raceproof/runtime:^0.1
composer require raceproof/laravel:^0.1 --dev
```

Do not rely on the main package's transitive runtime dependency: Composer removes transitive dependencies that are needed only by a root dev dependency during `composer install --no-dev`. Declare `raceproof/runtime` directly in the application's `require` section.

Outside a validated RaceProof worker, checkpoint calls are no-ops. The runtime does not register commands, spawn processes, inspect requests, read environment flags, or contact a network service.

## Upgrade paths

- Full package currently in production: require `raceproof/runtime`, migrate application imports from `RaceProof\Laravel\Facades\RacePoint` to `race_point()` or `RaceProof\Runtime\Checkpoint`, then move `raceproof/laravel` to `require-dev`.
- Guarded helper calls: require the runtime, then the `function_exists('race_point')` guard may be removed.
- No application checkpoints: keep only `raceproof/laravel` in `require-dev`; no runtime root requirement is necessary.

Always run `composer install --no-dev` in a deployment smoke test and execute an instrumented code path. It must load successfully and the checkpoint must return immediately.
