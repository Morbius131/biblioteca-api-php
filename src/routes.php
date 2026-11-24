<?php

declare(strict_types=1);

use Api\Controllers\AuthController;
use Api\Controllers\BookController;
use Api\Controllers\HealthController;
use Api\Controllers\LoanController;
use Api\Http\Router;

$router = new Router();

// Públicas
$router->add('GET', '/api/health', [HealthController::class, 'check']);
$router->add('POST', '/api/register', [AuthController::class, 'register']);
$router->add('POST', '/api/login', [AuthController::class, 'login']);
$router->add('GET', '/api/books', [BookController::class, 'index']);
$router->add('GET', '/api/books/{id}', [BookController::class, 'show']);

// Requieren token Bearer
$router->add('GET', '/api/me', [AuthController::class, 'me'], auth: true);
$router->add('POST', '/api/logout', [AuthController::class, 'logout'], auth: true);

$router->add('POST', '/api/books', [BookController::class, 'store'], auth: true);
$router->add('PUT', '/api/books/{id}', [BookController::class, 'update'], auth: true);
$router->add('PATCH', '/api/books/{id}', [BookController::class, 'update'], auth: true);
$router->add('DELETE', '/api/books/{id}', [BookController::class, 'destroy'], auth: true);

$router->add('POST', '/api/books/{id}/loan', [LoanController::class, 'loan'], auth: true);
$router->add('POST', '/api/books/{id}/return', [LoanController::class, 'giveBack'], auth: true);
$router->add('GET', '/api/loans', [LoanController::class, 'index'], auth: true);

return $router;
