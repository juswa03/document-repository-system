<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

abstract class Controller
{
    /**
     * Page an in-memory collection (used where a list is assembled from
     * more than one query and can't be paged at the database).
     */
    protected function paginateCollection(Collection $items, Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));

        return new Paginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * The `{data, meta}` envelope every paginated endpoint returns, so
     * the frontend reads one shape everywhere.
     */
    protected function paged(LengthAwarePaginator $p, ?callable $map = null): array
    {
        $items = $map ? collect($p->items())->map($map)->all() : $p->items();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
            ],
        ];
    }
}
