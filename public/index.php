<?php
declare(strict_types=1);

// Set execution time limit for LDAP operations
set_time_limit(600); // 10 minutes

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;
use HelpdeskForm\Middleware\CorsMiddleware;
use HelpdeskForm\Middleware\AuthMiddleware;
use HelpdeskForm\Middleware\ValidationMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

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

// Add Error Handling Middleware (first)
$errorMiddleware = $app->addErrorMiddleware($_ENV['APP_DEBUG'] === 'true', true, true);

// Add CORS Middleware
$app->add(new CorsMiddleware());

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Validation Middleware
$app->add(new ValidationMiddleware());

// Add Routing Middleware (last - this must be added after other middleware)
$app->addRoutingMiddleware();

// Set up routes
$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

// Run the application
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);
