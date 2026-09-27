<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\ComplianceReadiness;
use App\Domain\Compliance\Services\Returns\ReturnGenerators;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Organisation\Models\Establishment;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only compliance API (Phase 5 Part S), scope `compliance.read`, inside the key's tenant.
 * Registration numbers and statutory identifiers (UAN, IP number, PAN) are always masked; nothing
 * here exposes bank credentials, payroll secrets, tax or portal credentials, or tokens. Reading a
 * return's entries is recorded as STATUTORY_OUTPUT_ACCESSED.
 */
class ComplianceController extends Controller
{
    public function establishments(Request $request): JsonResponse
    {
        $query = Establishment::query()->with('legalEntity')
            ->when($request->query('state'), fn (Builder $q, $s) => $q->where('state', strtoupper($s)))
            ->orderBy('id');

        return $this->page($query, $request, fn (Establishment $e) => [
            'id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'state' => $e->state, 'country' => $e->country, 'type' => $e->establishment_type,
            'status' => $e->status?->value, 'effective_from' => $e->effective_from?->toDateString(), 'effective_to' => $e->effective_to?->toDateString(),
            'legal_entity' => ['id' => $e->legal_entity_id, 'code' => $e->legalEntity?->code, 'legal_name' => $e->legalEntity?->legal_name],
        ]);
    }

    public function registrations(Request $request): JsonResponse
    {
        $query = StatutoryRegistration::query()
            ->when($request->query('establishment_id'), fn (Builder $q, $id) => $q->where('establishment_id', (int) $id))
            ->when($request->query('type'), fn (Builder $q, $t) => $q->where('registration_type', $t))
            ->orderBy('id');

        return $this->page($query, $request, fn (StatutoryRegistration $r) => [
            'id' => $r->id, 'legal_entity_id' => $r->legal_entity_id, 'establishment_id' => $r->establishment_id,
            'authority' => $r->statutory_authority, 'type' => $r->registration_type, 'number' => $r->maskedNumber(),
            'jurisdiction' => $r->jurisdiction, 'state' => $r->state_code, 'status' => $r->status, 'verification_status' => $r->verification_status,
            'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
        ]);
    }

    public function rules(Request $request): JsonResponse
    {
        $query = ComplianceRule::query()
            ->when($request->query('code'), fn (Builder $q, $c) => $q->where('code', strtoupper($c)))
            ->when($request->query('state'), fn (Builder $q, $s) => $q->where('state', strtoupper($s)))
            ->when($request->query('verification_status'), fn (Builder $q, $s) => $q->where('verification_status', $s))
            ->orderBy('code')->orderBy('state')->orderBy('version');

        return $this->page($query, $request, fn (ComplianceRule $r) => $this->ruleData($r));
    }

    public function ruleShow(int $rule): JsonResponse
    {
        $r = ComplianceRule::query()->findOrFail($rule);

        return response()->json(['data' => $this->ruleData($r) + [
            'payload' => $r->payload(),
            'verification_notes' => $r->verification_notes,
            'verification_history' => $r->verifications()->get()->map(fn ($v) => ['action' => $v->action, 'from' => $v->from_status, 'to' => $v->to_status, 'source_url' => $v->source_url, 'at' => $v->created_at?->toIso8601String()])->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function ruleData(ComplianceRule $rule): array
    {
        return [
            'id' => $rule->id, 'code' => $rule->code, 'jurisdiction' => $rule->jurisdiction, 'state' => $rule->state, 'authority' => $rule->authority,
            'name' => $rule->name, 'version' => $rule->version, 'effective_from' => $rule->effective_from?->toDateString(), 'effective_to' => $rule->effective_to?->toDateString(),
            'verification_status' => $rule->verification_status, 'verified_at' => $rule->verified_at?->toIso8601String(),
            'source_url' => $rule->source_url, 'source_title' => $rule->source_title, 'checksum' => $rule->checksum,
        ];
    }

    public function returns(Request $request): JsonResponse
    {
        $query = StatutoryReturn::query()
            ->when($request->query('type'), fn (Builder $q, $t) => $q->where('return_type', strtoupper($t)))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->when($request->query('period'), fn (Builder $q, $p) => $q->where('period_key', $p))
            ->when($request->query('establishment_id'), fn (Builder $q, $id) => $q->where('establishment_id', (int) $id))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (StatutoryReturn $r) => $this->summary($r));
    }

    public function show(int $return): JsonResponse
    {
        $r = StatutoryReturn::query()->findOrFail($return);

        return response()->json(['data' => $this->summary($r) + [
            'validation' => $r->validation,
            'rule_versions' => $r->rule_versions,
            'readiness' => app(ComplianceReadiness::class)->forReturn($r),
            'actions' => $r->actions()->get()->map(fn ($a) => ['action' => $a->action, 'from' => $a->from_status, 'to' => $a->to_status, 'source' => $a->source, 'at' => $a->created_at?->toIso8601String()])->all(),
        ]]);
    }

    public function entries(Request $request, int $return): JsonResponse
    {
        $r = StatutoryReturn::query()->findOrFail($return);
        $generator = app(ReturnGenerators::class)->for($r->return_type);
        app(StatutoryReturns::class)->recordAccess($r, null, 'api', 'entries via key '.$request->attributes->get('api_key')?->name);

        return $this->page($generator->entries($r), $request, fn ($entry) => $generator->present($entry, false));
    }

    public function reconciliation(int $return): JsonResponse
    {
        $r = StatutoryReturn::query()->findOrFail($return);

        return response()->json(['data' => [
            'return_id' => $r->id,
            'status' => $r->reconciliation_status,
            'reconciliations' => $r->reconciliations()->get()->map(fn ($x) => ['stage' => $x->stage, 'status' => $x->status, 'blocking' => $x->blocking_count, 'checks' => $x->checks, 'at' => $x->performed_at?->toIso8601String()])->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function summary(StatutoryReturn $r): array
    {
        return [
            'id' => $r->id, 'type' => $r->return_type, 'form' => $r->form_code, 'legacy_form' => $r->legacy_form_code, 'kind' => $r->return_kind, 'sequence' => $r->sequence,
            'period' => $r->period_key, 'legal_entity_id' => $r->legal_entity_id, 'establishment_id' => $r->establishment_id, 'state' => $r->state_code,
            'status' => $r->status, 'blocking' => $r->blocking_count, 'warnings' => $r->warning_count, 'reconciliation_status' => $r->reconciliation_status,
            'totals' => $r->totals, 'format' => ['code' => $r->format_code, 'version' => $r->format_version, 'verification_status' => $r->format_verification_status],
            'approved_at' => $r->approved_at?->toIso8601String(), 'exported_at' => $r->exported_at?->toIso8601String(),
            'submitted_at' => $r->submitted_at?->toIso8601String(), 'external_reference' => $r->external_reference, 'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
        ];
    }

    private function page(Builder $query, Request $request, callable $map): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }
}
