# slim-guard

`slim-guard` is the official Slim 4 adapter for
[guard-core-php](https://github.com/rennf93/guard-core-php), the PHP port of the
guard-core security engine. It wires the engine into Slim's middleware stack by
composing [psr15-guard](https://github.com/rennf93/psr15-guard) (the PSR-15
middleware adapter for the same engine) and adds only the Slim-specific
integration layer: factory wiring, one-call attachment, and middleware ordering
guidance.

All security logic lives in the engine; this package contains no security logic
and no duplication of the PSR-15 adapter. Every verdict comes from
`GuardEngine::execute()`, and block responses are translated by the composed
psr15-guard middleware.

## Installation

```bash
composer require rennf93/slim-guard
```

Requires PHP 8.2 or later. Until `rennf93/guard-core-php` and
`rennf93/psr15-guard` have Packagist distributions, point composer at their
repositories and allow dev stability:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/rennf93/guard-core-php" },
        { "type": "vcs", "url": "https://github.com/rennf93/psr15-guard" }
    ]
}
```

## Quick start

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSlim\SlimGuard;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(
    enableRedis: true,
    redisPrefix: 'guard_core:',
    rateLimit: 100,
    rateLimitWindow: 60,
)))->addTo($app);
```

Blocked requests get the engine's `GuardResponse` translated to a PSR-7
response by psr15-guard (status, body, headers). Passing requests continue into
Slim's middleware stack untouched.

## What the wiring layer handles

- Factory wiring: the response factory comes from `$app->getResponseFactory()`,
  and a matching stream factory is resolved (explicit argument, response
  factory that is also a stream factory, or the default slim/psr7 factory)
- Attachment: app-level (`addTo`) screens everything that reaches the app,
  including unmatched routes that would 404; group-level (`addToGroup`) only
  screens requests that match a route inside the group
- Ordering: the last middleware added executes first in Slim; add the guard
  last so it runs before anything reads the request body stream
- Statelessness: `SlimGuard` and the composed middleware hold no mutable state

See [Usage](usage.md) for the full adapter surface and
[Configuration](configuration.md) for engine tuning. Runnable apps live in the
[examples](https://github.com/rennf93/slim-guard/tree/master/examples)
directory.
