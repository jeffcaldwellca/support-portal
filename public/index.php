<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;
use HelpdeskForm\Middleware\SecurityHeadersMiddleware;
use HelpdeskForm\Middleware\ValidationMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Fail fast on an unconfigured CSRF secret. A missing or placeholder secret
// silently weakens CSRF protection, so refuse to boot instead.
$csrfSecret = $_ENV['CSRF_SECRET'] ?? '';
if ($csrfSecret === '' || $csrfSecret === 'default_secret' || $csrfSecret === 'generate_random_csrf_secret_here') {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("Configuration error: CSRF_SECRET is not set. Generate one with: php -r \"echo bin2hex(random_bytes(32));\"\n");
}

// Create Container
$containerBuilder = new ContainerBuilder();

// Set up dependencies
$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

// Create App
AppFactory::setContainer($container);
$app = AppFactory::create();

// Set base path to empty (we're using built-in server at root)
$app->setBasePath('');

// Middleware is executed in LIFO order (last added runs first / outermost).
// We add it in reverse of the desired inbound flow so the final order is:
//   SecurityHeaders -> ErrorMiddleware -> BodyParsing -> Validation -> Routing
// This guarantees:
//   * Body parsing runs before CSRF validation (so JSON bodies are available).
//   * The error middleware wraps the validation/routing middleware, so their
//     exceptions return a clean error response instead of a fatal stack trace.
//   * Security headers are applied to every response, including error responses.
$app->addRoutingMiddleware();

$app->add(new ValidationMiddleware());

$app->addBodyParsingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(($_ENV['APP_DEBUG'] ?? '') === 'true', true, true);

$app->add(new SecurityHeadersMiddleware());

// Set up routes
$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

// Run the application
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);
