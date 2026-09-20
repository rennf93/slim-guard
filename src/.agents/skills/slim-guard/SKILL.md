---
name: slim-guard
description: Use when an agent works in rennf93/slim-guard or touches its Slim adapter surface: Slim 4 wiring layer that composes the psr15-guard PSR-15 middleware into Slim's App and contains no security logic of its own; covers SlimGuard construction and factory resolution (forApp, slim/psr7 autodetection, explicit StreamFactoryInterface, LogicException), attachment points (addTo at app level screens 404s, addToGroup screens matched routes only), middleware ordering versus Slim's BodyParsingMiddleware (last added executes first), the fail-closed and bounded-body behavior inherited from psr15-guard, composer scripts (composer lint, composer test), and the plain-PHP test runner bin/test_slim.php with its REDIS_HOST integration mode.
---

# slim-guard

## Quick Reference

- Package `rennf93/slim-guard`; PSR-4 namespace `RenzoFranceschini\GuardCoreSlim\` mapped to `src/`, one final class: `SlimGuard`.
- It composes, it does not duplicate: `SlimGuard` builds a `RenzoFranceschini\GuardCorePsr15\GuardMiddleware` (the psr15-guard PSR-15 middleware) and only wires, exposes, and attaches it. Slim 4 middleware IS PSR-15 middleware, so that adapter already is the Slim middleware.
- Requires PHP `^8.2`, `rennf93/guard-core-php ^0.1.0`, `rennf93/psr15-guard ^0.1.0`, `slim/slim ^4.12`, `psr/http-factory ^1.0` (dev: `slim/psr7 ^1.7`).
- Commands: `composer lint` (php -l sweep over `src` and `bin`), `composer test` (`php bin/test_slim.php`). CI also runs `composer install --no-interaction --no-progress`, `composer update rennf93/guard-core-php --no-interaction`, the same php -l sweep, `REDIS_HOST=127.0.0.1 php bin/test_slim.php`, and `composer audit`.
- No security logic, no request adaptation, no response translation, no fail-closed catch: all of that is psr15-guard plus guard-core-php.

## Installation

```bash
composer require rennf93/slim-guard
```

Until `rennf93/guard-core-php` and `rennf93/psr15-guard` have Packagist releases, point Composer at their repositories (README setup):

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

The dependency constraints are `rennf93/guard-core-php: ^0.1.0` and `rennf93/psr15-guard: ^0.1.0`. This package's own composer.json resolves both locally through `../guard-core-php` and `../psr15-guard` path repositories (marked `"canonical": false`) with the VCS URLs above as fallbacks.

## Setup

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSlim\SlimGuard;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(
    enableRedis: false,
    blacklist: ['192.0.2.0/24'],
    rateLimit: 100,
    rateLimitWindow: 60,
    enableRateLimiting: true,
)))->addTo($app);

$app->get('/users', function ($request, $response) { /* ... */ });

$app->run();
```

- The consumer builds the `SecurityConfig` and the `GuardEngine`; this package ships no Slim service provider, no container entry, and no config-file-to-SecurityConfig mapper.
- Lifecycle: construct `GuardEngine` (and therefore `SlimGuard`) per request in classic FPM, or per worker under long-running runtimes (FrankenPHP, RoadRunner, workerman). `SlimGuard` and the composed middleware hold no mutable state.
- Redis: set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance for distributed rate limits, IP bans, and cloud-range caches. With `redisFailOpen: true` the guard still constructs and serves requests when Redis is unreachable; with `redisFailOpen: false` construction fails closed with `GuardRedisException` (inherited from psr15-guard).

## SlimGuard

