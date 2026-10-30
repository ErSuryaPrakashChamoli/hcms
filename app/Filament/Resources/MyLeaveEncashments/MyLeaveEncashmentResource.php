<?php

namespace App\Filament\Resources\MyLeaveEncashments;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Filament\Resources\MyLeaveEncashments\Pages\ListMyLeaveEncashments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity HP-1: an employee's own leave encashments — ask to encash, follow the decision, withdraw a pending
 * one. The HR register (Leave → Encashments) is unchanged; this shows only the signed-in person's rows.
 */
class MyLeaveEncashmentResource extends Resource
{
    protected static ?string $model = LeaveEncashment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My encashments';

    protected static ?string $modelLabel = 'encashment';

    protected static ?string $slug = 'my-encashments';

    protected static ?int $navigationSort = 45;

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('leave.apply') ?? false) && Employee::query()->where('user_id', auth()->id())->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['leaveType', 'reviewer'])
            ->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('leaveType.name')->label('Leave')->badge(),
                TextColumn::make('period_year')->label('Year'),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => LeaveEncashment::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'approved', 'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('created_at')->label('Asked on')->date(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('withdraw')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (LeaveEncashment $record) => $record->status === 'pending')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (LeaveEncashment $record, array $data) {
                        try {
                            app(LeaveAdjustments::class)->cancelEncashment($record, $data['reason']);
                            Notification::make()->success()->title('Encashment withdrawn')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMyLeaveEncashments::route('/')];
    }
}
