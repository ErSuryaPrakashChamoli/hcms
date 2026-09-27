<?php

namespace App\Filament\Resources\ImprovementPlans\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageImprovementPlans extends ManageRecords
{
    protected static string $resource = ImprovementPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Open plan')->using(fn (array $data) => app(ImprovementPlans::class)->open(
                Employee::query()->findOrFail($data['employee_id']), $data['manager_id'] ?? null, $data['reason'], $data['objectives'], $data['start_date'], $data['end_date'], null, auth()->user(),
            )),
        ];
    }
}
