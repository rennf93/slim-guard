<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCore\Request\GuardRequest;
use RenzoFranceschini\GuardCore\Request\GuardResponse;
use RenzoFranceschini\GuardCore\Redis\GuardRedisException;
use RenzoFranceschini\GuardCore\Redis\RedisHandler;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware as Psr15GuardMiddleware;
use RenzoFranceschini\GuardCoreSlim\SlimGuard;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Routing\RouteContext;

require __DIR__ . '/../vendor/autoload.php';

final class T
{
    public int $passed = 0;

    public int $failed = 0;

    public function same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
            echo '  expected: ' . var_export($expected, true) . "\n";
            echo '  actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public function ok(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
        }
    }

    public function throws(string $class, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->failed++;
            echo "FAIL - {$label}: no exception\n";
        } catch (Throwable $e) {
            $this->same($class, $e::class, $label);
        }
    }

    public function section(string $name): void
    {
        echo "\n=== {$name} ===\n";
    }
}

final class RouteRecorder
{
    public int $calls = 0;

    public ?ServerRequestInterface $seen = null;

    public mixed $parsedBody = null;

    public function respondWith(string $body): \Closure
    {
        return function (ServerRequestInterface $request, ResponseInterface $response) use ($body) {
            $this->calls++;
            $this->seen = $request;

            return $response->withBody((new StreamFactory())->createStream($body));
        };
    }

    public function respondWithParsedBody(string $body): \Closure
    {
        return function (ServerRequestInterface $request, ResponseInterface $response) use ($body) {
            $this->calls++;
            $this->seen = $request;
            $this->parsedBody = $request->getParsedBody();

            return $response->withBody((new StreamFactory())->createStream($body));
        };
    }
}

final class RecordingResponseFactory implements ResponseFactoryInterface
{
    /** @var list<int> */
    public array $createdStatuses = [];

    public function __construct(private readonly ResponseFactoryInterface $inner)
    {
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->createdStatuses[] = $code;

        return $this->inner->createResponse($code, $reasonPhrase);
    }
}

final class RecordingStreamFactory implements StreamFactoryInterface
{
    /** @var list<string> */
    public array $created = [];

    public function __construct(private readonly StreamFactoryInterface $inner)
    {
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $this->created[] = $content;

        return $this->inner->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->inner->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->inner->createStreamFromResource($resource);
    }
}

final class DualFactory implements ResponseFactoryInterface, StreamFactoryInterface
{
    /** @var list<int> */
    public array $createdStatuses = [];

    /** @var list<string> */
    public array $createdStreams = [];

    private readonly ResponseFactoryInterface $responses;

    private readonly StreamFactoryInterface $streams;

    public function __construct()
    {
        $this->responses = new ResponseFactory();
        $this->streams = new StreamFactory();
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->createdStatuses[] = $code;

        return $this->responses->createResponse($code, $reasonPhrase);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $this->createdStreams[] = $content;

        return $this->streams->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->streams->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->streams->createStreamFromResource($resource);
    }
}

final class ThrowingUriRequest implements ServerRequestInterface
{
    public function __construct(private readonly ServerRequestInterface $inner)
    {
    }

    public function getUri(): UriInterface
    {
        throw new RuntimeException('malformed request uri');
    }

    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        return new static($this->inner->withProtocolVersion($version));
    }

    public function getHeaders(): array
    {
        return $this->inner->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->inner->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->inner->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->inner->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        return new static($this->inner->withHeader($name, $value));
    }

    public function withAddedHeader(string $name, $value): static
    {
        return new static($this->inner->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): static
    {
        return new static($this->inner->withoutHeader($name));
    }

    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        return new static($this->inner->withBody($body));
    }

    public function getRequestTarget(): string
    {
        return $this->inner->getRequestTarget();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        return new static($this->inner->withRequestTarget($requestTarget));
    }

    public function getMethod(): string
    {
        return $this->inner->getMethod();
    }

    public function withMethod(string $method): static
    {
        return new static($this->inner->withMethod($method));
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return new static($this->inner->withUri($uri, $preserveHost));
    }

    public function getServerParams(): array
    {
        return $this->inner->getServerParams();
    }

    public function getCookieParams(): array
    {
        return $this->inner->getCookieParams();
    }

    public function withCookieParams(array $cookies): static
    {
        return new static($this->inner->withCookieParams($cookies));
    }

    public function getQueryParams(): array
    {
        return $this->inner->getQueryParams();
    }

    public function withQueryParams(array $query): static
    {
        return new static($this->inner->withQueryParams($query));
    }

    public function getParsedBody(): mixed
    {
        return $this->inner->getParsedBody();
    }

    public function withParsedBody($data): static
    {
        return new static($this->inner->withParsedBody($data));
    }

    public function getAttributes(): array
    {
        return $this->inner->getAttributes();
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->inner->getAttribute($name, $default);
    }

    public function withAttribute(string $name, $value): static
    {
        return new static($this->inner->withAttribute($name, $value));
    }

    public function withoutAttribute(string $name): static
    {
        return new static($this->inner->withoutAttribute($name));
    }

    public function getUploadedFiles(): array
    {
        return $this->inner->getUploadedFiles();
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        return new static($this->inner->withUploadedFiles($uploadedFiles));
    }
}

