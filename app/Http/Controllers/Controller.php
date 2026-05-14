<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Http\Request;

abstract class Controller
{
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    protected function resolvePerPage(Request $request, int $default = 10): int
    {
        $perPage = (int) $request->query('perPage', $default);
        if ($perPage < 1 || $perPage > 100) {
            return $default;
        }

        return $perPage;
    }
}
