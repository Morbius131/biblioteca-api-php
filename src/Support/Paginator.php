<?php

declare(strict_types=1);

namespace Api\Support;

use Api\Http\HttpException;

final class Paginator
{
    /** @return array{0:int,1:int} [page, perPage] */
    public static function params(array $query, int $defaultPerPage = 10, int $maxPerPage = 100): array
    {
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var(
            $query['per_page'] ?? $defaultPerPage,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => $maxPerPage]]
        );

        if ($page === false || $perPage === false) {
            throw new HttpException(422, 'validation_error', 'Parámetros de paginación inválidos.', [
                'pagination' => ["page debe ser >= 1 y per_page debe estar entre 1 y $maxPerPage."],
            ]);
        }

        return [$page, $perPage];
    }

    /** @return array{page:int,per_page:int,total:int,total_pages:int} */
    public static function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