/**
 * @param array<string, string> $headers
 */
function slimRequest(
    string $path = '/',
    string $ip = '203.0.113.9',
    string $method = 'GET',
    string $query = '',
    string $body = '',
    array $headers = []
): ServerRequestInterface {
    $request = (new ServerRequestFactory())->createServerRequest(
        $method,
        'http://ex.test' . $path . ($query !== '' ? '?' . $query : ''),
        ['REMOTE_ADDR' => $ip]
    );
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    if ($body !== '') {
        $request = $request->withBody((new StreamFactory())->createStream($body));
    }

    return $request;
}

/**
 * @return array{0: App, 1: SlimGuard}
 */
function makeApp(SecurityConfig $config): array
{
    $app = AppFactory::create();
    $guard = SlimGuard::forApp($app, new GuardEngine($config));
    $guard->addTo($app);

    return [$app, $guard];
}

/**
 * @param list<array<string, mixed>> $hooks
 */
function hookCapture(array &$hooks): \Closure
{
    return function (object $request, array $payload) use (&$hooks): void {
        $hooks[] = $payload;
    };
}

$t = new T();
$attackQuery = 'q=' . urlencode("<script>alert('xss')</script>");

$t->section('composition surface: psr15-guard is composed, not reimplemented');
$app = AppFactory::create();
$guard = SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(enableRedis: false)));
$t->ok($guard->middleware() instanceof Psr15GuardMiddleware, 'middleware() is the psr15-guard GuardMiddleware');
$t->ok($guard->middleware() instanceof MiddlewareInterface, 'middleware() is a standard PSR-15 middleware');
$t->same($app, $guard->addTo($app), 'addTo() attaches to the app and returns the same App instance');
$group = $app->group('/unused-group', static function (RouteCollectorProxyInterface $api): void {
});
$t->same($group, $guard->addToGroup($group), 'addToGroup() attaches to the group and returns the same group');

$t->section('factory wiring: SlimGuard::forApp resolves the Slim default factories');
$app = AppFactory::create();
$guard = SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(enableRedis: false)));
$guard->addTo($app);
$users = new RouteRecorder();
$app->get('/users', $users->respondWith('users'));
$request = slimRequest('/users', '198.51.100.7');
$response = $app->handle($request);
$t->ok($response instanceof ResponseInterface, 'app->handle returns a PSR-7 response');
$t->same(200, $response->getStatusCode(), 'clean request reaches the Slim route');
$t->same('users', (string) $response->getBody(), 'route response body returned unchanged');
$t->same(1, $users->calls, 'route handler called exactly once');
$t->ok($users->seen !== null, 'route handler received a request forwarded by the guard');
$t->same('/users', $users->seen->getUri()->getPath(), 'forwarded request kept its URI through the guard');
$t->ok($users->seen->getAttribute(RouteContext::ROUTE) !== null, 'Slim routing ran downstream of the guard (route attribute attached by Slim, not the adapter)');

