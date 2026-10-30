<?php

namespace App\Filament\Resources\ExpenseReports\RelationManagers;

use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Services\ExpenseReports;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The approvals of a submitted report: one per workflow group (and one for exceptional expenses). Approvers in scope
 * approve line by line (each line up to its claimable amount; 0 rejects the line), reject, or return the report.
 */
class PartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    protected static ?string $title = 'Approvals';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        $decidable = fn (ExpenseReportPart $p) => $p->status === 'pending' && auth()->user()->can('approve', $p);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereNotIn('status', ['returned', 'cancelled'])->with('decider'))
            ->columns([
                TextColumn::make('label')->label('Approval for'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => $state === 'exceptional' ? 'Exceptional' : 'Standard')->color(fn (string $state) => $state === 'exceptional' ? 'warning' : 'gray'),
                TextColumn::make('total_claimed')->label('Claimed')->numeric(2),
                TextColumn::make('total_approved')->label('Approved')->numeric(2)->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReportPart::STATUSES[$state] ?? $state),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('decision_note')->label('Note')->placeholder('—')->wrap(),
            ])
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible($decidable)
                    ->fillForm(fn (ExpenseReportPart $record) => ['lines' => $record->receipts()->with('category')->get()->map(fn (ExpenseReceipt $r) => [
                        'id' => $r->id, 'line' => $r->number.' · '.$r->expense_date->format('d M').' · '.($r->category?->name ?? ''), 'claimable' => number_format((float) $r->claimable_amount, 2),
                        'amount' => (float) $r->claimable_amount, 'note' => null,
                    ])->all()])
                    ->schema([
                        Repeater::make('lines')->label('Approve each expense (0 rejects it)')->addable(false)->deletable(false)->reorderable(false)->columns(4)->schema([
                            Hidden::make('id'),
                            TextInput::make('line')->label('Expense')->disabled()->dehydrated(false)->columnSpan(2),
                            TextInput::make('claimable')->label('Claimable')->disabled()->dehydrated(false),
                            TextInput::make('amount')->label('Approve')->numeric()->minValue(0)->required(),
                            TextInput::make('note')->label('Note for this expense')->maxLength(500)->columnSpanFull(),
                        ]),
                        Textarea::make('note')->label('Note')->maxLength(500),
                    ])
                    ->action(fn (ExpenseReportPart $record, array $data) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->approvePart($record,
                        collect($data['lines'] ?? [])->mapWithKeys(fn (array $l) => [(int) $l['id'] => ['amount' => $l['amount'] ?? null, 'note' => $l['note'] ?? null]])->all(), $data['note'] ?? null), 'Decision recorded')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible($decidable)
                    ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (ExpenseReportPart $record, array $data) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->rejectPart($record, $data['note']), 'Expenses rejected')),
                Action::make('return')->label('Return to employee')->icon('heroicon-m-arrow-uturn-left')->color('gray')->visible($decidable)
                    ->schema([Textarea::make('note')->label('What should the employee change?')->required()->maxLength(500)])
                    ->action(fn (ExpenseReportPart $record, array $data) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->returnReport($record, $data['note']), 'Report returned to the employee')),
            ]);
    }
}
