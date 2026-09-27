<?php

namespace App\Filament\Resources\AttendanceRegularisations;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Services\Regularisations;
use App\Filament\Resources\AttendanceRegularisations\Pages\ListAttendanceRegularisations;
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

class AttendanceRegularisationResource extends Resource
{
    protected static ?string $model = AttendanceRegularisation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Regularisations';

    protected static ?int $navigationSort = 60;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AttendanceRegularisation::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'requester', 'reviewer']);
    }

    public static function table(Table $table): Table
    {
        $decide = fn (bool $approve) => function (AttendanceRegularisation $record, array $data) use ($approve) {
            try {
                $approve ? app(Regularisations::class)->approve($record, $data['note'] ?? null) : app(Regularisations::class)->reject($record, $data['note']);
                Notification::make()->success()->title($approve ? 'Approved and reprocessed' : 'Rejected')->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name']),
                TextColumn::make('date')->date('D, d M')->sortable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.regularisation_types.{$state}", $state)),
                TextColumn::make('requested_in')->label('In')->time('H:i')->placeholder('—'),
                TextColumn::make('requested_out')->label('Out')->time('H:i')->placeholder('—'),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('requester.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(AttendanceRegularisation::STATUSES)->default('pending')])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (AttendanceRegularisation $record) => $record->status === 'pending' && auth()->user()->can('approve', $record))
                    ->schema([Textarea::make('note')->maxLength(255)])
                    ->action($decide(true)),
                Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (AttendanceRegularisation $record) => $record->status === 'pending' && auth()->user()->can('approve', $record))
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action($decide(false)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceRegularisations::route('/')];
    }
}
