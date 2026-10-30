<?php

namespace App\Filament\Resources\GoalTemplates\Pages;

use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Services\GoalTemplates;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\GoalTemplates\GoalTemplateResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditGoalTemplate extends PeopleEditRecord
{
    protected static string $resource = GoalTemplateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne: "Add goals" from the library — a KRA and its suggested KPIs.
            Action::make('addFromLibrary')->label('Add from '.PerformanceLabels::get('kra').' library')->icon(Heroicon::OutlinedBookOpen)->color('gray')
                ->visible(fn () => auth()->user()->can('performance.manage'))
                ->schema([Select::make('kra_id')->label(PerformanceLabels::get('kra'))->required()->native(false)->options(fn () => Kra::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())])
                ->action(function (GoalTemplate $record, array $data) {
                    PerformanceActions::run(fn () => app(GoalTemplates::class)->addFromLibrary($record, Kra::query()->findOrFail($data['kra_id']), auth()->user()), 'Added from the library');
                    $this->redirect(GoalTemplateResource::getUrl('edit', ['record' => $record]));
                }),
            DeleteAction::make()->visible(fn (GoalTemplate $record) => ! Goal::query()->where('goal_template_id', $record->id)->exists()),
        ];
    }
}
