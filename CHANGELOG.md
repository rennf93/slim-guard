Release Notes
=============

___

v1.0.0 (2026-09-24)
-------------------

First stable release (v1.0.0)
-----------------------------

### Added

- **Slim 4 adapter for guard-core-php 4.0.4.** slim-guard composes the psr15-guard PSR-15 middleware into Slim's App and adds the Slim-native integration layer; it contains no security logic and no detection logic of its own. Every verdict comes from `GuardEngine::execute()`, and the fail-secure path (block translation, fail-closed 500) is inherited from psr15-guard, not reimplemented here.
- **Factory wiring.** `SlimGuard::forApp($app, $engine)` takes the `ResponseFactoryInterface` from Slim's `App` and resolves a matching `StreamFactoryInterface` (explicit argument, response factory that is also a stream factory, or the default slim/psr7 implementation when installed).
- **Attachment.** `addTo($app)` calls Slim's `App::add()`; `addToGroup($group)` attaches to a route group with its documented semantics.
- **Body-parsing ordering guidance** for Slim's optional `BodyParsingMiddleware`, which consumes the request body stream.

### Changed

- **The engine dependency is pinned to `rennf93/guard-core-php` `^4.0.4`** and the composed PSR-15 adapter to `rennf93/psr15-guard` `^1.0.0`, both first stable releases, replacing the `^0.1.0` pins to the burned pre-release snapshot tags.

### Internal (v1.0.0)

- Unit and Redis-backed integration suites for the Slim wiring layer in `bin/test_slim.php` (Redis-backed shared-state cases run when `REDIS_HOST` points at a reachable Redis), plus a `php -l` sweep and `composer audit` in CI across PHP 8.2, 8.3 and 8.4.
- Community workflows, a MkDocs documentation site, and example apps landed via the parity-polish pass.

___
