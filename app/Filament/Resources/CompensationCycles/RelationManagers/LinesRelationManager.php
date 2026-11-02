<?php

namespace App\Filament\Resources\CompensationCycles\RelationManagers;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationCycleProposal;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\IncrementPlanSharing;
use App\Filament\Support\CompensationActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Phase 11: a cycle's lines — one compensation change per employee, adjustable only while the cycle is a draft.
 * HROne parity PO-17: the CTC each line was calculated from, and the managers' proposals (shared plans), which the
 * preparer accepts (the line is adjusted) or declines while the plan is a draft.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Employees and proposed compensation';

    /** @var Collection<int, CompensationCycleProposal>|null the latest proposal per line (per request) */
    private ?Collection $proposals = null;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee')->description(fn (CompensationChange $record) => $record->employee?->person?->full_name)->searchable(),
                TextColumn::make('previous_ctc_annual')->label('Current CTC')->numeric(2)->placeholder('—')
                    // HROne parity PO-17: before submission, the CTC the line was calculated from.
                    ->state(fn (CompensationChange $record) => $record->previous_ctc_annual ?? $record->base_ctc_annual),
                TextColumn::make('increase_percent')->label('%')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Proposed CTC')->numeric(2)->description(fn (CompensationChange $record) => $record->currency),
                TextColumn::make('performance_label')->label('Finalized rating')->placeholder('—')->toggleable(),
                TextColumn::make('manager_proposal')->label('Manager\'s proposal')->numeric(2)->placeholder('—')
                    ->state(fn (CompensationChange $record) => $this->proposalFor($record)?->ctc_annual)
                    ->description(fn (CompensationChange $record) => ($p = $this->proposalFor($record))
                        ? ($p->proposer?->name ?? '').' · '.(CompensationCycleProposal::STATUSES[$p->status] ?? $p->status).($p->note ? ' · '.$p->note : '')
                        : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationChange::STATUSES[$state] ?? $state),
            ])
            ->defaultSort('id')
            ->recordActions([
                Action::make('adjust')->label('Adjust')->icon('heroicon-m-pencil-square')
                    ->visible(fn (CompensationChange $record) => $record->status === 'draft' && (int) $record->proposed_by === (int) auth()->id())
                    ->fillForm(fn (CompensationChange $record) => ['ctc_annual' => $record->ctc_annual])
                    ->schema([TextInput::make('ctc_annual')->label('Proposed annual CTC')->numeric()->minValue(1)->required()])
                    ->action(fn (CompensationChange $record, array $data) => CompensationActions::run(fn () => app(CompensationChanges::class)->update($record, ['ctc_annual' => $data['ctc_annual']], auth()->user()), 'Line adjusted')),
                Action::make('acceptProposal')->label('Accept proposal')->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (CompensationChange $record) => 'The line becomes '.number_format((float) $this->proposalFor($record)?->ctc_annual, 2).' a year, still a draft that is reviewed and approved by others.')
                    ->visible(fn (CompensationChange $record) => $this->decidable($record) && (int) $this->getOwnerRecord()->prepared_by === (int) auth()->id())
                    ->action(function (CompensationChange $record) {
                        $proposal = $this->proposalFor($record);
                        $this->proposals = null;
                        CompensationActions::run(fn () => app(IncrementPlanSharing::class)->accept($proposal, auth()->user()), 'Proposal accepted; the line is adjusted');
                    }),
                Action::make('declineProposal')->label('Decline proposal')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (CompensationChange $record) => $this->decidable($record))
                    ->schema([Textarea::make('note')->label('Note for the manager')->required()->maxLength(2000)])
                    ->action(function (CompensationChange $record, array $data) {
                        $proposal = $this->proposalFor($record);
                        $this->proposals = null;
                        CompensationActions::run(fn () => app(IncrementPlanSharing::class)->decline($proposal, (string) $data['note'], auth()->user()), 'Proposal declined');
                    }),
            ]);
    }

    private function proposalFor(CompensationChange $line): ?CompensationCycleProposal
    {
        $cycle = $this->getOwnerRecord();
        $this->proposals ??= $cycle instanceof CompensationCycle ? app(IncrementPlanSharing::class)->latestProposals($cycle) : collect();

        return $this->proposals->get((int) $line->id);
    }

    private function decidable(CompensationChange $line): bool
    {
        $cycle = $this->getOwnerRecord();

        return $cycle instanceof CompensationCycle && $cycle->status === 'draft' && $line->status === 'draft'
            && $this->proposalFor($line)?->status === 'proposed' && (bool) auth()->user()?->hasPermission('compensation.cycles');
    }
}
