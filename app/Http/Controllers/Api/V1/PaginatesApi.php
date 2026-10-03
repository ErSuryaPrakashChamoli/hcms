<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The shared list shape of the v1 API (Phase 9; the only copy since Phase 14):
 * - `{data, meta}` pages, `per_page` 1–200 (default 50);
 * - allow-listed sorting `sort=field,-field` (ADR-0014), always with an id tie-breaker for stable pages.
 *   An unknown sort field is a 422, never a silent default.
 */
trait PaginatesApi
{
    private function page(Builder $query, Request $request, callable $map): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    /**
     * @param  array<string, string>  $allowed  public sort name => column
     * @param  string  $default  e.g. '-id'
     */
    private function sorted(Builder $query, Request $request, array $allowed, string $default = '-id'): Builder
    {
        $requested = trim((string) $request->query('sort', ''));
        $fields = $requested === '' ? [$default] : explode(',', $requested);
        $table = $query->getModel()->getTable();
        $query->reorder();
        foreach (array_slice($fields, 0, 3) as $field) {
            $field = trim($field);
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $name = ltrim($field, '-+');
            $column = $name === 'id' ? $table.'.id' : ($allowed[$name] ?? null);
            if ($column === null) {
                throw new HttpException(422, 'Unknown sort field ['.$name.']. Allowed: '.implode(', ', ['id', ...array_keys($allowed)]).'.');
            }
            $query->orderBy($column, $direction);
        }

        return $query->orderBy($table.'.id', 'desc');
    }
}