$t->section('app-level wiring screens before routing');
$hooks = [];
$users = new RouteRecorder();
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks)));
$app->get('/users', $users->respondWith('users'));
$blocked = $app->handle(slimRequest('/users', '192.0.2.66'));
$t->same(403, $blocked->getStatusCode(), 'blacklisted ip -> 403 at app level');
$t->same('Forbidden', (string) $blocked->getBody(), '403 body exact (translated by psr15-guard)');
$t->same(0, $users->calls, 'route handler never called on block');
$t->same('ip_security', $hooks[0]['check_name'] ?? null, 'on_block check_name');
$t->same('IP blacklisted: 192.0.2.66', $hooks[0]['reason'] ?? null, 'on_block reason');
$t->same(403, $hooks[0]['status_code'] ?? null, 'on_block status_code');
$t->same(false, $hooks[0]['passive_mode'] ?? null, 'on_block passive_mode false');
$notFound = $app->handle(slimRequest('/nope', '192.0.2.66'));
$t->same(403, $notFound->getStatusCode(), 'unmatched path still screened: app middleware runs before routing (404 would be Slim\'s)');

$hooks = [];
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks)));
$suspicious = $app->handle(slimRequest('/search', '203.0.113.10', 'GET', $attackQuery));
$t->same(400, $suspicious->getStatusCode(), 'penetration via query param -> 400 (Slim parsed the query params)');
$t->same('Suspicious activity detected', (string) $suspicious->getBody(), 'suspicious body exact');
$t->same(400, $app->handle(slimRequest('/submit', '203.0.113.21', 'POST', body: 'comment=<script>alert(1)</script>'))->getStatusCode(), 'attack body in POST -> 400 (raw PSR-7 stream scanned)');

$hooks = [];
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, enforceHttps: true, onBlock: hookCapture($hooks)));
$redirect = $app->handle(slimRequest('/order', '203.0.113.45'));
$t->same(301, $redirect->getStatusCode(), 'https enforcement -> 301');
$t->same(['https://ex.test/order'], $redirect->getHeader('location'), 'Location header translated to PSR-7');

$hooks = [];
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false, onBlock: hookCapture($hooks)));
$app->get('/limited', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response->withBody((new StreamFactory())->createStream('limited')));
$limited = slimRequest('/limited', '203.0.113.44');
$t->same(200, $app->handle($limited)->getStatusCode(), 'rate limit hit 1 passes');
$t->same(200, $app->handle($limited)->getStatusCode(), 'rate limit hit 2 passes');
$third = $app->handle($limited);
$t->same(429, $third->getStatusCode(), 'rate limit hit 3 -> 429');
$t->same('Too many requests', (string) $third->getBody(), '429 body exact');
$t->same(['60'], $third->getHeader('retry-after'), 'Retry-After header translated');

$t->section('whitelist and passive mode at app level');
$hooks = [];
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, whitelist: ['203.0.113.50'], onBlock: hookCapture($hooks)));
$app->get('/search', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response->withBody((new StreamFactory())->createStream('searched')));
$allowed = $app->handle(slimRequest('/search', '203.0.113.50', 'GET', $attackQuery));
$t->same(200, $allowed->getStatusCode(), 'whitelisted ip passes despite attack payload');
$other = $app->handle(slimRequest('/search', '203.0.113.99', 'GET', $attackQuery));
$t->same(403, $other->getStatusCode(), 'non-whitelisted ip still screened (whitelist miss -> ip_security 403)');

$hooks = [];
$users = new RouteRecorder();
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, passiveMode: true, onBlock: hookCapture($hooks)));
$app->get('/search', $users->respondWith('searched'));
$passive = $app->handle(slimRequest('/search', '203.0.113.70', 'GET', $attackQuery));
$t->same(200, $passive->getStatusCode(), 'passive mode does not block');
$t->same(1, $users->calls, 'passive mode hands the request to the Slim route');
$t->same([], $hooks, 'passive mode fires no on_block');

