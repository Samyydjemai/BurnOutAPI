<?php
declare(strict_types=1);

use App\Application\Handlers\HttpErrorHandler;
use App\Application\Handlers\ShutdownHandler;
use App\Application\ResponseEmitter\ResponseEmitter;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Kreait\Firebase\Factory;

// Autoload
require __DIR__ . '/../vendor/autoload.php';

// --------------------
// ⚠️ Désactivation temporaire SSL pour Windows local
// --------------------
stream_context_set_default([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
]);

// --------------------
// Firebase Auth
// --------------------
$serviceAccountPath = __DIR__ . '/../config/callofphoenix-firebase-adminsdk.json';
$factory = (new Factory)->withServiceAccount($serviceAccountPath);
$auth = $factory->createAuth();

// --------------------
// PHP-DI Container
// --------------------
$containerBuilder = new ContainerBuilder();
if (false) { // Production cache
    $containerBuilder->enableCompilation(__DIR__ . '/../var/cache');
}

// Settings, dependencies, repositories
$settings = require __DIR__ . '/../app/settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../app/dependencies.php';
$dependencies($containerBuilder);

$repositories = require __DIR__ . '/../app/repositories.php';
$repositories($containerBuilder);

// Build container
$container = $containerBuilder->build();

// Instantiate App
AppFactory::setContainer($container);
$app = AppFactory::create();
$callableResolver = $app->getCallableResolver();

// --------------------
// Middleware
// --------------------
$middleware = require __DIR__ . '/../app/middleware.php';
$middleware($app);

// --------------------
// Routes Firebase
// --------------------
$routes = require __DIR__ . '/../app/routes.php';
// On passe $auth aux routes
$routes($app, $auth);

// --------------------
// Error Handling
// --------------------
/** @var SettingsInterface $settings */
$settings = $container->get(SettingsInterface::class);
$displayErrorDetails = $settings->get('displayErrorDetails');
$logError = $settings->get('logError');
$logErrorDetails = $settings->get('logErrorDetails');

// Request object
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Error Handler
$responseFactory = $app->getResponseFactory();
$errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);

// Shutdown handler
$shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
register_shutdown_function($shutdownHandler);

// Add routing & body parsing middleware
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();

// Error middleware
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logError, $logErrorDetails);
$errorMiddleware->setDefaultErrorHandler($errorHandler);

// Run App
$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);