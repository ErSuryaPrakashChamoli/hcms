<?php

namespace App\Filament\Resources\HolidayCalendars\RelationManagers;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Filament\Support\HolidayLibraryPicker;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidaysRelationManager extends RelationManager
{
    protected static string $relationship = 'holidays';

    /** Memoised per request: Filament evaluates visible() several times a render. */
    private ?bool $libraryOffered = null;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->native(false)->required(),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('type')->options(config('peopleos.attendance.holiday_types'))->default('public')->required(),
            Toggle::make('is_half_day')->label('Half day'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.holiday_types.{$state}", $state)),
                IconColumn::make('is_half_day')->label('Half day')->boolean(),
                TextColumn::make('source')->label('Source')->color('gray')
                    ->state(fn (Holiday $record) => $record->holiday_library_entry_id !== null ? 'Holiday library' : 'Entered here'),
            ])
            ->defaultSort('date')
            ->headerActions([CreateAction::make(), $this->addFromLibraryAction()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /** Copy the holidays the organisation observes from the platform's holiday library into this calendar. */
    private function addFromLibraryAction(): Action
    {
        return Action::make('addFromLibrary')->label('Add from holiday library')->icon(Heroicon::OutlinedBookOpen)->color('gray')
            ->visible(fn () => (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false) && $this->libraryOffered())
            ->modalHeading('Add holidays from the holiday library')
            ->modalDescription('Choose the country, year and region, then tick the holidays your organisation observes. They are copied into this calendar, where you can still edit or remove them.')
            ->modalWidth('4xl')->modalSubmitActionLabel('Add holidays')
            ->fillForm(fn () => HolidayLibraryPicker::defaults($this->getOwnerRecord()->year))
            ->schema(HolidayLibraryPicker::fields())
            ->action(fn (array $data) => HolidayLibraryPicker::run(fn () => app(HolidayLibrary::class)->adopt($this->getOwnerRecord(), (array) ($data['library_entries'] ?? []), auth()->user())));
    }

    private function libraryOffered(): bool
    {
        return $this->libraryOffered ??= HolidayLibraryPicker::offered();
    }
}
