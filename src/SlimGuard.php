<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCoreSlim;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;
use Slim\App;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Psr7\Factory\StreamFactory as SlimPsr7StreamFactory;

/**
 * Wires the guard into Slim 4 by composing the psr15-guard PSR-15 middleware.
 *
 * Slim 4 middleware IS PSR-15 middleware, and rennf93/psr15-guard already
 * provides exactly that for guard-core-php. This class therefore contains no
 * security logic and translates nothing itself: every verdict comes from
 * GuardEngine::execute(), and the block-response translation and fail-secure
 * path are performed by the composed psr15-guard middleware (including the
 * fail-closed engine initialization it performs on construction: a
 * GuardRedisException is swallowed only when SecurityConfig::redisFailOpen is
 * true, otherwise constructing this class fails closed by rethrowing).
 *
 * What is Slim-specific here is the wiring: resolving the PSR-7 factories
 * Slim consumers already have (from App::getResponseFactory(), with the
 * default slim/psr7 stream factory auto-detected), attaching the composed
 * middleware to the App or to a route group, and the documented ordering
 * requirement relative to Slim's BodyParsingMiddleware (add this middleware
 * last so it executes first; Slim's dispatcher runs the last-added middleware
 * first, and middleware that casts the request body stream to a string before
 * the guard runs leaves the read pointer at EOF).
 */
final class SlimGuard
{
    private readonly GuardMiddleware $psr15Guard;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        GuardEngine $engine
    ) {
        $this->psr15Guard = new GuardMiddleware($engine, $responseFactory, $streamFactory);
    }

    /**
     * Builds the guard from an existing Slim App: the response factory is the
     * app's own ($app->getResponseFactory()), and the stream factory resolves
     * from the explicit argument, then the response factory when it also
     * implements StreamFactoryInterface, then the default slim/psr7 factory
     * when that package is installed. A LogicException is thrown when none
     * of those apply; pass a StreamFactoryInterface explicitly (for example
     * with Nyholm or Guzzle PSR-7) instead of relying on slim/psr7.
     */
    public static function forApp(App $app, GuardEngine $engine, ?StreamFactoryInterface $streamFactory = null): self
    {
        $responseFactory = $app->getResponseFactory();
        $streamFactory ??= self::resolveStreamFactory($responseFactory);

        return new self($responseFactory, $streamFactory, $engine);
    }

    /**
     * The composed psr15-guard middleware. It is a standard PSR-15
     * MiddlewareInterface, so it can also be added by hand
     * ($app->add($slimGuard->middleware())) or wrapped around anything that
     * accepts PSR-15 middleware.
     */
    public function middleware(): MiddlewareInterface
    {
        return $this->psr15Guard;
    }

    /**
     * App-level attachment: $app->addMiddleware($guard). App middleware runs
     * before routing, so the guard screens everything that reaches the app,
     * including requests that would 404. For full coverage this is the right
     * attachment point.
     */
    public function addTo(App $app): App
    {
        $app->addMiddleware($this->psr15Guard);

        return $app;
    }

    /**
     * Route-group attachment: $group->addMiddleware($guard). Slim only
     * executes group middleware for requests that MATCH a route inside the
     * group; a request hitting an unmatched path under the group is answered
     * by routing (404) without the guard ever running. Group attachment is
     * for scoping a dedicated engine to a matched route family, not for
     * screening the whole app.
     */
    public function addToGroup(RouteGroupInterface $group): RouteGroupInterface
    {
        return $group->addMiddleware($this->psr15Guard);
    }

    private static function resolveStreamFactory(ResponseFactoryInterface $responseFactory): StreamFactoryInterface
    {
        if ($responseFactory instanceof StreamFactoryInterface) {
            return $responseFactory;
        }

        // Soft reference: the class_exists guard triggers the autoloader, so
        // this branch is a no-op when slim/psr7 is not the installed PSR-7
        // implementation and slim-guard stays usable with any PSR-7 package.
        if (class_exists(SlimPsr7StreamFactory::class)) {
            return new SlimPsr7StreamFactory();
        }

        throw new \LogicException(
            'slim-guard could not resolve a StreamFactoryInterface: the App response factory does not implement '
            . StreamFactoryInterface::class . ' and slim/psr7 is not installed. Pass a StreamFactoryInterface '
            . 'explicitly: SlimGuard::forApp($app, $engine, $yourStreamFactory).'
        );
    }
}
