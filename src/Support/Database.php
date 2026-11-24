<?php

declare(strict_types=1);

namespace Api\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = getenv('API_DB_PATH') ?: dirname(__DIR__, 2) . '/database/biblioteca.sqlite';
            $isNew = !is_file($path);

            $pdo = new PDO('sqlite:' . $path, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo = $pdo;

            if ($isNew) {
                self::migrate($pdo);
                self::seed($pdo);
            }
        }

        return self::$pdo;
    }

    /**
     * Ejecuta $fn dentro de una transacción de escritura (BEGIN IMMEDIATE),
     * lo que serializa los escritores y evita condiciones de carrera (p. ej. dos préstamos de la última copia).
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
        CREATE TABLE users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT NOT NULL,
            email         TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at    TEXT NOT NULL
        );

        CREATE TABLE api_tokens (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL
        );

        CREATE TABLE books (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            title            TEXT NOT NULL,
            author           TEXT NOT NULL,
            isbn             TEXT UNIQUE,
            year             INTEGER,
            copies_total     INTEGER NOT NULL CHECK (copies_total >= 0),
            copies_available INTEGER NOT NULL CHECK (copies_available >= 0 AND copies_available <= copies_total),
            created_at       TEXT NOT NULL,
            updated_at       TEXT NOT NULL
        );

        CREATE TABLE loans (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            book_id     INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
            loaned_at   TEXT NOT NULL,
            due_at      TEXT NOT NULL,
            returned_at TEXT
        );

        -- Un usuario no puede tener dos préstamos activos del mismo libro (garantizado por la BD).
        CREATE UNIQUE INDEX uq_active_loan ON loans(user_id, book_id) WHERE returned_at IS NULL;
        CREATE INDEX idx_loans_book ON loans(book_id, returned_at);
        CREATE INDEX idx_books_title ON books(title);
        CREATE INDEX idx_books_author ON books(author);
        SQL);
    }

    private static function seed(PDO $pdo): void
    {
        $now = now_iso();

        $pdo->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute(['Usuario Demo', 'demo@example.com', password_hash('demo12345', PASSWORD_DEFAULT), $now]);

        $books = [
            ['Cien años de soledad', 'Gabriel García Márquez', 1967, 3],
            ['El amor en los tiempos del cólera', 'Gabriel García Márquez', 1985, 2],
            ['Rayuela', 'Julio Cortázar', 1963, 2],
            ['Pedro Páramo', 'Juan Rulfo', 1955, 1],
            ['La ciudad y los perros', 'Mario Vargas Llosa', 1963, 2],
            ['Ficciones', 'Jorge Luis Borges', 1944, 4],
        ];

        $insert = $pdo->prepare(
            'INSERT INTO books (title, author, year, copies_total, copies_available, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($books as [$title, $author, $year, $copies]) {
            $insert->execute([$title, $author, $year, $copies, $copies, $now, $now]);
        }
    }
}
