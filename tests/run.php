<?php

declare(strict_types=1);

/**
 * Pruebas de integración de la API (sin dependencias).
 *
 * Uso:
 *   php -S 127.0.0.1:8081 -t public public/index.php   # en otra terminal
 *   php tests/run.php http://127.0.0.1:8081
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8081', '/');
$passed = 0;
$failed = 0;

/** @return array{status:int,body:?array,headers:array} */
function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $rawBody = null): array
{
    global $base;

    $headers = ['Accept: application/json'];
    if ($body !== null || $rawBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $rawBody ?? ($body !== null ? json_encode($body) : ''),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);

    $raw = @file_get_contents($base . $path, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);

    $status = 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }

    return [
        'status' => $status,
        'body' => ($raw !== false && $raw !== '') ? json_decode($raw, true) : null,
        'headers' => $responseHeaders,
    ];
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  \033[32m✔\033[0m $name\n";
    } else {
        $failed++;
        echo "  \033[31m✘\033[0m $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function errorCode(array $r): ?string
{
    return $r['body']['error']['code'] ?? null;
}

$suffix = bin2hex(random_bytes(4));
$password = 'clave-segura-123';

// ---------------------------------------------------------------------------
section('Salud y rutas');
$r = call('GET', '/api/health');
check('GET /api/health → 200', $r['status'] === 200 && ($r['body']['status'] ?? '') === 'ok');

$r = call('GET', '/api/no-existe');
check('Ruta inexistente → 404', $r['status'] === 404 && errorCode($r) === 'not_found');

$r = call('DELETE', '/api/health');
check('Método no permitido → 405', $r['status'] === 405 && errorCode($r) === 'method_not_allowed');

$r = call('POST', '/api/login', null, null, '{json roto');
check('JSON inválido → 400', $r['status'] === 400 && errorCode($r) === 'invalid_json');

// ---------------------------------------------------------------------------
section('Catálogo público');
$r = call('GET', '/api/books');
check('GET /api/books → 200 con data y meta', $r['status'] === 200 && is_array($r['body']['data'] ?? null) && isset($r['body']['meta']['total']));

$r = call('GET', '/api/books?search=borges');
check('Búsqueda por texto filtra resultados', $r['status'] === 200 && count($r['body']['data']) >= 1
    && str_contains(strtolower($r['body']['data'][0]['author']), 'borges'));

$r = call('GET', '/api/books?per_page=2&page=1&sort=-year');
check('Paginación y orden descendente', $r['status'] === 200 && count($r['body']['data']) === 2
    && $r['body']['data'][0]['year'] >= $r['body']['data'][1]['year']
    && $r['body']['meta']['per_page'] === 2);

$r = call('GET', '/api/books?sort=password');
check('sort fuera de la lista blanca → 422', $r['status'] === 422 && errorCode($r) === 'validation_error');

$r = call('GET', '/api/books?per_page=1000');
check('per_page fuera de rango → 422', $r['status'] === 422);

$r = call('GET', '/api/books/abc');
check('Id no numérico → 404', $r['status'] === 404);

$r = call('GET', '/api/books/999999');
check('Libro inexistente → 404', $r['status'] === 404);

// ---------------------------------------------------------------------------
section('Registro y autenticación');
$r = call('POST', '/api/register', ['name' => 'Ana', 'email' => "ana+$suffix@example.com", 'password' => 'corta']);
check('Registro con contraseña corta → 422 con detalle por campo', $r['status'] === 422 && isset($r['body']['error']['details']['password']));

$r = call('POST', '/api/register', ['name' => 'Ana Prueba', 'email' => "ana+$suffix@example.com", 'password' => $password]);
check('Registro válido → 201', $r['status'] === 201 && ($r['body']['data']['email'] ?? '') === "ana+$suffix@example.com");
check('La respuesta de registro no expone la contraseña', !isset($r['body']['data']['password']) && !isset($r['body']['data']['password_hash']));

$r = call('POST', '/api/register', ['name' => 'Ana Prueba', 'email' => strtoupper("ana+$suffix@example.com"), 'password' => $password]);
check('Correo duplicado (sin distinguir mayúsculas) → 409', $r['status'] === 409 && errorCode($r) === 'email_taken');

$r = call('POST', '/api/login', ['email' => "ana+$suffix@example.com", 'password' => 'incorrecta']);
check('Login con contraseña incorrecta → 401', $r['status'] === 401 && errorCode($r) === 'invalid_credentials');

$r = call('POST', '/api/login', ['email' => "ana+$suffix@example.com", 'password' => $password]);
$tokenA = $r['body']['data']['access_token'] ?? '';
check('Login correcto → 200 con token Bearer', $r['status'] === 200 && strlen($tokenA) === 64);

$r = call('POST', '/api/register', ['name' => 'Beto Prueba', 'email' => "beto+$suffix@example.com", 'password' => $password]);
$r = call('POST', '/api/login', ['email' => "beto+$suffix@example.com", 'password' => $password]);
$tokenB = $r['body']['data']['access_token'] ?? '';
check('Segundo usuario autenticado', strlen($tokenB) === 64);

$r = call('GET', '/api/me', null, $tokenA);
check('GET /api/me con token → 200', $r['status'] === 200 && ($r['body']['data']['email'] ?? '') === "ana+$suffix@example.com");

$r = call('GET', '/api/me');
check('GET /api/me sin token → 401', $r['status'] === 401 && errorCode($r) === 'unauthorized');

$r = call('GET', '/api/me', null, 'token-inventado');
check('Token inválido → 401', $r['status'] === 401);

// ---------------------------------------------------------------------------
section('CRUD de libros (protegido)');
$r = call('POST', '/api/books', ['title' => 'X', 'author' => 'Y']);
check('Crear libro sin token → 401', $r['status'] === 401);

$r = call('POST', '/api/books', ['title' => '', 'author' => 'Y', 'isbn' => '123', 'year' => 'dos mil', 'copies_total' => 0], $tokenA);
check('Crear libro inválido → 422 con varios errores', $r['status'] === 422
    && isset($r['body']['error']['details']['title'], $r['body']['error']['details']['isbn'], $r['body']['error']['details']['year'], $r['body']['error']['details']['copies_total']));

$isbn = '978' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
$r = call('POST', '/api/books', ['title' => "Libro de prueba $suffix", 'author' => 'Autora Test', 'isbn' => $isbn, 'year' => 2020, 'copies_total' => 1], $tokenA);
$bookId = $r['body']['data']['id'] ?? 0;
check('Crear libro válido → 201 con Location', $r['status'] === 201 && $bookId > 0
    && ($r['body']['data']['copies_available'] ?? null) === 1
    && (bool) array_filter($r['headers'], fn ($h) => stripos($h, 'Location: /api/books/') === 0));

$r = call('POST', '/api/books', ['title' => 'Otro', 'author' => 'Otra', 'isbn' => $isbn], $tokenA);
check('ISBN duplicado → 409', $r['status'] === 409 && errorCode($r) === 'isbn_taken');

$r = call('GET', "/api/books/$bookId");
check('GET libro creado → 200', $r['status'] === 200 && $r['body']['data']['isbn'] === $isbn);

$r = call('PATCH', "/api/books/$bookId", ['year' => 2021], $tokenA);
check('PATCH parcial conserva el resto de campos', $r['status'] === 200 && $r['body']['data']['year'] === 2021
    && $r['body']['data']['title'] === "Libro de prueba $suffix");

$r = call('PUT', "/api/books/$bookId", ['title' => "Libro editado $suffix", 'author' => 'Autora Test', 'copies_total' => 2], $tokenA);
check('PUT aumenta copias totales y disponibles', $r['status'] === 200
    && $r['body']['data']['copies_total'] === 2 && $r['body']['data']['copies_available'] === 2);

// ---------------------------------------------------------------------------
section('Préstamos');
$r = call('POST', "/api/books/$bookId/loan");
check('Prestar sin token → 401', $r['status'] === 401);

$r = call('POST', "/api/books/$bookId/loan", null, $tokenA);
check('Prestar → 201 con fecha de vencimiento', $r['status'] === 201
    && array_key_exists('returned_at', $r['body']['data'] ?? []) && $r['body']['data']['returned_at'] === null
    && is_string($r['body']['data']['due_at'] ?? null)
    && ($r['body']['data']['overdue'] ?? true) === false
    && ($r['body']['data']['book']['id'] ?? 0) === $bookId);

$r = call('POST', "/api/books/$bookId/loan", null, $tokenA);
check('Prestar dos veces el mismo libro → 409', $r['status'] === 409 && errorCode($r) === 'already_loaned');

$r = call('GET', "/api/books/$bookId");
check('Las copias disponibles bajan a 1', $r['body']['data']['copies_available'] === 1);

$r = call('PUT', "/api/books/$bookId", ['copies_total' => 1], $tokenA);
check('Reducir copias a 1 con 1 prestada → 200', $r['status'] === 200 && $r['body']['data']['copies_available'] === 0);

$r = call('POST', "/api/books/$bookId/loan", null, $tokenB);
check('Sin copias disponibles → 409', $r['status'] === 409 && errorCode($r) === 'no_copies_available');

$r = call('PUT', "/api/books/$bookId", ['copies_total' => 1, 'title' => "Libro editado $suffix", 'author' => 'Autora Test'], $tokenA);
check('PUT sin cambios en copias sigue válido', $r['status'] === 200);

$r = call('GET', '/api/loans?status=active', null, $tokenA);
check('GET /api/loans?status=active lista el préstamo', $r['status'] === 200
    && count($r['body']['data']) === 1 && $r['body']['data'][0]['book']['id'] === $bookId);

$r = call('GET', '/api/loans', null, $tokenB);
check('Cada usuario ve solo sus préstamos', $r['status'] === 200 && count($r['body']['data']) === 0);

$r = call('GET', '/api/loans?status=otro', null, $tokenA);
check('status inválido → 422', $r['status'] === 422);

$r = call('DELETE', "/api/books/$bookId", null, $tokenA);
check('Eliminar libro con préstamo activo → 409', $r['status'] === 409 && errorCode($r) === 'book_in_use');

$r = call('POST', "/api/books/$bookId/return", null, $tokenB);
check('Devolver sin tener préstamo → 404', $r['status'] === 404 && errorCode($r) === 'loan_not_found');

$r = call('POST', "/api/books/$bookId/return", null, $tokenA);
check('Devolver → 200 con returned_at', $r['status'] === 200 && is_string($r['body']['data']['returned_at'] ?? null));

$r = call('POST', "/api/books/$bookId/return", null, $tokenA);
check('Devolver otra vez → 404', $r['status'] === 404);

$r = call('GET', "/api/books/$bookId");
check('Las copias disponibles se restauran', $r['body']['data']['copies_available'] === 1);

$r = call('GET', '/api/loans?status=returned', null, $tokenA);
check('Historial de devueltos', $r['status'] === 200 && count($r['body']['data']) === 1);

// ---------------------------------------------------------------------------
section('Eliminación y cierre de sesión');
$r = call('DELETE', "/api/books/$bookId", null, $tokenA);
check('Eliminar libro sin préstamos activos → 204', $r['status'] === 204);

$r = call('GET', "/api/books/$bookId");
check('El libro eliminado ya no existe → 404', $r['status'] === 404);

$r = call('POST', '/api/logout', null, $tokenA);
check('Logout → 204', $r['status'] === 204);

$r = call('GET', '/api/me', null, $tokenA);
check('El token revocado deja de funcionar → 401', $r['status'] === 401);

// ---------------------------------------------------------------------------
echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "Resultado: $passed correctas, $failed fallidas\033[0m\n";
exit($failed === 0 ? 0 : 1);
