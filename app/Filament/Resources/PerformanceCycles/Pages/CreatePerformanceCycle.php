<?php

namespace App\Filament\Resources\PerformanceCycles\Pages;

use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Services\PerformanceTemplates;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreatePerformanceCycle extends PeopleCreateRecord
{
    protected static string $resource = PerformanceCycleResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /** Phase 7: a chosen template version drives the cycle configuration (stages, scale, competencies, weights). */
    protected function applyTemplate(): void
    {
        $cycle = $this->getRecord();
        if ($cycle->status === 'draft' && $cycle->performance_template_version_id) {
            app(PerformanceTemplates::class)->applyTo($cycle, PerformanceTemplateVersion::query()->findOrFail($cycle->performance_template_version_id));
        }
    }

    protected function afterCreate(): void
    {
        $this->applyTemplate();
    }
}
