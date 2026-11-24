<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Http\HttpException;
use Api\Http\Request;
use Api\Http\Response;
use Api\Support\Database;
use Api\Support\Paginator;
use Api\Support\Validator;

final class BookController
{
    private const SORTABLE = ['title', 'author', 'year', 'created_at'];

    public function index(Request $request, array $params): Response
    {
        [$page, $perPage] = Paginator::params($request->query);

        $where = [];
        $bind = [];

        $search = $request->query['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $where[] = "(title LIKE :search ESCAPE '\\' OR author LIKE :search ESCAPE '\\')";
            $bind[':search'] = '%' . addcslashes(trim($search), '%_\\') . '%';
        }

        $author = $request->query['author'] ?? null;
        if (is_string($author) && trim($author) !== '') {
            $where[] = "author LIKE :author ESCAPE '\\'";
            $bind[':author'] = '%' . addcslashes(trim($author), '%_\\') . '%';
        }

        if (($request->query['available'] ?? null) === 'true') {
            $where[] = 'copies_available > 0';
        }

        $sort = $request->query['sort'] ?? 'title';
        $direction = 'ASC';
        if (is_string($sort) && str_starts_with($sort, '-')) {
            $direction = 'DESC';
            $sort = substr($sort, 1);
        }
        if (!is_string($sort) || !in_array($sort, self::SORTABLE, true)) {
            throw new HttpException(422, 'validation_error', 'Parámetro sort inválido.', [
                'sort' => ['Valores permitidos: ' . implode(', ', self::SORTABLE) . ' (prefija con "-" para orden descendente).'],
            ]);
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM books $whereSql");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        // $sort y $direction provienen de una lista blanca, por eso es seguro interpolarlos.
        $stmt = $pdo->prepare(
            "SELECT * FROM books $whereSql ORDER BY $sort COLLATE NOCASE $direction, id ASC LIMIT :limit OFFSET :offset"
        );
        foreach ($bind as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return Response::json([
            'data' => $stmt->fetchAll(),
            'meta' => Paginator::meta($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        return Response::json(['data' => $this->findOrFail(Validator::id($params['id']))]);
    }

    public function store(Request $request, array $params): Response
    {
        $data = Validator::validate($request->body, $this->rules());
        $this->assertIsbnAvailable($data['isbn'] ?? null, null);

        $pdo = Database::pdo();
        $now = now_iso();
        $copies = $data['copies_total'] ?? 1;

        $pdo->prepare(
            'INSERT INTO books (title, author, isbn, year, copies_total, copies_available, created_at, updated_at)
             VALUES (:title, :author, :isbn, :year, :total, :available, :created, :updated)'
        )->execute([
            ':title' => $data['title'],
            ':author' => $data['author'],
            ':isbn' => $data['isbn'] ?? null,
            ':year' => $data['year'] ?? null,
            ':total' => $copies,
            ':available' => $copies,
            ':created' => $now,
            ':updated' => $now,
        ]);

        $id = (int) $pdo->lastInsertId();

        return Response::json(['data' => $this->findOrFail($id)], 201, ['Location' => '/api/books/' . $id]);
    }

    /** PUT y PATCH: los campos no enviados conservan su valor actual. */
    public function update(Request $request, array $params): Response
    {
        $id = Validator::id($params['id']);
        $book = $this->findOrFail($id);

        $merged = array_merge([
            'title' => $book['title'],
            'author' => $book['author'],
            'isbn' => $book['isbn'],
            'year' => $book['year'],
            'copies_total' => $book['copies_total'],
        ], $request->body);

        $data = Validator::validate($merged, $this->rules());
        $this->assertIsbnAvailable($data['isbn'] ?? null, $id);

        // Al cambiar el total de copias se ajustan las disponibles; no pueden quedar negativas.
        $newTotal = $data['copies_total'] ?? $book['copies_total'];
        $newAvailable = $book['copies_available'] + ($newTotal - $book['copies_total']);
        if ($newAvailable < 0) {
            throw new HttpException(422, 'validation_error', 'Los datos enviados no son válidos.', [
                'copies_total' => ['No puede ser menor que la cantidad de copias actualmente prestadas.'],
            ]);
        }

        Database::pdo()->prepare(
            'UPDATE books
                SET title = :title, author = :author, isbn = :isbn, year = :year,
                    copies_total = :total, copies_available = :available, updated_at = :updated
              WHERE id = :id'
        )->execute([
            ':title' => $data['title'],
            ':author' => $data['author'],
            ':isbn' => $data['isbn'] ?? null,
            ':year' => $data['year'] ?? null,
            ':total' => $newTotal,
            ':available' => $newAvailable,
            ':updated' => now_iso(),
            ':id' => $id,
        ]);

        return Response::json(['data' => $this->findOrFail($id)]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $id = Validator::id($params['id']);
        $this->findOrFail($id);

        $pdo = Database::pdo();
        $active = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE book_id = :id AND returned_at IS NULL');
        $active->execute([':id' => $id]);
        if ((int) $active->fetchColumn() > 0) {
            throw new HttpException(409, 'book_in_use', 'No se puede eliminar un libro con préstamos activos.');
        }

        $pdo->prepare('DELETE FROM books WHERE id = :id')->execute([':id' => $id]);

        return Response::noContent();
    }

    /** @return array<string,string> */
    private function rules(): array
    {
        return [
            'title' => 'required|string|min:1|max:200',
            'author' => 'required|string|min:1|max:120',
            'isbn' => 'string|isbn',
            'year' => 'integer|min:1000|max:' . ((int) gmdate('Y') + 1),
            'copies_total' => 'integer|min:1|max:1000',
        ];
    }

    private function assertIsbnAvailable(?string $isbn, ?int $exceptId): void
    {
        if ($isbn === null) {
            return;
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM books WHERE isbn = :isbn AND id != :id');
        $stmt->execute([':isbn' => $isbn, ':id' => $exceptId ?? 0]);
        if ($stmt->fetchColumn()) {
            throw new HttpException(409, 'isbn_taken', 'Ya existe un libro con ese ISBN.');
        }
    }

    /** @return array<string,mixed> */
    private function findOrFail(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM books WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: throw new HttpException(404, 'not_found', 'El libro solicitado no existe.');
    }
}
