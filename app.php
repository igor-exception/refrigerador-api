<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

$app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(false, true, false);
$errorMiddleware->getDefaultErrorHandler()->forceContentType('application/json');

return $app;
