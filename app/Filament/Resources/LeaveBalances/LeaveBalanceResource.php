<?php

namespace App\Filament\Resources\LeaveBalances;

use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveYear;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

class LeaveBalanceResource extends Resource
{
    protected static ?string $model = LeaveBalance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Balances';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'leaveType']);

        if (! auth()->user()->can('leave.view')) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (LeaveBalance $record) => $record->employee?->employee_code),
                TextColumn::make('leaveType.name')->label('Type')->badge()->color('info'),
                TextColumn::make('period_year')->label('Year')->sortable(),
                TextColumn::make('opening')->numeric(1),
                TextColumn::make('accrued')->numeric(1),
                TextColumn::make('adjusted')->numeric(1)->toggleable(),
                TextColumn::make('used')->numeric(1),
                TextColumn::make('pending')->numeric(1)->color('warning'),
                TextColumn::make('encashed')->numeric(1)->toggleable(),
                TextColumn::make('lapsed')->numeric(1)->toggleable(),
                TextColumn::make('closing')->numeric(1)->weight('bold'),
                TextColumn::make('available')->state(fn (LeaveBalance $record) => $record->available())->numeric(1)->color('success'),
            ])
            ->filters([
                SelectFilter::make('leave_type_id')->label('Type')->relationship('leaveType', 'name')->preload(),
                SelectFilter::make('period_year')->label('Year')->options(fn () => LeaveBalance::query()->distinct()->orderByDesc('period_year')->pluck('period_year', 'period_year')->all())->default(app(LeaveYear::class)->periodFor(now())),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('adjust')->label('Adjust')->icon('heroicon-m-adjustments-horizontal')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->schema([
                        TextInput::make('days')->numeric()->required()->helperText('Positive credits, negative debits (halves allowed).'),
                        Textarea::make('note')->required()->maxLength(255),
                    ])
                    ->action(function (LeaveBalance $record, array $data) {
                        try {
                            app(LeaveAdjustments::class)->adjust($record->employee, $record->leaveType, (float) $data['days'], $data['note'], $record->period_year);
                            Notification::make()->success()->title('Balance adjusted')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('encash')->label('Encash')->icon('heroicon-m-banknotes')->color('warning')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->visible(fn (LeaveBalance $record) => $record->leaveType?->is_encashable && $record->available() > 0)
                    ->schema([
                        TextInput::make('days')->numeric()->minValue(0.5)->required(),
                        Textarea::make('reason')->maxLength(255),
                    ])
                    ->action(function (LeaveBalance $record, array $data) {
                        try {
                            $encashment = app(LeaveAdjustments::class)->requestEncashment($record->employee, $record->leaveType, (float) $data['days'], $data['reason'] ?? null, $record->period_year);
                            app(LeaveAdjustments::class)->approveEncashment($encashment, 'Approved by HR');
                            Notification::make()->success()->title('Encashment recorded')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveBalances::route('/')];
    }

    /** Unused directly; keeps the type import meaningful for IDEs. */
    public static function types(): array
    {
        return LeaveType::query()->pluck('name', 'id')->all();
    }
}
