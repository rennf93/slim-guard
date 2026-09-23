<?php

declare(strict_types=1);

use App\Config;
use App\Routes;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSlim\SlimGuard;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$engine = new GuardEngine(Config::securityConfig());

// Add the guard LAST so it executes FIRST: middleware added later runs
// earlier in Slim, and nothing must read the request body stream before the
// engine's bounded body scan.
SlimGuard::forApp($app, $engine)->addTo($app);

// Error middleware outermost (added after the guard, so it runs innermost of
// the two): turns routing errors (404) into responses instead of exceptions.
$app->addErrorMiddleware(false, true, false);

Routes::register($app, $engine);

$app->run();
