<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Auth;
use Api\Http\HttpException;
use Api\Http\Request;
use Api\Http\Response;
use Api\Support\Database;
use Api\Support\Validator;

final class AuthController
{
    public function register(Request $request, array $params): Response
    {
        $data = Validator::validate($request->body, [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:8|max:72',
        ]);
        $email = strtolower($data['email']);

        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = :email');
        $exists->execute([':email' => $email]);
        if ($exists->fetchColumn()) {
            throw new HttpException(409, 'email_taken', 'Ya existe una cuenta con ese correo.');
        }

        $pdo->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (:n, :e, :p, :c)')
            ->execute([
                ':n' => $data['name'],
                ':e' => $email,
                ':p' => password_hash($data['password'], PASSWORD_DEFAULT),
                ':c' => now_iso(),
            ]);

        return Response::json(
            ['data' => ['id' => (int) $pdo->lastInsertId(), 'name' => $data['name'], 'email' => $email]],
            201
        );
    }

    public function login(Request $request, array $params): Response
    {
        $data = Validator::validate($request->body, [
            'email' => 'required|string|max:190',
            'password' => 'required|string|max:72',
        ]);

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute([':email' => strtolower($data['email'])]);
        $user = $stmt->fetch();

        // Se verifica siempre contra un hash para no filtrar por tiempo si el correo existe.
        $hash = $user['password_hash'] ?? password_hash('no-existe', PASSWORD_DEFAULT);
        $valid = password_verify($data['password'], $hash);

        if (!$user || !$valid) {
            throw new HttpException(401, 'invalid_credentials', 'Correo o contraseña incorrectos.');
        }

        $token = Auth::issueToken((int) $user['id']);

        return Response::json(['data' => [
            'token_type' => 'Bearer',
            'access_token' => $token['token'],
            'expires_at' => $token['expires_at'],
        ]]);
    }

    public function me(Request $request, array $params): Response
    {
        return Response::json(['data' => $request->user]);
    }

    public function logout(Request $request, array $params): Response
    {
        Auth::revoke($request);

        return Response::noContent();
    }
}
