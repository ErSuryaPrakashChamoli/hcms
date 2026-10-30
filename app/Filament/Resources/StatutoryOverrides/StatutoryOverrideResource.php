<?php

namespace App\Filament\Resources\StatutoryOverrides;

use App\Domain\Compliance\Models\StatutoryOverride;
use App\Domain\Compliance\Services\StatutoryOverrides;
use App\Filament\Resources\StatutoryOverrides\Pages\ListStatutoryOverrides;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne parity PY-14: PF / ESI / PT / LWF / TDS amounts set by hand for one employee and pay month, with the reason. */
class StatutoryOverrideResource extends Resource
{
    protected static ?string $model = StatutoryOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Statutory overrides';

    protected static ?string $modelLabel = 'statutory override';

    protected static ?int $navigationSort = 75;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'period', 'creator']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (StatutoryOverride $r) => $r->employee?->employee_code),
                TextColumn::make('period')->label('Month')->state(fn (StatutoryOverride $r) => $r->period?->label()),
                TextColumn::make('code')->label('Line')->badge()->formatStateUsing(fn (StatutoryOverride $r) => $r->codeLabel()),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('reason')->wrap()->limit(80),
                TextColumn::make('creator.name')->label('Set by')->placeholder('—'),
                TextColumn::make('created_at')->label('Set on')->dateTime(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'warning' : 'gray')
                    ->description(fn (StatutoryOverride $r) => $r->cancel_reason),
            ])
            ->filters([
                SelectFilter::make('code')->label('Line')->options(collect(StatutoryOverride::CODES)->map(fn ($c) => $c[0])->all()),
                SelectFilter::make('status')->options(StatutoryOverride::STATUSES)->default('active'),
                // HROne parity PO-1: the WO-2 employee filters.
                ...PayrollRegisters::filters('payroll.view'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('cancel')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (StatutoryOverride $r) => $r->status === 'active' && auth()->user()->can('update', $r))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (StatutoryOverride $record, array $data) {
                        try {
                            app(StatutoryOverrides::class)->cancel($record, $data['reason'], auth()->user());
                            Notification::make()->success()->title('Override cancelled')->body('Recalculate the run to go back to the calculated amount.')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStatutoryOverrides::route('/')];
    }
}
