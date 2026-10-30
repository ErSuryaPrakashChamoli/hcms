<?php

namespace App\Filament\Resources\EmployeeDevices;

use App\Domain\Attendance\Models\EmployeeDevice;
use App\Domain\Attendance\Services\GeoLocations;
use App\Filament\Resources\EmployeeDevices\Pages\ListEmployeeDevices;
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

/** HROne parity HP-1: phones and browsers employees check in from, under a single-device check-in policy. */
class EmployeeDeviceResource extends Resource
{
    protected static ?string $model = EmployeeDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Check-in devices';

    protected static ?int $navigationSort = 72;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('attendance.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $set = fn (string $status, string $done) => function (EmployeeDevice $record, array $data) use ($status, $done) {
            try {
                app(GeoLocations::class)->reviewDevice($record, $status, $data['reason'] ?? null);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'reviewer']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name']),
                TextColumn::make('device_identifier')->label('Device')->limit(24)->copyable(),
                TextColumn::make('platform')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => EmployeeDevice::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'blocked' => 'danger', default => 'warning'
                    }),
                TextColumn::make('first_seen_at')->dateTime()->toggleable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(EmployeeDevice::STATUSES)])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible(fn (EmployeeDevice $r) => $r->status !== 'approved')
                    ->schema([Textarea::make('reason')->maxLength(255)])->action($set('approved', 'Device approved')),
                Action::make('block')->icon('heroicon-m-no-symbol')->color('danger')->visible(fn (EmployeeDevice $r) => $r->status !== 'blocked')
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])->action($set('blocked', 'Device blocked')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeeDevices::route('/')];
    }
}
