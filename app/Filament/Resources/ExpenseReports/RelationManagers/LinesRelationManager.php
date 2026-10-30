<?php

namespace App\Filament\Resources\ExpenseReports\RelationManagers;

use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** The expenses in a report, with what the checks noted and what was approved; a draft's lines can be removed. */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    protected static ?string $title = 'Expenses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('category'))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('expense_date')->date(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('merchant')->placeholder('—')->toggleable(),
                TextColumn::make('base_amount')->label('Amount')->numeric(2),
                TextColumn::make('claimable_amount')->label('Claimable')->numeric(2),
                TextColumn::make('approved_amount')->label('Approved')->numeric(2)->placeholder('—'),
                TextColumn::make('checks')->label('Checks')->badge()->color('warning')->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => [...($r->is_exceptional ? ['Exceptional: '.$r->exception_reason] : []), ...array_map(fn (string $f) => (string) config("peopleos.expense.receipt_flags.{$f}", $f), (array) $r->flags)]),
                TextColumn::make('files')->label('Files')->html()->state(fn (ExpenseReceipt $r) => new HtmlString(collect($r->attachments ?? [])->values()
                    ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('expense-receipt', $r, $i)).'">'.e(basename($path)).'</a>')->implode('<br>')))->placeholder('—'),
                TextColumn::make('approver_note')->label('Approver note')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReceipt::STATUSES[$state] ?? $state),
            ])
            ->recordActions([
                Action::make('remove')->label('Remove')->icon('heroicon-m-minus-circle')->color('gray')->requiresConfirmation()
                    ->visible(fn () => $this->ownerRecord instanceof ExpenseReport && $this->ownerRecord->isEditable() && auth()->user()->can('update', $this->ownerRecord))
                    ->action(fn (ExpenseReceipt $record) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->removeReceipt($this->ownerRecord, $record), 'Expense removed from the report')),
            ]);
    }
}
