<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Services\PositionOccupancy;
use App\Domain\Workforce\Services\WorkforceSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 10 positions API (`/api/v1/positions/*`, scope positions.read). Positions are addressed by
 * code; occupants appear by employee code only (no names, pay or personal data); everything is read
 * as of ?on=YYYY-MM-DD (default today) from effective-dated records. Codes from another tenant
 * resolve to 404. Read-only: positions change only through the domain actions in PeopleOS.
 */
class PositionController extends Controller
{
    use PaginatesApi;

    public function index(Request $request): JsonResponse
    {
        $query = Position::query()->with(['company:id,code', 'currentVersion.designation:id,code', 'currentVersion.parent:id,code'])
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderBy('code');

        return $this->page($query, $request, fn (Position $p) => $this->row($p, $p->currentVersion));
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $position = Position::query()->with('company:id,code')->where('code', strtoupper($code))->firstOrFail();
        $on = $this->on($request);

        return response()->json(['data' => [
            ...$this->row($position, $position->versionOn($on)),
            'as_of' => $on,
            'versions' => PositionVersion::query()->with(['designation:id,code', 'parent:id,code'])->where('position_id', $position->id)->orderBy('version')->get()
                ->map(fn (PositionVersion $v) => [
                    'version' => $v->version, 'status' => $v->status, 'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
                    'in_force_at_least_one_day' => $v->isInForce(), 'title' => $v->title, 'designation_code' => $v->designation?->code, 'parent_code' => $v->parent?->code,
                    'headcount' => $v->headcount, 'fte' => (float) $v->fte, 'fte_capacity' => (float) $v->fte_capacity, 'change_type' => $v->change_type,
                ])->all(),
        ]]);
    }

    public function occupancy(Request $request, string $code, PositionOccupancy $occupancy): JsonResponse
    {
        $position = Position::query()->where('code', strtoupper($code))->firstOrFail();
        $data = $occupancy->occupancy($position, $this->on($request));
        $data['occupants'] = array_map(fn ($o) => ['employee_code' => $o['employee_code'], 'fte' => $o['fte'], 'since' => $o['since']], $data['occupants']);

        return response()->json(['data' => ['code' => $position->code, 'as_of' => $this->on($request), ...$data]]);
    }

    public function vacancies(Request $request, WorkforceSnapshot $snapshot): JsonResponse
    {
        $on = $this->on($request);

        return $this->page($snapshot->vacancies($on)->with(['position:id,code', 'designation:id,code'])->orderBy('position_versions.id'), $request, fn (PositionVersion $v) => [
            'code' => $v->position?->code, 'title' => $v->title, 'designation_code' => $v->designation?->code, 'as_of' => $on,
            'seats' => $v->headcount, 'occupied_seats' => (int) $v->occupied_seats, 'vacant_seats' => max(0, $v->headcount - (int) $v->occupied_seats),
            'fte_capacity' => (float) $v->fte_capacity, 'vacant_fte' => max(0.0, round((float) $v->fte_capacity - (float) $v->occupied_fte, 2)),
        ]);
    }

    private function row(Position $position, ?PositionVersion $version): array
    {
        return [
            'code' => $position->code, 'title' => $version?->title ?? $position->title, 'status' => $version?->status ?? $position->status, 'latest_status' => $position->status,
            'company_code' => $position->company?->code, 'designation_code' => $version?->designation?->code, 'parent_code' => $version?->parent?->code,
            'occupancy_mode' => $version?->occupancy_mode, 'headcount' => $version?->headcount, 'fte' => $version ? (float) $version->fte : null, 'fte_capacity' => $version ? (float) $version->fte_capacity : null,
            'worker_type' => $version?->worker_type, 'first_effective_from' => $position->first_effective_from?->toDateString(),
        ];
    }

    private function on(Request $request): string
    {
        return Carbon::parse($request->query('on') ?: now())->toDateString();
    }
}
