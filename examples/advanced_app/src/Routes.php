<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use Slim\App;

/**
 * Route registration. Admin routes drive the engine's ban manager directly;
 * they are gated by the custom_request admin gate in Config.
 */
final readonly class Routes
{
    public static function register(App $app, GuardEngine $engine): void
    {
        $write = static function (Response $response, string $text): Response {
            $response->getBody()->write($text);

            return $response;
        };
        $json = static function (Response $response, mixed $payload, int $status = 200): Response {
            $response->getBody()->write((string) json_encode($payload));

            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        };

        $app->get('/', fn (Request $request, Response $response) => $write($response, 'ok'));
        $app->get('/health', fn (Request $request, Response $response) => $write($response, 'healthy'));
        $app->get('/rate/burst', fn (Request $request, Response $response) => $write($response, 'burst ok'));
        $app->get('/search', function (Request $request, Response $response) use ($write): Response {
            $q = htmlspecialchars((string) ($request->getQueryParams()['q'] ?? ''), ENT_QUOTES);

            return $write($response, 'search: ' . $q);
        });

        $app->get('/admin/ban', function (Request $request, Response $response) use ($engine, $json): Response {
            $ip = (string) ($request->getQueryParams()['ip'] ?? '');
            if ($ip === '') {
                return $json($response, ['error' => 'ip query parameter is required'], 400);
            }
            $duration = (int) ($request->getQueryParams()['duration'] ?? 600);
            $banned = $engine->banManager()->ban($ip, $duration, 'manual');

            return $json($response, ['ip' => $ip, 'duration' => $duration, 'banned' => $banned]);
        });

        $app->get('/admin/unban', function (Request $request, Response $response) use ($engine, $json): Response {
            $ip = (string) ($request->getQueryParams()['ip'] ?? '');
            if ($ip === '') {
                return $json($response, ['error' => 'ip query parameter is required'], 400);
            }
            $engine->banManager()->unban($ip);

            return $json($response, ['ip' => $ip, 'banned' => false]);
        });

        $app->get('/admin/check', function (Request $request, Response $response) use ($engine, $json): Response {
            $ip = (string) ($request->getQueryParams()['ip'] ?? '');
            if ($ip === '') {
                return $json($response, ['error' => 'ip query parameter is required'], 400);
            }

            return $json($response, ['ip' => $ip, 'banned' => $engine->banManager()->isIpBanned($ip)]);
        });
    }
}
