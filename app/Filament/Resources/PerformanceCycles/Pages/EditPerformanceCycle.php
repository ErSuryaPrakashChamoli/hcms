<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Services\PerformanceTemplates;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\PerformanceActions;
use Filament\Resources\Pages\EditRecord;

class EditPerformanceCycle extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = PerformanceCycleResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceActions::forCycle();
    }

    /** Phase 7: a chosen template version drives the cycle configuration (stages, scale, competencies, weights). */
    protected function applyTemplate(): void
    {
        $cycle = $this->getRecord();
        if ($cycle->status === 'draft' && $cycle->performance_template_version_id) {
            app(PerformanceTemplates::class)->applyTo($cycle, PerformanceTemplateVersion::query()->findOrFail($cycle->performance_template_version_id));
        }
    }

    protected function afterSave(): void
    {
        $this->applyTemplate();
    }
}
