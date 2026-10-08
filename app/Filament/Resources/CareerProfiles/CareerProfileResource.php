<?php

namespace App\Filament\Resources\CareerProfiles;

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Organisation\Models\Designation;
use App\Filament\Resources\CareerProfiles\Pages\ManageCareerProfiles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 9 career profiles for HR (career.view within organisation scope). Employees keep their own
 * profile on My Career; managers see shared fields on Team Career. Aspiration history is
 * effective-dated and never overwritten.
 */
class CareerProfileResource extends Resource
{
    protected static ?string $model = CareerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Career profiles';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('career.view') || auth()->user()?->can('career.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'track']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable()->description(fn (CareerProfile $record) => $record->employee?->employee_code),
                TextColumn::make('track.name')->label('Track')->placeholder('—'),
                TextColumn::make('target_designation_ids')->label('Target roles')->state(fn (CareerProfile $record) => Designation::query()->whereIn('id', $record->target_designation_ids ?? [])->pluck('name')->implode(', '))->placeholder('—')->wrap(),
                IconColumn::make('share_aspirations_with_manager')->label('Shares aspirations')->boolean(),
                IconColumn::make('share_mobility_with_manager')->label('Shares mobility')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->recordActions([
                Action::make('history')->label('Aspirations')->icon(Heroicon::OutlinedClock)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (CareerProfile $record) => CareerAspirationEntry::query()->with('targetDesignation')->where('employee_id', $record->employee_id)->orderByDesc('effective_from')->orderByDesc('id')->get()
                        ->map(fn (CareerAspirationEntry $a) => TextEntry::make("a{$a->id}")->label(config("peopleos.career.aspiration_terms.{$a->term}", $a->term).' · '.$a->effective_from?->toDateString().($a->status === 'superseded' ? ' (superseded)' : ''))
                            ->state(trim(($a->targetDesignation?->name ? $a->targetDesignation->name.' — ' : '').(string) $a->aspiration) ?: '—'))->all() ?: [TextEntry::make('none')->hiddenLabel()->state('No aspirations recorded.')]),
            ])
            ->emptyStateHeading('No career profiles yet')->emptyStateDescription('Employees create their profile on My Career.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCareerProfiles::route('/')];
    }
}
