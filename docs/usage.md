# Usage

## Construction

```php
final class SlimGuard
{
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        GuardEngine $engine
    ) { ... }

    public static function forApp(App $app, GuardEngine $engine, ?StreamFactoryInterface $streamFactory = null): self;
    public function middleware(): MiddlewareInterface;
    public function addTo(App $app): App;
    public function addToGroup(RouteGroupInterface $group): RouteGroupInterface;
}
```

`SlimGuard::forApp()` builds the guard from an existing Slim `App`: the
response factory is the app's own, and the stream factory resolves from the
explicit argument, then a response factory that also implements
`StreamFactoryInterface`, then the default slim/psr7 factory when installed. A
`LogicException` is thrown when none of those apply; pass a
`StreamFactoryInterface` explicitly instead of relying on slim/psr7.

The constructor composes the psr15-guard `GuardMiddleware`, which calls
`$engine->initialize()`; a `GuardRedisException` is swallowed only when
`SecurityConfig.redisFailOpen` is true, otherwise construction fails closed.

## Attachment

```php
SlimGuard::forApp($app, $engine)->addTo($app);        // app middleware: screens everything, including 404s

$group = $app->group('/admin', function ($group) { ... });
SlimGuard::forApp($groupApp, $adminEngine)->addToGroup($group);
                                                       // group middleware: only screens matched routes
```

`middleware()` exposes the composed PSR-15 middleware for hand wiring
(`$app->add($slimGuard->middleware())`) or for any stack that accepts PSR-15
middleware.

## Middleware ordering

Slim runs the last-added middleware first. Add the guard AFTER (in code order)
any middleware that reads the request body stream: Slim's optional
`BodyParsingMiddleware` casts the stream to a string, which leaves the read
pointer at EOF and starves the engine's bounded body scan. The guard should be
added last so it executes first.

## Verdicts

Block verdicts are translated by the composed psr15-guard middleware and never
reach your route callable:

| Situation | Status | Body |
|---|---|---|
| Banned IP | 403 | `IP address banned` |
| Suspicious content | 400 | `Suspicious activity detected` |
| Rate limit exceeded | 429 | `Too many requests` plus `Retry-After` |
| Engine malfunction | 500 | `Security check failed` |

Bodies can be overridden globally through `SecurityConfig.customErrorResponses`.

## Fail-closed behavior

Inherited from psr15-guard: if the engine check throws, the middleware
responds with the engine's fail-closed response (`500 Security check failed`)
rather than letting the request through. A custom 500 body comes from
`customErrorResponses[500]`, not from adapter code.

## Route-scoped configuration

The engine supports per-route `RouteConfig` (required headers, per-route rate
limits) keyed off a route ID on the request state, and the composed adapter
builds its engine request internally, so there is no route-ID hook. The
engine-sanctioned equivalent is the `customRequestCheck` config closure, which
runs as the last pipeline check; for Slim-native route scoping, attach a
dedicated guard to a route group with `addToGroup()` and its own engine
instance. See the
[advanced example app](https://github.com/rennf93/slim-guard/tree/master/examples/advanced_app).
