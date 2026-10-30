<?php

namespace App\Filament\Resources\AttendanceDevices;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\AttendanceDevices\Pages\ManageAttendanceDevices;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
            // HROne setup parity (TO-8): HROne's biometric server — a sync agent on the client's server reads the attendance
            // database and pushes punches here. Its credentials stay with the agent; PeopleOS keeps what to read and how often.
            Section::make('Sync server')->columns(2)->columnSpanFull()->collapsible()->schema([
                Radio::make('settings.sync_mode')->label('How punches arrive')->options(['push' => 'The device pushes them', 'server' => 'A sync agent reads the attendance server'])->default('push')->inline()->live()->columnSpanFull(),
                Select::make('settings.server_type')->label('Server type')->options(['mssql' => 'Microsoft SQL Server', 'mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'oracle' => 'Oracle', 'access' => 'MS Access', 'csv' => 'File export'])
                    ->visible(fn (Get $get) => $get('settings.sync_mode') === 'server'),
                TextInput::make('settings.server_name')->label('Server name')->maxLength(120)->visible(fn (Get $get) => $get('settings.sync_mode') === 'server'),
                TextInput::make('settings.table_name')->label('Attendance table or view')->maxLength(120)->visible(fn (Get $get) => $get('settings.sync_mode') === 'server'),
                TextInput::make('settings.connection_reference')->label('Connection reference (the name the agent knows; no passwords)')->maxLength(120)->visible(fn (Get $get) => $get('settings.sync_mode') === 'server'),
                TextInput::make('settings.frequency_hours')->label('Sync every (hours)')->numeric()->minValue(1)->maxValue(24)->visible(fn (Get $get) => $get('settings.sync_mode') === 'server'),
                KeyValue::make('settings.column_map')->label('Column mapping (the server column → PeopleOS field)')->keyLabel('Server column')->valueLabel('employee_code, punched_at, direction or device_id')
                    ->columnSpanFull()->visible(fn (Get $get) => $get('settings.sync_mode') === 'server'),
            ]),
            Section::make('Alert when nothing arrives')->columns(2)->columnSpanFull()->collapsible()->schema([
                TextInput::make('settings.alert_after_hours')->label('Alert after (hours without punches; blank = never)')->numeric()->minValue(1)->maxValue(720),
                TagsInput::make('settings.alert_emails')->label('Send the alert to')->placeholder('it@example.com'),
                CheckboxList::make('settings.alert_skip_days')->label('Days without alerts')->options(['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'])->columns(7)->columnSpanFull(),
            ]),
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
