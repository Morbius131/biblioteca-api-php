<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Api\Http\HttpException;
use Api\Http\Request;
use Api\Http\Response;

try {
    $request = Request::capture();

    if ($request->method === 'OPTIONS') {
        $response = Response::noContent(); // Preflight CORS
    } else {
        /** @var \Api\Http\Router $router */
        $router = require __DIR__ . '/../src/routes.php';
        $response = $router->dispatch($request);
    }
} catch (HttpException $e) {
    $response = $e->toResponse();
} catch (Throwable $e) {
    error_log((string) $e);
    $response = Response::json([
        'error' => [
            'code' => 'server_error',
            'message' => API_DEBUG ? $e->getMessage() : 'Error interno del servidor.',
        ],
    ], 500);
}

$response->send();
