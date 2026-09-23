# AGENTS.md
Guidance for AI agents (including Claude Code) working in this repository.

## Project Overview

rennf93/slim-guard (https://github.com/rennf93/slim-guard) is a Slim 4 adapter for guard-core-php. It composes the psr15-guard PSR-15 middleware into Slim's middleware stack and adds the Slim-native wiring: PSR-7 factory resolution from Slim's `App`, one-call attachment to the app or a route group, and the body-parsing ordering guidance. It works with Slim 4.

- Composer package `rennf93/slim-guard`, type `library`, license MIT. No `version` field in composer.json (the sibling-adapter convention); versions come from git tags, of which there are none, so composer installs it as `dev-main`.
- This repository contains NO security logic and NO response translation. Detection, rate limiting, bans, verdicts, block-response shaping, and the fail-closed path all live in guard-core-php (engine) and psr15-guard (PSR-15 adapter). slim-guard only wires.
- Design, one sentence: Slim 4 middleware IS PSR-15 middleware and psr15-guard already provides exactly that, so slim-guard composes it instead of copying it.
- PHP `^8.2`. Autoload is PSR-4: `RenzoFranceschini\GuardCoreSlim\` maps to `src/`.
- Shipped tags: none. There are no git tags and no releases. `master` is protected: never push to it, never merge into it, never create tags or releases.
- Docs site: MkDocs Material in `docs/` (strict build in CI, gh-deploy on push to `master` touching docs sources). Runnable demos in `examples/` are exercised by the live-smoke workflow.

## Ecosystem Position

- `rennf93/guard-core-php` (https://github.com/rennf93/guard-core-php) is the engine. It owns `SecurityConfig`, `GuardEngine`, the `GuardRequest`/`GuardResponse` contracts, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException`. Every check, verdict, and block response body originates there.
- `rennf93/psr15-guard` (https://github.com/rennf93/psr15-guard) is the PSR-15 adapter for that engine. It owns `GuardMiddleware` (a standard `Psr\Http\Server\MiddlewareInterface`), `PsrGuardRequest` (the `GuardRequest` contract over PSR-7), and `ResponseTranslator` (guard verdicts to PSR-7 responses). Slim 4 accepts exactly this kind of middleware, which is why slim-guard requires it rather than reimplementing any of it.
- This package is the Slim wiring layer on top: the single `SlimGuard` class composes `Psr\Http\Server\MiddlewareInterface` (psr15-guard's `GuardMiddleware`) and offers Slim-idiomatic construction (`forApp`), attachment (`addTo`, `addToGroup`), and the composed middleware itself (`middleware()`).
- Composer constraints (composer.json `require`): `rennf93/guard-core-php: ^0.1.0`, `rennf93/psr15-guard: ^0.1.0`, `slim/slim: ^4.12`, `psr/http-factory: ^1.0`. Dev: `slim/psr7: ^1.7`. `composer.lock` pins v0.1.0 for both first-party packages (dist zipballs fetched from their GitHub VCS repositories).
- Repository configuration in composer.json, in order:
  1. Path repository `../guard-core-php`, marked `"canonical": false` (resolves when a sibling checkout of the core exists; non-canonical, so other sources win on conflict).
  2. VCS fallback `https://github.com/rennf93/guard-core-php.git` (how fresh checkouts and CI actually resolve the core).
  3. Path repository `../psr15-guard`, marked `"canonical": false` (same pattern for the PSR-15 sibling).
  4. VCS fallback `https://github.com/rennf93/psr15-guard.git`.
- `minimum-stability: dev` with `prefer-stable: true`, required until the core and the PSR-15 adapter have Packagist distributions. The README documents the same setup for consumers of this package.
- `config.platform.php: 8.2.0` pins dependency RESOLUTION to the lowest supported PHP so one lock installs across the 8.2/8.3/8.4 matrix (the sibling-adapter convention). Keep it.
- Dependencies that are deliberately NOT here: `slim/psr7` is required only as a dev dependency. slim-guard references `Slim\Psr7\Factory\StreamFactory` solely behind a `class_exists()` guard, so any PSR-7 implementation works (Nyholm, Guzzle); consumers of other implementations pass a `StreamFactoryInterface` explicitly. `psr/http-message` and `psr/http-server-*` arrive transitively (slim/slim and psr15-guard both require them); only `psr/http-factory` is declared directly because `SlimGuard` type-hints its interfaces.

## Boundary Rules

The adapter wires; the engine and psr15-guard decide.

- MUST NOT implement detection rules, penetration signatures, rate limiting, IP blacklisting or whitelisting, banning, security headers, or CORS. None of that exists in `src/` and none may be added.
- MUST NOT duplicate psr15-guard: no request adaptation (`PsrGuardRequest` already does it), no verdict handling, no block-response translation (`ResponseTranslator` already does it), no fail-closed catch (`GuardMiddleware::process()` already does it). If a feature seems to need any of those, it belongs in psr15-guard or guard-core-php, not here.
- MUST depend on psr15-guard's middleware for the entire request-screening path. `SlimGuard` constructs one `GuardMiddleware` and its only behaviors are constructing, exposing, and attaching it.
- MUST stay Slim-specific and wiring-only. `src/` imports only `Slim\*`, `Psr\Http\*`, and `RenzoFranceschini\GuardCore\*` + `RenzoFranceschini\GuardCorePsr15\*` classes. Any other import family is a boundary violation.
- MUST NOT require `slim/psr7` at runtime: the `Slim\Psr7\Factory\StreamFactory` reference is soft (`class_exists()`), so the package stays PSR-7-implementation-agnostic.
- MUST inherit the bounded body read: `PsrGuardRequest::MAX_BODY_BYTES = 262144` (256 KiB) matches the engine's full-scan window. slim-guard neither widens nor re-declares it.
- MUST NOT add a Slim service provider, container entry, or config-file-to-SecurityConfig mapper: configuration is constructor options only (the consumer builds a `SecurityConfig` and a `GuardEngine` themselves). That keeps a parallel config surface out of the ecosystem.
- MUST keep the wiring stateless: `SlimGuard` holds only the composed middleware, which holds no mutable state. Distributed rate limits, bans, and cloud-range caches require Redis.

Two wiring policies are deliberate and documented (both are wiring, not security logic):

- App-level attachment (`addTo`) screens everything that reaches the app, including unmatched paths that would 404, because Slim executes app middleware before routing. Group-level attachment (`addToGroup`) only screens requests that match a route inside the group: Slim appends group middleware when a route matches, so an unmatched path under the group is answered by routing (HttpNotFoundException) without the guard ever running. Group attachment scopes a dedicated engine to a matched route family; it is not full coverage.
- Middleware order relative to `BodyParsingMiddleware`: Slim's dispatcher runs the LAST-added middleware FIRST. Add the guard last so it executes first. `BodyParsingMiddleware` casts the request body stream to a string, and a slim/psr7 stream cast rewinds and reads to EOF, so when body parsing runs before the guard, the guard's forward read sees an empty stream and body attacks pass. Both orders are asserted in the test suite (the wrong order is asserted as a documented miss).

## Quick Start

This machine has NO `php` and NO `composer` binary. Run everything through Docker (`php:8.2-cli` / `php:8.3-cli` / `php:8.4-cli` for tests, `composer:2` for composer), or let CI execute.

Local install path (per composer.json):

1. Make the first-party deps resolvable: either check out `guard-core-php` and `psr15-guard` at sibling directories `../guard-core-php` and `../psr15-guard` (the path repositories) or rely on the VCS fallbacks.
2. `composer install` (the lock already pins both v0.1.0).
3. `composer lint`, then `composer test`.

Docker path used during development (host bind-mounts the ZZZ workspace so the sibling path repositories resolve at identical host paths; unit-only variant sets `REDIS_HOST=0`, the full variant runs the Redis integration against the host Redis on 6379 via host.docker.internal):

```
docker run --rm -v <workspace>:/work -w /work/slim-guard -e REDIS_HOST=0 php:8.3-cli php bin/test_slim.php
docker run --rm -v <workspace>:/work -w /work/slim-guard -e REDIS_HOST=host.docker.internal php:8.3-cli php bin/test_slim.php
docker run --rm -v <workspace>:/work -w /work/slim-guard composer:2 composer install --no-interaction --no-progress
```

The CI-verified path (copy this when describing a working environment; from `.github/workflows/ci.yml`):

1. Checkout this repo.
2. Checkout `rennf93/guard-core-php` at ref `guard-core-port-php` into `core-checkout`, then `mv core-checkout ../guard-core-php` so the path repository resolves. psr15-guard needs no checkout: its `^0.1.0` constraint resolves the v0.1.0 tag from the VCS fallback.
3. Set up PHP from the matrix (8.2, 8.3, or 8.4) with the `mbstring` extension, coverage none.
4. `composer install --no-interaction --no-progress`, then `composer update rennf93/guard-core-php --no-interaction`.
5. Run the php -l sweep, then `REDIS_HOST=127.0.0.1 php bin/test_slim.php` against a `redis:7-alpine` service on port 6379.

## Development Commands

Composer scripts (composer.json `scripts`; these are the only two):

| Command | What it runs |
| --- | --- |
| `composer test` | `php bin/test_slim.php` |
| `composer lint` | `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` |
| `mkdocs build --strict` | Build the docs site (run with `docker run --rm -v "$PWD":/work -w /work python:3.12-slim sh -c "pip install -q mkdocs-material && mkdocs build --strict"`; the `site/` output is gitignored) |
| `docker compose -f examples/simple_app/docker-compose.yml up --build -d --wait` | Bring up the simple example app plus Redis; then run the curl assertions from `.github/workflows/live-smoke.yml` |
| `docker compose -f examples/advanced_app/docker-compose.yml up --build -d --wait` | Same for the advanced example (assertions in `examples/advanced_app/README.md`) |

Direct commands used by CI (verified in `.github/workflows/ci.yml`; the same install, lint, and test steps appear in `release.yml` and `scheduled-lint.yml`):

- `composer install --no-interaction --no-progress`
- `composer update rennf93/guard-core-php --no-interaction` (CI deliberately refreshes the core dependency on every run)
- `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` (same sweep as `composer lint`)
- `php bin/test_slim.php` with env `REDIS_HOST=127.0.0.1`
- `composer audit` (Composer audit job, PHP 8.3)
- `composer validate --strict` must stay clean (run it after any composer.json edit)

`bin/` contains exactly one script: `bin/test_slim.php` (the whole test suite, plain PHP, no PHPUnit). There is no PHPUnit config, no PHPStan, and no PHP-CS-Fixer in this repo. There is no host PHP here either; examples and docs are verified with Docker (`composer:2` and `php:8.3-cli-alpine` images, `python:3.12-slim` for mkdocs).

## Project Structure

```
.github/dependabot.yml                Weekly dependabot: github-actions + composer (grouped)
.github/labels.yml                    Label registry for sync-labels
.github/labeler.yml                   PR area-label rules for labeler
.github/workflows/ci.yml              CI: test matrix php 8.2/8.3/8.4 + redis service + composer audit
.github/workflows/release.yml         Release Gate: same suite, runs on v* tags
.github/workflows/scheduled-lint.yml  Weekly cron (Mon 04:00 UTC): php -l sweep + composer audit
.github/workflows/issue-link.yml      PR must close an open issue or carry no-issue
.github/workflows/summary.yml         AI issue summary on the needs-summary label
.github/workflows/sync-labels.yml     Applies .github/labels.yml on push/dispatch
.github/workflows/greetings.yml       First-issue / first-PR welcome messages
.github/workflows/labeler.yml         Area labels from .github/labeler.yml
.github/workflows/stale.yml           Daily stale sweep with reminders
.github/workflows/live-smoke.yml      Dockerized compose smoke over examples/simple_app
.github/workflows/docs.yml            mkdocs strict build + gh-deploy on master docs changes
.github/workflows/container-release.yml  Publishes examples/advanced_app image to ghcr.io
.github/workflows/upstream-drift.yml  Daily suite run against guard-core-php@master
bin/test_slim.php                     Entire test suite, plain PHP runner with a T assertion harness
composer.json                         Package metadata, autoload, scripts, repositories, platform pin
composer.lock                         Locked deps; tracked; regenerate only deliberately
docs/index.md                         Docs home: what the adapter is, install, quick start
docs/usage.md                         SlimGuard surface, attachment points, ordering, verdicts
docs/configuration.md                 SecurityConfig surface pointers, Redis, body bound
examples/simple_app/                  Minimal guarded Slim app (compose app + redis), live-smoke target
examples/advanced_app/                Production-shaped app (env config, admin ban manager routes)
mkdocs.yml                            MkDocs Material site definition
src/SlimGuard.php                     The Slim wiring layer (factory resolution + attachment)
src/.agents/skills/slim-guard/        Package skill (SKILL.md)
LICENSE                               MIT, (c) 2026 Renzo Franceschini
README.md                             Install, usage, design (composition over duplication), behavior notes
AGENTS.md / CLAUDE.md                 Agent guide (byte-identical copies)
```

- `src/` is the PSR-4 package root for `RenzoFranceschini\GuardCoreSlim\`. It is flat: one final class per file, class name equals file name, no subdirectories. A second class would be a real surface change: update the README behavior notes in the same PR.
- `vendor/` exists on disk but is gitignored. Never `git add` it. `composer.lock` is tracked; never modify it casually.

## Technology Stack

- PHP `^8.2` (CI matrix: 8.2, 8.3, 8.4; the audit and scheduled-lint jobs run on 8.3).
- Runtime deps (composer.json `require`, with composer.lock versions):
  - `rennf93/guard-core-php ^0.1.0` (locked v0.1.0)
  - `rennf93/psr15-guard ^0.1.0` (locked v0.1.0; the composed PSR-15 adapter)
  - `slim/slim ^4.12` (locked v4.15.3)
  - `psr/http-factory ^1.0` (locked v1.1.0; direct because SlimGuard type-hints its interfaces)
- Dev deps: `slim/psr7 ^1.7` (locked v1.8.0), the Slim-default PSR-7 implementation used to build test requests and auto-detected by `forApp`.
- Transitive (not declared here): `psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware` (via slim/slim and psr15-guard), `nikic/fast-route`, `psr/container`, `psr/log` (via slim/slim), `fig/http-message-util` (via slim/psr7).
- No Slim service container is required: every test runs against a bare `AppFactory::create()` app.
- CI runs a `redis:7-alpine` service container on port 6379 with health checks for the Redis integration tests.
- Actions are pinned by commit SHA: `actions/checkout` v7.0.1, `shivammathur/setup-php` 2.37.2, `actions/ai-inference` v3, `crazy-max/ghaction-github-labeler` v6.0.0, `actions/first-interaction` v3.1.0, `actions/labeler` v7.0.0, `actions/stale` v11.0.0, `docker/login-action` v4.6.0, `docker/setup-compose-action` v2.4.0.
- Examples run on `php:8.3-cli-alpine` (PHP built-in webserver, non-root in the advanced app) with composer builds from `composer:2`; Redis is `redis:7-alpine`.
- Docs site: mkdocs-material, strict build, deployed to GitHub Pages by `docs.yml` on `master` pushes touching `docs/**`, `mkdocs.yml`, `README.md`, or `src/**`.
- The examples are demo code, not package surface: they live under `examples/`, carry their own composer.json (no committed lock file), and must never be autoloaded by the library.

## Testing Guidelines

- Run with `composer test` (or `php bin/test_slim.php`). Exit code 0 means green, 1 means red. Output: `ok - <label>` or `FAIL - <label>` per assertion, `=== section ===` headers, and a final `Passed: N, Failed: N` plus `N/N GREEN` or `N/N RED`.
- Redis integration: the runner attempts a socket connection to `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379). Set `REDIS_HOST=0` to force integration off; if no Redis is reachable it prints a SKIP line and unit coverage stands. CI sets `REDIS_HOST=127.0.0.1` against the redis service. Local docker uses `REDIS_HOST=host.docker.internal` against the host Redis. Integration uses a random `REDIS_PREFIX=guard_core_slim:<hex>:` and cleans keys before and after.
- Coverage areas (section names in `bin/test_slim.php`): composition surface (middleware() IS the psr15-guard GuardMiddleware, a standard MiddlewareInterface; addTo/addToGroup return the same instance); factory wiring (forApp resolves the Slim default slim/psr7 factories; explicit factories are the ones the composed translator uses, proven with recording factories; a response factory that is also a StreamFactoryInterface is reused instead of the fallback); app-level wiring screens before routing (blacklist 403 with the on_block payload, unmatched paths screened too, attack query 400 via Slim-parsed query params, attack POST body 400 via the raw PSR-7 stream, 301 https redirect with Location, 429 rate limit with Retry-After, whitelist miss 403, passive mode passes); pass-through (the route handler runs exactly once, keeps its URI, and carries Slim's route attribute attached downstream of the guard); route-group attachment (blocks matched group routes with a scoped engine, clean ips pass, unmatched paths under the group are answered by HttpNotFoundException, not the guard); body parsing order (guard added last executes first and blocks body attacks while Slim still parses the body downstream; the reversed order is asserted as the documented miss where the guard sees an empty stream); fail-secure inherited (engine malfunction 500 Security check failed with the route never reached, customErrorResponses override, check-exception fail-secure); redis fail-open versus fail-closed construction; plus the Redis integration sections (shared rate-limit bucket across two SlimGuard instances, ban written by engine A blocking engine B).
- Requests pass through Slim's real routing: any request expected to REACH a route needs that route registered on the app; requests expected to be blocked, redirected, or fail-closed need no route (the guard answers before routing).
- Any new engine behavior that flows through the adapter needs assertions here before the PR lands.
- The host machine cannot run the suite directly (no php binary); docker (`php:8.2-cli` / `php:8.3-cli` / `php:8.4-cli`) and CI are the executors.

## Code Quality Standards

- `declare(strict_types=1);` at the top of every PHP file in `src/` and `bin/`.
- `final` classes, `private readonly` promoted constructor properties, 4-space indentation, one class per file named after the class.
- Imports in `src/` are limited to `Slim\*`, `Psr\Http\*`, `RenzoFranceschini\GuardCore\*`, and `RenzoFranceschini\GuardCorePsr15\*`. A new import outside those families is a boundary violation (see Boundary Rules).
- PHP 8.4-only syntax is forbidden (for example `new Foo()->bar()` without parentheses); the supported floor is 8.2.
- The php -l sweep over `src` and `bin` must pass; `composer lint` prints `LINT_OK` on success.
- Conventional commits are the house style (see `git log` of the sibling repos): `feat:`, `fix:`, `fix(deps):`, `ci:`, `chore:`, `chore(composer):`, `test:`, `docs:`. Lowercase, imperative, no attribution trailers.
- Dependabot keeps github-actions and composer dependencies fresh weekly (composer updates are grouped into one PR).

## Best Practices

- `main` is protected. Work on a branch, push, open a PR (draft PRs are fine). Never push to `main`, never tag, never publish a release as part of agent work.
- Never `git add vendor/`, `.DS_Store`, or any stray file. Stage explicit paths only.
- Keep the adapter thin. If a change adds detection, verdict logic, or response shaping, it belongs in guard-core-php (engine) or psr15-guard (PSR-15 translation), not here. slim-guard's entire surface is construction, factory resolution, and attachment.
- Configuration is constructor options: consumers build the `SecurityConfig` and `GuardEngine` themselves and hand them to `SlimGuard::forApp($app, $engine)` or `new SlimGuard($responseFactory, $streamFactory, $engine)`. Do not add a config-array-to-SecurityConfig mapper; that is a parallel config surface.
- Preserve the composition relationship: any behavior change in screening or translation belongs upstream; this repo only changes wiring.
- CI checks out the core at branch `guard-core-port-php` into `../guard-core-php` so the path repository resolves, and `composer update rennf93/guard-core-php` floats within the `^0.1.0` constraint. The composer.json constraints (`^0.1.0` for both first-party packages, tagged v0.1.0) are the source of truth for the dependencies.
- Update README.md behavior notes in the same PR whenever adapter behavior changes (for example the body-parsing order note when the ordering guidance changes).
- The CI workflows are byte-identical to symfony-guard's except the step/script name (`test_slim`). Verify with `diff` after touching them.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/rennf93/guard-core-php. The engine. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there, and every verdict originates there. Resolved via the `../guard-core-php` path repository (canonical: false) with the VCS fallback `https://github.com/rennf93/guard-core-php.git`; CI checks out its `guard-core-port-php` branch.
- `rennf93/psr15-guard`: https://github.com/rennf93/psr15-guard. The composed PSR-15 adapter: `GuardMiddleware`, `PsrGuardRequest`, and `ResponseTranslator` live there and perform the entire screening and translation path. Resolved via the `../psr15-guard` path repository (canonical: false) with the VCS fallback `https://github.com/rennf93/psr15-guard.git`.
- `rennf93/laravel-guard`: https://github.com/rennf93/laravel-guard. The Laravel sibling adapter.
- `rennf93/symfony-guard`: https://github.com/rennf93/symfony-guard. The Symfony sibling adapter; the CI/docs precedent this repository byte-matches.
- `rennf93/slim-guard`: this repository, the Slim adapter layer of the guard-core ecosystem.
