<?php

namespace App\Filament\Resources\AlumniProfiles;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Alumni\Models\AlumniRequest;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\AlumniProfiles\Pages\ListAlumniProfiles;
use App\Filament\Resources\AlumniProfiles\Pages\ViewAlumniProfile;
use App\Filament\Resources\AlumniProfiles\RelationManagers\RequestsRelationManager;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Alumni directory and request handling (§62). */
class AlumniProfileResource extends Resource
{
    protected static ?string $model = AlumniProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Alumni';

    protected static ?string $navigationLabel = 'Alumni';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('alumni.view') || auth()->user()?->can('alumni.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person'])->withCount(['requests as open_requests_count' => fn ($q) => $q->whereIn('status', AlumniRequest::OPEN)]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('personal_email')->email()->maxLength(255),
            TextInput::make('phone')->maxLength(32),
            Toggle::make('is_rehire_eligible')->label('Eligible for rehire'),
            Toggle::make('portal_enabled')->label('Portal enabled'),
            Toggle::make('consent_to_contact')->label('Consented to contact'),
            TextInput::make('notes')->maxLength(1000)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (AlumniProfile $record) => $record->employee->person?->full_name)->columns(4)->schema([
                TextEntry::make('employee.employee_code')->label('Code'),
                TextEntry::make('last_designation')->placeholder('—'),
                TextEntry::make('last_department')->placeholder('—'),
                TextEntry::make('exit_type')->badge()->color('gray')->formatStateUsing(fn (?string $state) => config("peopleos.exit.types.{$state}", $state)),
                TextEntry::make('joined_on')->date()->placeholder('—'),
                TextEntry::make('exited_on')->date(),
                TextEntry::make('personal_email')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('is_rehire_eligible')->label('Rehire')->formatStateUsing(fn ($state) => $state ? 'Eligible' : 'Not eligible'),
                TextEntry::make('portal_enabled')->label('Portal')->formatStateUsing(fn ($state) => $state ? 'Enabled' : 'Disabled'),
                TextEntry::make('consent_to_contact')->label('Contact')->formatStateUsing(fn ($state) => $state ? 'Consented' : 'No'),
                TextEntry::make('notes')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name')->searchable(['first_name', 'last_name']),
                TextColumn::make('last_designation')->placeholder('—'),
                TextColumn::make('exited_on')->date()->sortable(),
                TextColumn::make('exit_type')->badge()->color('gray')->formatStateUsing(fn (?string $state) => config("peopleos.exit.types.{$state}", $state)),
                IconColumn::make('is_rehire_eligible')->label('Rehire')->boolean(),
                IconColumn::make('portal_enabled')->label('Portal')->boolean(),
                TextColumn::make('open_requests_count')->label('Open requests')->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
            ])
            ->defaultSort('exited_on', 'desc')
            ->filters([SelectFilter::make('exit_type')->options(config('peopleos.exit.types'))])
            ->recordActions([ViewAction::make(), EditAction::make()->visible(fn () => auth()->user()->can('alumni.manage'))]);
    }

    public static function getRelations(): array
    {
        return [RequestsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlumniProfiles::route('/'),
            'view' => ViewAlumniProfile::route('/{record}'),
        ];
    }
}
