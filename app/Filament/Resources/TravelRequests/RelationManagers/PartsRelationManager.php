<?php

namespace App\Filament\Resources\TravelRequests\RelationManagers;

use App\Domain\Expense\Models\TravelRequestLeg;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Owner decision 2026-10-29 (EX-F): the approvals of a trip spanning several workflow groups — one part per group with
 * its legs, its status and who decided it. A part no workflow is running for is approved or rejected here (in scope,
 * never one's own); the trip is approved when every part is, rejected when any part is.
 */
class PartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    protected static ?string $title = 'Approvals by workflow group';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->hasParts() && (auth()->user()?->can('view', $ownerRecord) ?? false);
    }

    public function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (TravelRequestPart $record, array $data = []) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };
        $decidable = fn (TravelRequestPart $r) => $r->isPending() && $this->getOwnerRecord()->status === 'submitted' && auth()->user()->can('approve', $r)
            && WorkflowInstance::query()->where('subject_type', $r->getMorphClass())->where('subject_id', $r->id)->whereIn('status', InstanceStatus::inFlight())->doesntExist();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['legs', 'decider']))
            ->columns([
                TextColumn::make('label')->label('Workflow group'),
                TextColumn::make('legs_summary')->label('Legs')->state(fn (TravelRequestPart $p) => $p->legs->map(fn (TravelRequestLeg $l) => $l->summary())->implode('; '))->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => TravelRequestPart::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('decided_at')->label('Decided on')->dateTime()->placeholder('—'),
                TextColumn::make('decision_note')->label('Note')->limit(60)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('approvePart')->label('Approve')->icon('heroicon-m-check')->color('success')->visible($decidable)
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action($run(fn ($r, $d) => app(TravelRequests::class)->approvePart($r, $d['note'] ?? null), 'Approved for this workflow group')),
                Action::make('rejectPart')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')->visible($decidable)
                    ->modalDescription('Rejecting one workflow group rejects the whole trip.')
                    ->schema([Textarea::make('note')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => app(TravelRequests::class)->rejectPart($r, $d['note']), 'Travel rejected')),
            ]);
    }
}
