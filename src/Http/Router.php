<?php

declare(strict_types=1);

namespace Api\Http;

use Api\Auth;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},auth:bool}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, array $handler, bool $auth = false): void
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }

            if ($route['auth']) {
                $request->user = Auth::authenticate($request);
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $route['handler'];

            return (new $class())->$action($request, $params);
        }

        if ($allowed) {
            throw new HttpException(
                405,
                'method_not_allowed',
                'Método no permitido para esta ruta.',
                headers: ['Allow' => implode(', ', array_unique($allowed))],
            );
        }

        throw new HttpException(404, 'not_found', 'La ruta solicitada no existe.');
    }
}
