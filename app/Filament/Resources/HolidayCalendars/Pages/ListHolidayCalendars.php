<?php

namespace App\Filament\Resources\HolidayCalendars\Pages;

use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Support\HolidayLibraryPicker;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TenantUnique;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class ListHolidayCalendars extends PeopleListRecords
{
    protected static string $resource = HolidayCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('createFromLibrary')->label('Create from holiday library')->icon(Heroicon::OutlinedBookOpen)->color('gray')
                ->visible(fn () => (auth()->user()?->can('create', HolidayCalendar::class) ?? false) && HolidayLibraryPicker::offered())
                ->modalHeading('Create a calendar from the holiday library')
                ->modalDescription('Pick the region and year, then tick the holidays your organisation observes. Afterwards, set who the calendar applies to on its Applies to tab.')
                ->modalWidth('4xl')->modalSubmitActionLabel('Create calendar')
                ->fillForm(fn () => HolidayLibraryPicker::defaults(now()->year))
                ->schema([
                    ...HolidayLibraryPicker::fields(suggestCalendar: true),
                    Grid::make(2)->schema([
                        TextInput::make('name')->label('Calendar name')->required()->maxLength(255),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->unique(HolidayCalendar::class, 'code', modifyRuleUsing: TenantUnique::rule()),
                    ]),
                ])
                ->action(function (array $data) {
                    $calendar = null;
                    HolidayLibraryPicker::run(function () use ($data, &$calendar) {
                        $result = app(HolidayLibrary::class)->createCalendar(['name' => $data['name'], 'code' => $data['code'], 'year' => $data['library_year']], (array) ($data['library_entries'] ?? []), auth()->user());
                        $calendar = $result['calendar'];

                        return $result;
                    });
                    if ($calendar !== null) {
                        $this->redirect(HolidayCalendarResource::getUrl('edit', ['record' => $calendar]));
                    }
                }),
        ];
    }
}
