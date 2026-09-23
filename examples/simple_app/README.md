# slim-guard simple app

Minimal guarded PSR-15 app: a single `public/index.php` front controller that
wraps a plain `RequestHandlerInterface` router with
`RenzoFranceschini\GuardCorePsr15\GuardMiddleware` (the slim-guard adapter for
guard-core-php), with endpoint rate limiting, penetration detection, auto-ban,
and custom error bodies. It is the app exercised by the repo's `live-smoke`
workflow.

PSR-7 implementation is `laminas/laminas-diactoros` (PSR-17 factories and
server-request creation). The app has no framework; any PSR-15 stack (Slim 4,
Mezzio, ...) would attach the same middleware with `$app->add($guard)`.

## Run

```bash
docker compose -f examples/simple_app/docker-compose.yml up --build -d --wait
curl -i http://localhost:8080/
docker compose -f examples/simple_app/docker-compose.yml down -v
```

The engine runs with Redis enabled (`REDIS_HOST=redis`, `REDIS_PORT=6379` from
the compose environment); distributed rate limits and bans are shared state.

## Routes

| Route | Behavior |
|---|---|
| `GET /` | Returns `200 ok` |
| `GET /health` | Returns `200 healthy`; engine `excludePaths` makes it bypass the guard entirely |
| `GET /rate/strict` | Endpoint rate limit of 1 request per 10 seconds (`endpointRateLimits`); excess requests get `429 Too many requests` |
| `GET /search?q=` | Echoes `q` (HTML-escaped); XSS payloads are blocked by the engine with `400 Suspicious activity detected` |

Everything else returns `404` through the same guarded pipeline.

## Configuration

Engine tuning is `SecurityConfig` constructor state in `public/index.php`:

- Global rate limit 100 requests / 60 seconds, endpoint override for `/rate/strict`
- IP banning enabled, `autoBanThreshold` 5, `autoBanDuration` 300 seconds
- `threatBanConfig`: the `xss` threat ban is pinned at threshold 1 (deterministic
  under PHP shared-nothing: the engine's strike counter lives in memory per
  engine instance, so repeated-violation auto-ban cannot accumulate across
  requests; a threshold-1 threat ban fires on the first violation, and the ban
  itself is Redis-backed)
- `customErrorResponses`: `403` body is `Blocked by slim-guard`
- `excludePaths`: `/health`
- `redisFailOpen: true` so the app still boots if Redis is briefly unreachable

Redis connection details come from the environment: `REDIS_HOST`, `REDIS_PORT`,
plus `REDIS_PREFIX` for key namespacing (the engine reads `REDIS_HOST` and
`REDIS_PORT` itself).

## What the live smoke asserts

`.github/workflows/live-smoke.yml` brings this compose stack up with
`--wait` and asserts, in order:

1. `GET /` serves `200`
2. `GET /health` (excluded path) serves `200`
3. `GET /rate/strict` first request `200`, second request `429` with body `Too many requests`
4. XSS query param (`?q=<script>alert(1)</script>`) trips the `xss` threat ban (threshold 1): `403` with body `Blocked by slim-guard`
5. The same IP is now Redis-banned: `GET /` is blocked `403` with body `Blocked by slim-guard`

## Serving choice

The runtime image is `php:8.3-cli-alpine` serving through the PHP built-in
webserver (`php -S` with this front controller as router script): every request,
including 404s, passes through the guard. The built-in server is single-process
and meant for demos; production deployments should use FrankenPHP, FPM behind
nginx, or a long-running worker runtime (Swoole, RoadRunner, Octane) and
construct the engine per request (FPM) or per worker (long-running runtimes).