$t->section('factory wiring: explicit factories are the ones the composed middleware uses');
$recRf = new RecordingResponseFactory(new ResponseFactory());
$recSf = new RecordingStreamFactory(new StreamFactory());
$app = AppFactory::create();
$guard = new SlimGuard($recRf, $recSf, new GuardEngine(new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'])));
$fluent = $app->add($guard->middleware());
$t->same($app, $fluent, 'App::add() accepts the composed middleware and returns the app (fluent)');
$blocked = $app->handle(slimRequest('/private', '192.0.2.66'));
$t->same(403, $blocked->getStatusCode(), 'explicit-factory stack blocks blacklisted ip');
$t->same('Forbidden', (string) $blocked->getBody(), 'explicit-factory stack 403 body exact');
$t->same(['Forbidden'], $recSf->created, 'block body created by the EXPLICIT stream factory');
$t->same([403], $recRf->createdStatuses, 'block status created by the EXPLICIT response factory');

$t->section('factory wiring: response factory doubling as stream factory');
$dual = new DualFactory();
$app = new App($dual);
$guard = SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'])));
$guard->addTo($app);
$blocked = $app->handle(slimRequest('/private', '192.0.2.66'));
$t->same(403, $blocked->getStatusCode(), 'dual-factory app blocks blacklisted ip');
$t->same([403], $dual->createdStatuses, 'dual factory produced the block response');
$t->same(['Forbidden'], $dual->createdStreams, 'forApp reused the response factory as the stream factory (no slim/psr7 fallback)');

$t->section('route-group attachment screens matched routes only');
$users = new RouteRecorder();
$app = AppFactory::create();
$groupGuard = SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'])));
$group = $app->group('/api', static function (RouteCollectorProxyInterface $api) use ($users): void {
    $api->get('/thing', $users->respondWith('thing'));
});
$groupGuard->addToGroup($group);
$t->same(403, $app->handle(slimRequest('/api/thing', '192.0.2.66'))->getStatusCode(), 'group middleware blocks blacklisted ip on a matched group route');
$t->same(0, $users->calls, 'group route handler never called on block');
$t->same(200, $app->handle(slimRequest('/api/thing', '203.0.113.5'))->getStatusCode(), 'clean ip passes the group guard');
$t->same(1, $users->calls, 'group route handler ran for the clean ip');
$t->throws(
    HttpNotFoundException::class,
    static function () use ($app): void {
        $app->handle(slimRequest('/api/unmatched', '192.0.2.66'));
    },
    'unmatched path under the group is answered by routing, not the guard (group middleware only runs on matched routes)'
);

$t->section('body parsing order with Slim BodyParsingMiddleware');
$clean = new RouteRecorder();
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(enableRedis: false)))->addTo($app);
$app->post('/form', $clean->respondWithParsedBody('form-ok'));
$blockedForm = $app->handle(slimRequest('/form', '203.0.113.20', 'POST', body: 'comment=<script>alert(1)</script>', headers: ['Content-Type' => 'application/x-www-form-urlencoded']));
$t->same(400, $blockedForm->getStatusCode(), 'guard added after body parsing executes FIRST and sees the raw stream: attack blocked');
$parsed = $app->handle(slimRequest('/form', '203.0.113.21', 'POST', body: 'comment=ok', headers: ['Content-Type' => 'application/x-www-form-urlencoded']));
$t->same(200, $parsed->getStatusCode(), 'clean form POST passes (guard then body parsing then route)');
$t->same(['comment' => 'ok'], $clean->parsedBody, 'Slim parsed the body downstream of the guard: getParsedBody works in the route');
$t->same(1, $clean->calls, 'clean form reached the route exactly once');

$missed = new RouteRecorder();
$app = AppFactory::create();
SlimGuard::forApp($app, new GuardEngine(new SecurityConfig(enableRedis: false)))->addTo($app);
$app->addBodyParsingMiddleware();
$app->post('/form', $missed->respondWithParsedBody('form-ok'));
$attackBody = 'comment=<script>alert(1)</script>';
$wrongOrder = $app->handle(slimRequest('/form', '203.0.113.22', 'POST', body: $attackBody, headers: ['Content-Type' => 'application/x-www-form-urlencoded']));
$t->same(200, $wrongOrder->getStatusCode(), 'DOCUMENTED FOOTGUN: body parsing added last executes first and consumes the stream');
$t->same(1, $missed->calls, 'the attack the guard could not see reached the route');
$t->same(['comment' => '<script>alert(1)</script>'], $missed->parsedBody, 'body parsing still produced the parsed body (guard saw an empty stream at EOF)');

$t->section('fail-secure inherited from psr15-guard (not reimplemented)');
$users = new RouteRecorder();
[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false));
$app->get('/x', $users->respondWith('x'));
$malformed = $app->handle(new ThrowingUriRequest(slimRequest('/x', '203.0.113.90')));
$t->same(500, $malformed->getStatusCode(), 'engine malfunction -> fail-closed 500 (psr15-guard catches, Slim never sees the throw)');
$t->same('Security check failed', (string) $malformed->getBody(), 'fail-closed body exact');
$t->same(0, $users->calls, 'route handler not called on malfunction');

