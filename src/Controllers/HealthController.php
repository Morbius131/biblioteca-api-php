<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Http\Request;
use Api\Http\Response;
use Api\Support\Database;

final class HealthController
{
    public function check(Request $request, array $params): Response
    {
        Database::pdo()->query('SELECT 1');

        return Response::json(['status' => 'ok', 'time' => now_iso()]);
    }
}
