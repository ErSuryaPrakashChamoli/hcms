<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Services\Bgv;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /api/v1/bgv/cases/{reference}/checks — vendor results (§22). */
class BgvCallbackController extends Controller
{
    public function store(Request $request, string $reference, Bgv $bgv): JsonResponse
    {
        $data = $request->validate([
            'checks' => ['required', 'array', 'min:1'],
            'checks.*.type' => ['required', Rule::in(array_keys(config('peopleos.bgv.check_types')))],
            'checks.*.status' => ['required', Rule::in(array_keys(BgvCheck::STATUSES))],
            'checks.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = BgvCase::query()->where('external_reference', $reference)->orWhere('id', ctype_digit($reference) ? (int) $reference : 0)->firstOrFail();

        foreach ($data['checks'] as $result) {
            $check = $case->checks()->firstOrCreate(['type' => $result['type']]);
            $bgv->recordCheck($check, $result['status'], $result['notes'] ?? null);
        }

        $case->refresh();

        return response()->json(['data' => [
            'case_id' => $case->id,
            'status' => $case->status,
            'overall_result' => $case->overall_result,
            'checks' => $case->checks()->get()->map(fn (BgvCheck $c) => ['type' => $c->type, 'status' => $c->status])->all(),
        ]]);
    }
}