- `final class SlimGuard`; the entire adapter surface.
- `new SlimGuard(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory, GuardEngine $engine)`. Constructs the composed psr15-guard middleware, which calls `$engine->initialize()`: a `GuardRedisException` is swallowed only when `$engine->config()->redisFailOpen` is true, otherwise construction fails closed.
- `SlimGuard::forApp(App $app, GuardEngine $engine, ?StreamFactoryInterface $streamFactory = null): self`. Takes the response factory from `$app->getResponseFactory()` and resolves the stream factory in this order: the explicit argument, then the response factory itself when it also implements `StreamFactoryInterface`, then a new `Slim\Psr7\Factory\StreamFactory` when slim/psr7 is installed (soft `class_exists()` reference), otherwise `LogicException` telling you to pass one explicitly.
- `middleware(): MiddlewareInterface` returns the composed psr15-guard middleware, so you can also attach it by hand (`$app->add(...)`) or use it anywhere PSR-15 middleware is accepted.
- `addTo(App $app): App` calls `$app->addMiddleware(...)` and returns the app. App-level middleware runs before routing, so this screens everything that reaches the app, including unmatched paths that would 404.
- `addToGroup(RouteGroupInterface $group): RouteGroupInterface` calls `$group->addMiddleware(...)`. Slim only runs group middleware for requests that match a route inside the group: a blacklisted IP hitting an unmatched path under the group gets the routing 404, not the guard's 403. Use it to scope a dedicated engine to a matched route family, not for app-wide coverage.
- Ordering: Slim's dispatcher runs the LAST-added middleware FIRST. Add the guard last (after `addBodyParsingMiddleware()`) so it executes first and sees the raw request body stream.

## Footguns

- No php and no composer on the dev machine: verify behavior by reading source and CI YAML; CI and docker are the executors (`php:8.2-cli` / `php:8.3-cli` / `php:8.4-cli` for tests, `composer:2` for composer).
- Body-parsing order is the sharpest edge: `BodyParsingMiddleware` casts the request body stream to a string, and a slim/psr7 cast rewinds then reads to EOF, leaving the read pointer at the end. If body parsing is added AFTER the guard (so it executes first), the guard's forward read sees an empty stream and body attacks pass. The test suite asserts both orders, including the documented miss.
- Group attachment is not app-wide coverage: unmatched paths under the group bypass the guard entirely (Slim appends group middleware only when a route matches). App-level `addTo` is the full-coverage choice.
- Requests that pass the guard go through Slim's real routing, so an app in tests (and in production) needs a matching route for them; blocked, redirected, or fail-closed requests never reach routing.
- Fail-closed is inherited and intentional: an engine exception yields `500 Security check failed` from psr15-guard, never a pass-through to Slim. `customErrorResponses` lives in the engine's `SecurityConfig`.
- Bounded body read is inherited: `PsrGuardRequest::MAX_BODY_BYTES` (256 KiB). Payloads beyond it, or signatures split across that boundary, are not detected. Do not change it here.
- `slim/psr7` is a dev dependency only, referenced behind `class_exists()`; never make it a hard runtime requirement or the package stops working with Nyholm, Guzzle, or other PSR-7 implementations, which simply pass a `StreamFactoryInterface` explicitly.
- `config.platform.php: 8.2.0` in composer.json pins dependency RESOLUTION to the lowest supported PHP so one lock installs across the 8.2/8.3/8.4 matrix. Keep it.
- In the test runner, `REDIS_HOST=0` forces integration mode off; otherwise it probes `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379) and prints SKIP if unreachable. CI sets `REDIS_HOST=127.0.0.1` with a `redis:7-alpine` service; local docker uses `host.docker.internal`.
- `main` is protected and there are no shipped tags: branch, PR, no direct pushes to `main`, no new tags or releases.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/rennf93/guard-core-php. The engine. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there, and every verdict originates there.
- `rennf93/psr15-guard`: https://github.com/rennf93/psr15-guard. The composed PSR-15 adapter. `GuardMiddleware` (the middleware this package wires in), `PsrGuardRequest`, and `ResponseTranslator` live there.
- `rennf93/laravel-guard`: https://github.com/rennf93/laravel-guard. The Laravel sibling adapter.
- `rennf93/symfony-guard`: https://github.com/rennf93/symfony-guard. The Symfony sibling adapter; the CI/docs precedent this repository byte-matches.
- `rennf93/slim-guard`: https://github.com/rennf93/slim-guard. This repository, the Slim adapter layer of the guard-core ecosystem.
