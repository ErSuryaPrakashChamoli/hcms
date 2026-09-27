<?php

namespace App\Filament\Resources\LeaveEncashments;

use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Filament\Resources\LeaveEncashments\Pages\ListLeaveEncashments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

class LeaveEncashmentResource extends Resource
{
    protected static ?string $model = LeaveEncashment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Encashments';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('leave.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'leaveType', 'reviewer']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name']),
                TextColumn::make('leaveType.name')->label('Type')->badge(),
                TextColumn::make('period_year')->label('Year'),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved', 'paid' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(LeaveEncashment::STATUSES)])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->visible(fn (LeaveEncashment $record) => $record->status === 'pending')
                    ->schema([Textarea::make('note')->maxLength(255)])
                    ->action(function (LeaveEncashment $record, array $data) {
                        try {
                            app(LeaveAdjustments::class)->approveEncashment($record, $data['note'] ?? null);
                            Notification::make()->success()->title('Encashment approved')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->authorize(fn () => auth()->user()->can('leave.manage'))
                    ->visible(fn (LeaveEncashment $record) => $record->status === 'pending')
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action(fn (LeaveEncashment $record, array $data) => app(LeaveAdjustments::class)->rejectEncashment($record, $data['note'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveEncashments::route('/')];
    }
}
