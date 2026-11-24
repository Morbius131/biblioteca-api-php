<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Http\HttpException;
use Api\Http\Request;
use Api\Http\Response;
use Api\Support\Database;
use Api\Support\Paginator;
use Api\Support\Validator;

final class LoanController
{
    private const LOAN_DAYS = 14;

    /** POST /api/books/{id}/loan: presta una copia al usuario autenticado. */
    public function loan(Request $request, array $params): Response
    {
        $bookId = Validator::id($params['id']);
        $userId = (int) $request->user['id'];

        $loanId = Database::transaction(function (\PDO $pdo) use ($bookId, $userId): int {
            $stmt = $pdo->prepare('SELECT copies_available FROM books WHERE id = :id');
            $stmt->execute([':id' => $bookId]);
            $book = $stmt->fetch();

            if (!$book) {
                throw new HttpException(404, 'not_found', 'El libro solicitado no existe.');
            }

            $dup = $pdo->prepare('SELECT 1 FROM loans WHERE user_id = :u AND book_id = :b AND returned_at IS NULL');
            $dup->execute([':u' => $userId, ':b' => $bookId]);
            if ($dup->fetchColumn()) {
                throw new HttpException(409, 'already_loaned', 'Ya tienes un préstamo activo de este libro.');
            }

            if ((int) $book['copies_available'] < 1) {
                throw new HttpException(409, 'no_copies_available', 'No hay copias disponibles de este libro.');
            }

            $pdo->prepare('INSERT INTO loans (user_id, book_id, loaned_at, due_at) VALUES (:u, :b, :l, :d)')
                ->execute([
                    ':u' => $userId,
                    ':b' => $bookId,
                    ':l' => now_iso(),
                    ':d' => now_iso(self::LOAN_DAYS * 86400),
                ]);
            $loanId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE books SET copies_available = copies_available - 1, updated_at = :now WHERE id = :id')
                ->execute([':now' => now_iso(), ':id' => $bookId]);

            return $loanId;
        });

        return Response::json(['data' => $this->findLoan($loanId)], 201);
    }

    /** POST /api/books/{id}/return: devuelve la copia que el usuario tiene prestada. */
    public function giveBack(Request $request, array $params): Response
    {
        $bookId = Validator::id($params['id']);
        $userId = (int) $request->user['id'];

        $loanId = Database::transaction(function (\PDO $pdo) use ($bookId, $userId): int {
            $stmt = $pdo->prepare('SELECT id FROM loans WHERE user_id = :u AND book_id = :b AND returned_at IS NULL');
            $stmt->execute([':u' => $userId, ':b' => $bookId]);
            $loanId = $stmt->fetchColumn();

            if ($loanId === false) {
                throw new HttpException(404, 'loan_not_found', 'No tienes un préstamo activo de este libro.');
            }

            $pdo->prepare('UPDATE loans SET returned_at = :now WHERE id = :id')
                ->execute([':now' => now_iso(), ':id' => $loanId]);
            $pdo->prepare('UPDATE books SET copies_available = copies_available + 1, updated_at = :now WHERE id = :id')
                ->execute([':now' => now_iso(), ':id' => $bookId]);

            return (int) $loanId;
        });

        return Response::json(['data' => $this->findLoan($loanId)]);
    }

    /** GET /api/loans?status=active|returned: préstamos del usuario autenticado. */
    public function index(Request $request, array $params): Response
    {
        [$page, $perPage] = Paginator::params($request->query);
        $userId = (int) $request->user['id'];

        $status = $request->query['status'] ?? null;
        $statusSql = match ($status) {
            null => '',
            'active' => 'AND l.returned_at IS NULL',
            'returned' => 'AND l.returned_at IS NOT NULL',
            default => throw new HttpException(422, 'validation_error', 'Parámetro status inválido.', [
                'status' => ['Valores permitidos: active, returned.'],
            ]),
        };

        $pdo = Database::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM loans l WHERE l.user_id = :u $statusSql");
        $count->execute([':u' => $userId]);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT l.id, l.loaned_at, l.due_at, l.returned_at, b.id AS book_id, b.title, b.author
               FROM loans l
               JOIN books b ON b.id = l.book_id
              WHERE l.user_id = :u $statusSql
           ORDER BY l.loaned_at DESC, l.id DESC
              LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return Response::json([
            'data' => array_map([$this, 'present'], $stmt->fetchAll()),
            'meta' => Paginator::meta($page, $perPage, $total),
        ]);
    }

    /** @return array<string,mixed> */
    private function findLoan(int $id): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.loaned_at, l.due_at, l.returned_at, b.id AS book_id, b.title, b.author
               FROM loans l JOIN books b ON b.id = l.book_id
              WHERE l.id = :id'
        );
        $stmt->execute([':id' => $id]);

        return $this->present($stmt->fetch());
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'book' => ['id' => (int) $row['book_id'], 'title' => $row['title'], 'author' => $row['author']],
            'loaned_at' => $row['loaned_at'],
            'due_at' => $row['due_at'],
            'returned_at' => $row['returned_at'],
            'overdue' => $row['returned_at'] === null && $row['due_at'] < now_iso(),
        ];
    }
}
