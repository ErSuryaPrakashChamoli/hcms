<?php

namespace App\Filament\Resources\ExpenseReports\RelationManagers;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Support\ExpenseReview;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The expenses in a report, with what the checks noted and what was approved; a draft's lines can be removed.
 *
 * HROne parity XO-3 (matrix 26 §26.4.3 #5–#8, #10, #25): sortable number, date, type and distance; a type filter (HROne's
 * Amount / Mileage tabs); "Columns" — up to five more receipt fields (custom fields included) the person keeps for every
 * report; "Open" for the receipt page (with previous / next receipt of the report); "Clear suspicion" for a line an
 * approver held out as suspected (D-XO7).
 */
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
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'part']))
            ->columns([
                TextColumn::make('number')->sortable(),
                TextColumn::make('expense_date')->date()->sortable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('type')->label('Type')->sortable()->toggleable()
                    ->formatStateUsing(fn (?string $state) => (string) config("peopleos.expense.types.{$state}", (string) $state)),
                TextColumn::make('distance_km')->label('Distance (km)')->numeric(2)->sortable()->toggleable()->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => $r->type === ExpenseCategory::MILEAGE ? $r->distance_km : null),
                TextColumn::make('merchant')->placeholder('—')->toggleable(),
                TextColumn::make('base_amount')->label('Amount')->numeric(2),
                TextColumn::make('claimable_amount')->label('Claimable')->numeric(2),
                TextColumn::make('approved_amount')->label('Approved')->numeric(2)->placeholder('—'),
                TextColumn::make('checks')->label('Checks')->badge()->color('warning')->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => [...($r->is_exceptional ? ['Exceptional: '.$r->exception_reason] : []), ...array_map(fn (string $f) => (string) config("peopleos.expense.receipt_flags.{$f}", $f), (array) $r->flags),
                        ...($r->isHeldAsSuspected() ? ['Held as suspected: '.$r->suspicion_note] : [])]),
                TextColumn::make('files')->label('Files')->html()->state(fn (ExpenseReceipt $r) => new HtmlString(collect($r->attachments ?? [])->values()
                    ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('expense-receipt', $r, $i)).'">'.e(basename($path)).'</a>')->implode('<br>')))->placeholder('—'),
                TextColumn::make('approver_note')->label('Approver note')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReceipt::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'suspected' ? 'warning' : null),
                // HROne parity XO-3 (§26.4.3 #7): the fields the person chose with "Columns".
                ...ExpenseReview::pickedColumns(),
            ])
            ->filters([
                // HROne parity XO-3 (§26.4.3 #25): HROne's Amount / Mileage tabs.
                SelectFilter::make('type')->label('Expense type')->options(fn () => (array) config('peopleos.expense.types')),
            ])
            ->headerActions([
                Action::make('reviewColumns')->label('Columns')->icon('heroicon-m-view-columns')->color('gray')
                    ->visible(fn () => ExpenseReportResource::oversees())
                    ->modalHeading('Receipt fields in the table')
                    ->modalDescription('Choose up to '.ExpenseReview::maxColumns().' more fields to show for the expenses of every report you open.')
                    ->fillForm(fn () => ['fields' => ExpenseReview::chosenColumns(auth()->user())])
                    ->schema([
                        CheckboxList::make('fields')->hiddenLabel()->columns(2)->options(fn () => ExpenseReview::columnOptions())
                            ->maxItems(ExpenseReview::maxColumns())->validationMessages(['max' => 'Limit exceeded: choose at most '.ExpenseReview::maxColumns().' fields.']),
                    ])
                    ->action(fn (array $data) => ExpenseReportResource::run(fn () => ExpenseReview::saveColumns(auth()->user(), (array) ($data['fields'] ?? [])), 'Columns saved')),
            ])
            ->recordActions([
                // HROne parity XO-3 (§26.4.3 #8): the receipt page, from which previous / next walk through the report.
                Action::make('open')->label('Open')->icon('heroicon-m-eye')->color('gray')
                    ->visible(fn (ExpenseReceipt $record) => auth()->user()?->can('view', $record) ?? false)
                    ->url(fn (ExpenseReceipt $record) => ExpenseReceiptResource::getUrl('view', ['record' => $record])),
                // HROne parity XO-3 (D-XO7): decide a line held out as suspected — approve up to its claimable amount or reject.
                Action::make('clearSuspicion')->label('Clear suspicion')->icon('heroicon-m-shield-check')->color('warning')
                    ->visible(fn (ExpenseReceipt $record) => $record->isHeldAsSuspected() && ($part = $record->part) !== null && (auth()->user()?->can('approve', $part) ?? false))
                    ->fillForm(fn (ExpenseReceipt $record) => ['amount' => (float) $record->claimable_amount])
                    ->schema(fn (ExpenseReceipt $record) => [
                        Text::make('Held as suspected'.(($by = User::query()->whereKey($record->suspected_by)->value('name')) ? ' by '.$by : '').': '.$record->suspicion_note),
                        TextInput::make('amount')->label('Approve')->numeric()->minValue(0)->maxValue((float) $record->claimable_amount)->required()
                            ->helperText('Up to the claimable '.number_format((float) $record->claimable_amount, 2).'; 0 rejects the expense.'),
                        Textarea::make('note')->label('Note')->required()->maxLength(500),
                    ])
                    ->action(fn (ExpenseReceipt $record, array $data) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->clearSuspicion($record, $data['amount'] ?? null, (string) ($data['note'] ?? '')), 'Suspicion cleared')),
                Action::make('remove')->label('Remove')->icon('heroicon-m-minus-circle')->color('gray')->requiresConfirmation()
                    ->visible(fn () => $this->ownerRecord instanceof ExpenseReport && $this->ownerRecord->isEditable() && auth()->user()->can('update', $this->ownerRecord))
                    ->action(fn (ExpenseReceipt $record) => ExpenseReportResource::run(fn () => app(ExpenseReports::class)->removeReceipt($this->ownerRecord, $record), 'Expense removed from the report')),
            ]);
    }
}
