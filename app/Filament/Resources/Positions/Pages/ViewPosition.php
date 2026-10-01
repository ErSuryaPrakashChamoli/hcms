<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionChangeRequest;
use App\Domain\Workforce\Services\Positions;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Support\WorkforceActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** Phase 10: one position — lifecycle moves, effective-dated changes and draft edits through the Positions service. */
class ViewPosition extends ViewRecord
{
    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        $moves = collect(config('peopleos.workforce.position_statuses'))->except(['draft'])->map(fn ($label, $status) => Action::make("move_{$status}")
            ->label(match ($status) {
                'proposed' => 'Propose', 'approved' => 'Approve', 'planned' => 'Mark planned', 'open' => $this->record->status === 'frozen' ? 'Unfreeze' : 'Open', 'frozen' => 'Freeze', 'on_hold' => 'Put on hold', 'abolished' => 'Abolish', 'closed' => 'Close', default => $label
            })
            ->visible(fn (Position $record) => in_array($status, config("peopleos.workforce.position_transitions.{$record->status}", []), true)
                && auth()->user()->can($status === 'approved' ? 'workforce.approve' : 'workforce.manage'))
            ->color(in_array($status, ['abolished', 'closed', 'frozen'], true) ? 'danger' : 'primary')
            ->schema([WorkforceActions::effectiveFrom()->default(null)->helperText('Leave empty for today (never before the latest version).'), Textarea::make('reason')->rows(2)->required(in_array($status, ['frozen', 'on_hold', 'abolished', 'closed'], true) || $this->record->status === 'frozen')])
            ->requiresConfirmation(in_array($status, ['abolished', 'closed'], true))
            ->action(fn (Position $record, array $data) => WorkforceActions::run(fn () => app(Positions::class)->transition($record, $status, $data['reason'] ?? null, auth()->user(), $data['effective_from'] ?? null, $record->lock_version), 'Position is now '.strtolower($label))))->values()->all();

        return [
            ActionGroup::make($moves)->label('Lifecycle')->icon(Heroicon::OutlinedArrowPath)->button(),
            Action::make('editDraft')->label('Edit draft')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->visible(fn (Position $record) => $record->status === 'draft' && auth()->user()->can('update', $record))
                ->fillForm(fn (Position $record) => collect($record->currentVersion?->getAttributes() ?? [])->only(app(Positions::class)->editable())->all() + ['effective_from' => $record->first_effective_from?->toDateString()])
                ->schema([WorkforceActions::effectiveFrom(), ...WorkforceActions::definitionFields(false)])
                ->action(fn (Position $record, array $data) => WorkforceActions::run(fn () => app(Positions::class)->updateDraft($record, $data, auth()->user()), 'Draft saved')),
            Action::make('change')->label('Change from a date')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn (Position $record) => ! in_array($record->status, ['draft', 'abolished', 'closed'], true) && auth()->user()->can('update', $record))
                ->modalDescription('Only fill what changes. Changes configured for approval become a change request for a second person.')
                ->schema([WorkforceActions::effectiveFrom()->required(), ...WorkforceActions::definitionFields(false), Textarea::make('reason')->required()->rows(2)])
                ->action(fn (Position $record, array $data) => WorkforceActions::run(fn () => app(Positions::class)->change($record, WorkforceActions::filled(collect($data)->except(['effective_from', 'reason'])->all()), $data['effective_from'], $data['reason'], auth()->user()),
                    fn ($result) => $result instanceof PositionChangeRequest ? 'Change request sent for approval' : 'New version recorded')),
        ];
    }
}
