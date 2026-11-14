<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

abstract class Controller
{
    public function FormatPage(LengthAwarePaginator $data, iterable $items): array
    {
        return [
            'page'      => $data->currentPage(),
            'page_size' => $data->perPage(),
            'total'     => $data->total(),
            'items'     => $items,
        ];
    }

    protected function pageSize(Request $request, int $default = 24): int
    {
        $data = $request->validate([
            'page'      => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'between:1,60'],
        ]);

        return $data['page_size'] ?? $default;
    }
}
