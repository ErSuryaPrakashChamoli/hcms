<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organisation\Services\OrganisationCodes;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Organisation reference data by code (Phase 1 §42): what integrations need to build positions. */
class OrganisationController extends Controller
{
    public function index(Request $request, string $type): JsonResponse
    {
        $model = OrganisationCodes::TYPES[$type] ?? abort(404);
        $companyCode = $request->query('company_code');
        $hasCompany = in_array('company_id', (new $model)->getFillable(), true);
        $query = $model::query()->orderBy('name')
            ->when($companyCode && $hasCompany, fn ($q) => $q->whereHas('company', fn ($cq) => $cq->where('code', $companyCode)))
            ->when($request->query('updated_since'), fn ($q, $d) => $q->where('updated_at', '>=', $d));

        $perPage = min(max((int) $request->query('per_page', 100), 1), 200);
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($unit) => [
                'id' => $unit->getKey(), 'code' => $unit->getAttribute('code'), 'name' => $unit->getAttribute('name'),
                'status' => $unit->getAttribute('status') instanceof \BackedEnum ? $unit->getAttribute('status')->value : $unit->getAttribute('status'),
                'company_id' => in_array('company_id', $unit->getFillable(), true) ? $unit->getAttribute('company_id') : null,
                'effective_from' => in_array('effective_from', $unit->getFillable(), true) ? $unit->getAttribute('effective_from')?->toDateString() : null,
            ])->values()->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }
}
