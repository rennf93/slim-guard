<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSlim\SlimGuard;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Minimal guarded Slim 4 app. The guard composes the psr15-guard PSR-15
 * middleware via SlimGuard and screens every request that reaches the app,
 * including unmatched routes that would 404.
 */
$app = AppFactory::create();

$engine = new GuardEngine(new SecurityConfig(
    enableRedis: true,
    redisPrefix: getenv('REDIS_PREFIX') ?: 'guard_core_slim:',
    redisFailOpen: true,
    // The PHP port has no ExcludedDetectionHeaders surface yet, so the ssrf
    // category flags benign Host headers (e.g. "localhost:8080") on every
    // request. The demo disables that one category; SSRF hygiene belongs to
    // the proxy tier. XSS, SQLi, and the rest stay fully on.
    enabledDetectionCategories: array_values(
        array_diff(SecurityConfig::DETECTION_CATEGORIES, ['ssrf'])
    ),
    enableRateLimiting: true,
    rateLimit: 100,
    rateLimitWindow: 60,
    endpointRateLimits: [
        '/rate/strict' => ['limit' => 1, 'window' => 10],
    ],
    enableIpBanning: true,
    autoBanThreshold: 5,
    autoBanDuration: 300,
    // The engine's strike counter for auto-ban lives in memory per engine
    // instance, so under PHP shared-nothing a repeated-violation auto-ban
    // never accumulates across requests. The demo pins the xss threat ban at
    // threshold 1 instead: the first XSS payload trips the ban, and the ban
    // itself is Redis-backed and deterministic across requests.
    threatBanConfig: ['xss' => ['threshold' => 1, 'duration' => 300]],
    customErrorResponses: [403 => 'Blocked by slim-guard'],
    excludePaths: ['/health'],
));

// Add the guard LAST so it executes FIRST: middleware added later runs
// earlier in Slim, and nothing must read the request body stream before the
// engine's bounded body scan.
SlimGuard::forApp($app, $engine)->addTo($app);

$write = static function (Response $response, string $text): Response {
    $response->getBody()->write($text);

    return $response;
};

// Error middleware outermost (added after the guard, so it runs innermost of
// the two): turns routing errors (404) into responses instead of exceptions.
$app->addErrorMiddleware(false, true, false);

$app->get('/', fn (Request $request, Response $response) => $write($response, 'ok'));
$app->get('/health', fn (Request $request, Response $response) => $write($response, 'healthy'));
$app->get('/rate/strict', fn (Request $request, Response $response) => $write($response, 'strict ok'));
$app->get('/search', function (Request $request, Response $response) use ($write): Response {
    $q = htmlspecialchars((string) ($request->getQueryParams()['q'] ?? ''), ENT_QUOTES);

    return $write($response, 'search: ' . $q);
});

$app->run();
