<?php

namespace App\Filament\Resources\AttendanceDevices;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\AttendanceDevices\Pages\ManageAttendanceDevices;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Biometric Integration Hub (§24): devices push to /api/v1/attendance/devices/{code}/punches with an attendance.write key. */
class AttendanceDeviceResource extends Resource
{
    protected static ?string $model = AttendanceDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Devices';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Used in the push URL.'),
            Select::make('adapter')->options(collect(config('peopleos.attendance.adapters'))->map(fn ($a) => $a['label'])->all())->default('generic')->required(),
            Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('adapter')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.attendance.adapters.{$state}.label", $state)),
                TextColumn::make('location.name')->label('Location')->placeholder('—'),
                TextColumn::make('last_seen_at')->dateTime()->placeholder('Never')->since(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (AttendanceDevice $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAttendanceDevices::route('/')];
    }
}