[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, customErrorResponses: [500 => 'Security unavailable']));
$custom = $app->handle(new ThrowingUriRequest(slimRequest('/x', '203.0.113.91')));
$t->same('Security unavailable', (string) $custom->getBody(), 'custom_error_responses honored on fail-closed');

[$app, $guard] = makeApp(new SecurityConfig(enableRedis: false, customRequestCheck: static fn (): never => throw new RuntimeException('validator exploded')));
$pipelineFail = $app->handle(slimRequest('/x', '203.0.113.80'));
$t->same(500, $pipelineFail->getStatusCode(), 'check exception -> pipeline fail-secure 500');
$t->same('Security check failed', (string) $pipelineFail->getBody(), 'pipeline fail-secure body exact');

$t->section('redis fail-open and fail-closed construction (inherited)');
$config = new SecurityConfig(enableRedis: true, redisFailOpen: false);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_slimu:', host: '127.0.0.1', port: 1));
$t->throws(
    GuardRedisException::class,
    static function () use ($engine): void {
        $app = AppFactory::create();
        SlimGuard::forApp($app, $engine);
    },
    'redis down + redis_fail_open=false: guard construction fails closed'
);

$config = new SecurityConfig(enableRedis: true, redisFailOpen: true);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_slimu:', host: '127.0.0.1', port: 1));
$app = AppFactory::create();
SlimGuard::forApp($app, $engine)->addTo($app);
$app->get('/x', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response->withBody((new StreamFactory())->createStream('x')));
$openPass = $app->handle(slimRequest('/x', '203.0.113.81'));
$t->same(200, $openPass->getStatusCode(), 'redis down + redis_fail_open=true: construction survives, request passes (bounded fail-open)');

$t->section('integration: shared state over real redis');
$integration = getenv('REDIS_HOST') !== '0';
if ($integration) {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($socket === false) {
        echo "SKIP: integration mode: no redis reachable at {$host}:{$port} ({$errstr}); unit coverage stands\n";
    } else {
        fclose($socket);
        runRedisIntegration($t);
    }
} else {
    echo "\nNOTE: integration mode off (set REDIS_HOST to run the adapter over real redis)\n";
}

function runRedisIntegration(T $t): void
{
    putenv('REDIS_PREFIX=guard_core_slim:' . bin2hex(random_bytes(3)) . ':');
    $redis = RedisHandler::fromEnv();
    $redis->initialize();
    $conn = $redis->connection();
    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }

    $t->section('integration: rate limit shared across SlimGuard instances');
    $configA = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineA = new GuardEngine($configA, $redis);
    $appA = AppFactory::create();
    SlimGuard::forApp($appA, $engineA)->addTo($appA);
    $appA->get('/limited', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response->withBody((new StreamFactory())->createStream('limited')));
    $limited = slimRequest('/limited', '192.0.2.11');
    $t->same(200, $appA->handle($limited)->getStatusCode(), 'engine A hit 1 passes');
    $t->same(200, $appA->handle($limited)->getStatusCode(), 'engine A hit 2 passes');
    $t->same(429, $appA->handle($limited)->getStatusCode(), 'engine A hit 3 -> 429 over redis');

    $configB = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineB = new GuardEngine($configB, $redis);
    $appB = AppFactory::create();
    SlimGuard::forApp($appB, $engineB)->addTo($appB);
    $t->same(429, $appB->handle($limited)->getStatusCode(), 'fresh engine B sees the shared bucket immediately');

    $t->section('integration: ban written by engine A blocks engine B through Slim');
    $t->same(true, $engineA->banManager()->ban('192.0.2.66', 60, 'slim_integration'), 'engine A bans 192.0.2.66');
    $banned = $appB->handle(slimRequest('/private', '192.0.2.66'));
    $t->same(403, $banned->getStatusCode(), 'engine B blocks the banned ip');
    $t->same('IP address banned', (string) $banned->getBody(), 'ban body exact');

    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }
}

$total = $t->passed + $t->failed;
echo "\nPassed: {$t->passed}, Failed: {$t->failed}\n";
echo "{$t->passed}/{$total}" . ($t->failed === 0 ? ' GREEN' : ' RED') . "\n";
exit($t->failed === 0 ? 0 : 1);
