<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Services\PerformanceTemplates;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\PerformanceActions;
use Illuminate\Support\Arr;

class EditPerformanceCycle extends PeopleEditRecord
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

    /**
     * QA audit 2026-11-12: a scheduled or launched cycle keeps its configuration, so its frozen fields are shown but never
     * written back (the form returned them, normalised — e.g. empty options as [] for null — and every save failed).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->getRecord()->isEditable() ? $data : Arr::except($data, PerformanceCycle::FROZEN);
    }

    protected function afterSave(): void
    {
        $this->applyTemplate();
    }
}
