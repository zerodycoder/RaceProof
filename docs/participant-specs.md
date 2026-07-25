# Per-participant requests and authentication

Global request, identity, and bootstrap settings remain the defaults for every worker. `forParticipant()` adds a validated override for one generated participant ID:

```php
use RaceProof\Laravel\ParticipantBuilder;

$result = race()
    ->participants(3)
    ->postJson('/api/checkout', ['sku' => 'default'])
    ->withHeaders(['X-Tenant' => 'acme'])
    ->actingAs($defaultUser)
    ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
        ->withPayload(['sku' => 'first'])
        ->withHeaders(['X-Tenant' => 'north'])
        ->withCookies(['locale' => 'en'])
        ->withToken($firstToken)
        ->actingAs($firstUser)
        ->withBootstrap(CheckoutParticipantBootstrap::class, [
            'tenant' => 'north',
        ]))
    ->forParticipant('p2', fn (ParticipantBuilder $participant) => $participant
        ->withPayload(['sku' => 'second'])
        ->withHeaders(['X-Tenant' => 'south']))
    ->run();
```

The callback runs only in the parent process. RaceProof stores its result as JSON data and never serializes or executes the closure in a worker.

## Override semantics

| Setting | Participant behavior |
| --- | --- |
| payload | replaces the complete global payload, including with an explicit empty array |
| headers | merges over global headers by name |
| cookies | merges over global cookies by name |
| token | writes the participant `Authorization` header and replaces a global authorization value |
| `actingAs` | replaces the global identity specification |
| bootstrap | replaces the global bootstrap specification |

Participant IDs are `p1` through `pN`, where `N` is the configured participant count. Invalid syntax is rejected when an override is defined. An ID outside the final race size is rejected while the plan is built, before the coordinator creates artifacts or starts a worker.

Payload and bootstrap values may contain only JSON-safe nulls, booleans, finite numbers, strings, lists, and string-keyed maps. Models, objects, closures, resources, non-finite numbers, sparse numeric maps, invalid header names, header line breaks, and cookie control characters are rejected.

## Persisted identities

`actingAs()` records only the Eloquent model class, scalar primary key, and guard name. Every worker constructs a new model query and loads that identity from the shared database before invoking the HTTP kernel.

The parent model must:

- have `exists === true`;
- have an integer or string primary key;
- implement Laravel's `Authenticatable` contract.

An in-memory model, factory instance that has not been saved, container mock, or parent database transaction cannot cross the process boundary.

```php
$firstBuyer = User::query()->findOrFail(11);
$secondBuyer = User::query()->findOrFail(12);

$result = race()
    ->participants(2)
    ->postJson('/api/checkout')
    ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
        ->actingAs($firstBuyer, 'web'))
    ->forParticipant('p2', fn (ParticipantBuilder $participant) => $participant
        ->actingAs($secondBuyer, 'web'))
    ->run();
```

## Session authentication

RaceProof sends the cookie value supplied to `withCookies()` through the real middleware stack. It does not invent or mutate a Laravel session. Create the authenticated session using the application under test, persist it in a store visible to every worker, and pass its real session cookie to the intended participant.

The `array` session driver is process-local and cannot prove cross-process session behavior. File sessions work for same-host RaceProof runs; database and Redis sessions also work when all workers use the same isolated test service. Application session blocking may intentionally serialize requests that share one session, so use distinct sessions when the race should remain concurrent.

Laravel encrypts cookies in the standard `web` middleware stack. Pass the encrypted client-side cookie, not a raw session ID, unless the application explicitly excludes that cookie from encryption.

## Token and Sanctum authentication

`withToken($token)` sends `Authorization: Bearer <token>`. A different scheme can be selected with the second argument. Empty tokens, invalid schemes, and line breaks are rejected.

This works with Laravel token guards and with Sanctum personal access tokens:

```php
$token = $user->createToken('raceproof', ['checkout'])->plainTextToken;

$result = race()
    ->participants(2)
    ->postJson('/api/checkout')
    ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
        ->withToken($token))
    ->run();
```

Sanctum first-party SPA authentication uses Laravel's normal session cookie path; test it using the session guidance above. Sanctum API-token authentication uses `withToken()`. RaceProof does not replace Sanctum middleware, ability checks, token expiry, or provider validation.

Avoid combining `actingAs()` and a request token for the same participant unless the test deliberately verifies guard precedence. The selected middleware and guard order remain application behavior.

## Credential handling

Workers need the real request credentials, so request headers and cookies are written to the permission-restricted `plan.json` inside the coordinator directory. Failed and timed-out runs retain that directory as evidence. Use disposable, least-privilege test tokens and sessions, keep artifact access restricted, and remove retained artifacts promptly after diagnosis.

See Laravel's [session documentation](https://laravel.com/docs/13.x/session) and [Sanctum documentation](https://laravel.com/docs/13.x/sanctum) for application-side storage and middleware requirements.
