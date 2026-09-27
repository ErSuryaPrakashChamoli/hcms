<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Employment\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit employee representation for /api/v1 (Phase 1 §61): never $model->toArray(). Sensitive
 * blocks (bank, statutory) appear only when the caller holds the sensitive scope and asked for them.
 *
 * @mixin Employee
 */
class EmployeeApiResource extends JsonResource
{
    public static bool $includeSensitive = false;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Employee $e */
        $e = $this->resource;
        $position = $e->currentPosition;
        $manager = $e->currentManager?->manager;

        $data = [
            'id' => $e->id,
            'employee_code' => $e->employee_code,
            'external_reference' => $e->external_reference,
            'source' => $e->source,
            'person' => [
                'id' => $e->person?->id,
                'first_name' => $e->person?->first_name, 'middle_name' => $e->person?->middle_name, 'last_name' => $e->person?->last_name,
                'preferred_name' => $e->person?->preferred_name, 'display_name' => $e->person?->display_name, 'gender' => $e->person?->gender,
            ],
            'name' => $e->person?->full_name,
            'work_email' => $e->work_email,
            'work_phone' => $e->work_phone,
            'lifecycle_state' => $e->lifecycle_state->value,
            'joining_date' => $e->joining_date?->toDateString(),
            'probation_end_date' => $e->probation_end_date?->toDateString(),
            'confirmation_date' => $e->confirmation_date?->toDateString(),
            'exit_date' => $e->exit_date?->toDateString(),
            'position' => $position ? [
                'effective_from' => $position->effective_from?->toDateString(),
                'company' => self::unit($position->company), 'location' => self::unit($position->location),
                'business_unit' => self::unit($position->businessUnit), 'division' => self::unit($position->division),
                'department' => self::unit($position->department), 'team' => self::unit($position->team),
                'designation' => self::unit($position->designation), 'level' => self::unit($position->level), 'grade' => self::unit($position->grade),
                'employment_type' => self::unit($position->employmentType), 'employee_category' => self::unit($position->employeeCategory), 'work_mode' => self::unit($position->workMode),
            ] : null,
            'manager' => $manager ? ['id' => $manager->id, 'employee_code' => $manager->employee_code, 'name' => $manager->person?->full_name] : null,
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];

        if (self::$includeSensitive) {
            $data['sensitive'] = [
                'personal_email' => $e->person?->personal_email,
                'personal_phone' => $e->person?->personal_phone,
                'date_of_birth' => $e->person?->date_of_birth?->toDateString(),
                'statutory' => $e->statutoryDetail ? ['pan' => $e->statutoryDetail->pan, 'uan' => $e->statutoryDetail->uan, 'esic_number' => $e->statutoryDetail->esic_number, 'pf_number' => $e->statutoryDetail->pf_number, 'tax_regime' => $e->statutoryDetail->tax_regime] : null,
                'bank_accounts' => $e->bankAccounts->map(fn ($b) => ['bank_name' => $b->bank_name, 'account_number' => $b->account_number, 'ifsc' => $b->ifsc, 'is_primary' => (bool) $b->is_primary])->values()->all(),
            ];
        }

        return $data;
    }

    /** @return array{id: int, code: ?string, name: ?string}|null */
    private static function unit(?object $unit): ?array
    {
        return $unit ? ['id' => $unit->getKey(), 'code' => $unit->getAttribute('code'), 'name' => $unit->getAttribute('name')] : null;
    }
}
