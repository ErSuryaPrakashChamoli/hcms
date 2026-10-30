<?php

namespace App\Filament\Resources\ImprovementPlans\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PerformanceRegisters;
use Filament\Actions\CreateAction;

class ManageImprovementPlans extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = ImprovementPlanResource::class;

    public static function registerKey(): string
    {
        return 'improvement_plans';
    }

    /** HROne parity GO-1: HROne's PIP and PIP evaluations registers as views of the one improvement plan register. */
    public static function presetViews(): array
    {
        return [
            'evaluations' => ['label' => 'PIP evaluations', 'filters' => ['evaluation' => ['values' => array_keys(ImprovementPlanResource::EVALUATION)]]],
            'evaluation_due' => ['label' => 'Evaluation due', 'filters' => ['evaluation' => ['values' => ['due']]]],
            'pending_approval' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['value' => 'pending_approval']]],
            'pending_outcome' => ['label' => 'Outcome waiting for approval', 'filters' => ['status' => ['value' => 'pending_outcome']]],
            'active' => ['label' => 'Active', 'filters' => ['status' => ['value' => 'active']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['active_pips'];
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity GO-1: views and report for performance HR; the manager's list is as before.
            ...(PerformanceRegisters::hrSide() ? $this->registerToolActions() : []),
            CreateAction::make()->label('Open plan')->using(fn (array $data) => app(ImprovementPlans::class)->open(
                Employee::query()->findOrFail($data['employee_id']), $data['manager_id'] ?? null, $data['reason'], $data['objectives'], $data['start_date'], $data['end_date'], null, auth()->user(),
                reasonCodes: $data['reason_codes'] ?? [],
            ))
                // HP-8f: under a PIP policy that asks for approval the plan waits for it.
                ->successNotificationTitle(fn ($record) => $record?->status === 'pending_approval' ? 'Plan sent for approval' : 'Plan opened'),
            PerformanceRegisters::actionsMenu(),
        ];
    }
}
