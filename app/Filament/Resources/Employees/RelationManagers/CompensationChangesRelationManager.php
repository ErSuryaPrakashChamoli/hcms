<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Employee 360 → Compensation changes (Phase 11): proposals and their decisions. Shown to full
 * compensation readers and to the people with a duty in the approval chain; never to the employee
 * or to managers through compensation.team. Every action goes through CompensationChanges, which
 * enforces proposer ≠ reviewer ≠ approver ≠ executor.
 */
class CompensationChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'compensationChanges';

    protected static ?string $title = 'Compensation changes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();
        if ($user === null || (int) $ownerRecord->user_id === (int) $user->id) {
            return false;
        }
        $access = app(CompensationAccess::class);
        if ($access->level($user, $ownerRecord) === 'full') {
            return true;
        }

        return collect(['compensation.propose', 'compensation.review', 'compensation.approve', 'compensation.execute'])->contains(fn ($p) => $user->hasPermission($p))
            && app(AccessScopes::class)->allows($user, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['structure', 'proposer', 'reviewer', 'approver', 'executor']))
            ->columns([
                TextColumn::make('reference')->label('Reference')->copyable()->limit(10)->tooltip(fn (CompensationChange $record) => $record->reference),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationChange::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'effective' => 'success', 'scheduled', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning'
                    }),
                TextColumn::make('change_type')->label('Type')->formatStateUsing(fn (string $state) => config("peopleos.compensation.change_types.{$state}", $state)),
                TextColumn::make('effective_from')->label('Effective')->date()->sortable(),
                TextColumn::make('previous_ctc_annual')->label('From CTC')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Proposed CTC')->numeric(2)->description(fn (CompensationChange $record) => $record->currency),
                TextColumn::make('structure.name')->label('Structure')->toggleable(),
                TextColumn::make('reason')->wrap()->limit(80)->toggleable(),
                TextColumn::make('proposer.name')->label('Proposed by'),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(),
                TextColumn::make('executor.name')->label('Executed by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ActionGroup::make([
                    $this->step('submit', 'Submit for review', 'heroicon-m-paper-airplane', fn (CompensationChange $c, User $u) => $c->status === 'draft' && (int) $c->proposed_by === (int) $u->id,
                        fn (CompensationChanges $s, CompensationChange $c, User $u) => $s->submit($c, $u)),
                    $this->step('review', 'Mark reviewed', 'heroicon-m-eye', fn (CompensationChange $c, User $u) => $c->status === 'submitted' && $u->hasPermission('compensation.review'),
                        fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->review($c, $u, $d['note'] ?? null), note: false),
                    $this->step('approve', 'Approve', 'heroicon-m-check', fn (CompensationChange $c, User $u) => $c->status === 'under_review' && $u->hasPermission('compensation.approve'),
                        fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->approve($c, $u, $d['note'] ?? null), note: false),
                    $this->step('reject', 'Reject', 'heroicon-m-x-mark', fn (CompensationChange $c, User $u) => in_array($c->status, CompensationChange::PENDING, true) && ($u->hasPermission('compensation.review') || $u->hasPermission('compensation.approve')),
                        fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->reject($c, $u, (string) $d['note']), note: true),
                    $this->step('return', 'Return to proposer', 'heroicon-m-arrow-uturn-left', fn (CompensationChange $c, User $u) => in_array($c->status, CompensationChange::PENDING, true) && ($u->hasPermission('compensation.review') || $u->hasPermission('compensation.approve')),
                        fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->returnToDraft($c, $u, (string) $d['note']), note: true),
                    $this->step('schedule', 'Execute (schedule)', 'heroicon-m-calendar', fn (CompensationChange $c, User $u) => $c->status === 'approved' && $u->hasPermission('compensation.execute'),
                        fn (CompensationChanges $s, CompensationChange $c, User $u) => $s->schedule($c, $u)),
                    $this->step('cancel', 'Cancel', 'heroicon-m-no-symbol', fn (CompensationChange $c, User $u) => in_array($c->status, ['draft', 'submitted', 'under_review', 'approved', 'scheduled'], true),
                        fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->cancel($c, $u, (string) $d['note']), note: true),
                ]),
            ]);
    }

    private function step(string $name, string $label, string $icon, callable $visible, callable $run, ?bool $note = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)->requiresConfirmation()
            ->visible(fn (CompensationChange $record) => $visible($record, auth()->user()))
            ->schema($note === null ? [] : [Textarea::make('note')->label($note ? 'Reason' : 'Note (optional)')->required($note)->maxLength(2000)])
            ->action(function (CompensationChange $record, array $data) use ($run, $label) {
                try {
                    $run(app(CompensationChanges::class), $record, auth()->user(), $data);
                    Notification::make()->success()->title($label.': done')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not possible')->body($e->getMessage())->persistent()->send();
                }
            });
    }
}
