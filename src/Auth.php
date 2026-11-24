<?php

declare(strict_types=1);

namespace Api;

use Api\Http\HttpException;
use Api\Http\Request;
use Api\Support\Database;

/**
 * Autenticación por token Bearer. El token en claro solo se entrega una vez;
 * en la base de datos se guarda únicamente su hash SHA-256.
 */
final class Auth
{
    public const TOKEN_TTL = 86400; // 24 horas

    /** @return array{token:string,expires_at:string} */
    public static function issueToken(int $userId): array
    {
        $pdo = Database::pdo();

        $pdo->prepare('DELETE FROM api_tokens WHERE expires_at < :now')->execute([':now' => now_iso()]);

        $token = bin2hex(random_bytes(32));
        $expiresAt = now_iso(self::TOKEN_TTL);

        $pdo->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, expires_at, created_at) VALUES (:u, :h, :e, :c)'
        )->execute([':u' => $userId, ':h' => hash('sha256', $token), ':e' => $expiresAt, ':c' => now_iso()]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** @return array{id:int,name:string,email:string} */
    public static function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw self::unauthorized('Falta el encabezado Authorization: Bearer <token>.');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.name, u.email, t.expires_at
               FROM api_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = :hash'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();

        if (!$row || $row['expires_at'] < now_iso()) {
            throw self::unauthorized('El token es inválido o ha expirado.');
        }

        return ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']];
    }

    public static function revoke(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            Database::pdo()
                ->prepare('DELETE FROM api_tokens WHERE token_hash = :hash')
                ->execute([':hash' => hash('sha256', $token)]);
        }
    }

    private static function unauthorized(string $message): HttpException
    {
        return new HttpException(401, 'unauthorized', $message, headers: ['WWW-Authenticate' => 'Bearer']);
    }
}
