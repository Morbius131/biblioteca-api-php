<?php

declare(strict_types=1);

namespace Api\Http;

final class Request
{
    /** Usuario autenticado (lo asigna el Router en rutas protegidas). */
    public ?array $user = null;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        private readonly string $authorization = '',
    ) {}

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

        $body = [];
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new HttpException(400, 'invalid_json', 'El cuerpo de la petición debe ser un objeto JSON válido.');
            }
            $body = $decoded;
        }

        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        return new self($method, $path, $_GET, $body, is_string($authorization) ? $authorization : '');
    }

    public function bearerToken(): ?string
    {
        return preg_match('/^Bearer\s+(\S+)$/i', $this->authorization, $m) ? $m[1] : null;
    }
}
