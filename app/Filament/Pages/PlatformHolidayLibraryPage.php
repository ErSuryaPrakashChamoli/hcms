<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Enums\HolidayLibraryStatus;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Support\Geography\Countries;
use App\Support\Storage\StagedUpload;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Holiday library: operators upload each country's holidays per region and year, then publish them. Tenants pick
 * the holidays they observe from published lists into their own calendars (Holiday calendars → Add from library).
 */
class PlatformHolidayLibraryPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Holiday library';

    protected static ?string $title = 'Holiday library';

    protected static ?string $slug = 'platform-holiday-library';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.platform-holiday-library';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, HolidayLibraryList> newest year first, the national list before the regions */
    public function lists(): Collection
    {
        return HolidayLibraryList::query()->with(['entries', 'uploader'])
            ->orderByDesc('year')->orderBy('country_code')->orderBy('region_code')->get();
    }

    public function statusColor(HolidayLibraryList $list): string
    {
        return match ($list->status) {
            HolidayLibraryStatus::Published => 'success',
            HolidayLibraryStatus::Draft => 'warning',
            HolidayLibraryStatus::Withdrawn => 'gray',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label('Upload holiday list')->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription('Choose the country and year, then upload the CSV. The format is below; Download template (next to Submit) gives a filled-in file to start from.')
                ->modalWidth('4xl')
                ->modalContent(fn () => static::csvFormat())
                ->extraModalFooterActions([
                    Action::make('downloadTemplate')->label('Download template')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                        ->action(fn (): StreamedResponse => static::templateDownload()),
                ])
                ->schema([
                    Select::make('country_code')->label('Country')->options(fn () => Countries::all())->default('IN')->required()->searchable(),
                    TextInput::make('year')->numeric()->integer()->minValue(2000)->maxValue(2100)->default(now()->year + 1)->required(),
                    TextInput::make('source')->label('Source (e.g. the government notification)')->maxLength(500),
                    FileUpload::make('file')->label('CSV file')->disk(StagedUpload::disk())->directory('holiday-library/uploads')->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])->maxSize(1024)->required(),
                ])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    try {
                        $lists = app(HolidayLibrary::class)->upload((string) $data['country_code'], (int) $data['year'], (string) StagedUpload::storage()->get($data['file']), $data['source'] ?? null, auth()->user());
                    } finally {
                        StagedUpload::storage()->delete($data['file']);
                    }

                    return 'Uploaded as draft: '.$lists->map(fn (HolidayLibraryList $l) => $l->regionLabel())->join(', ').'. Review and publish.';
                })),
        ];
    }

    public function publishAction(): Action
    {
        return Action::make('publish')->label('Publish')->color('success')->size('sm')->requiresConfirmation()
            ->modalDescription('Tenants can then choose holidays from this list for their calendars.')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                app(HolidayLibrary::class)->publish($this->find($arguments), auth()->user());

                return 'Published';
            }));
    }

    public function withdrawAction(): Action
    {
        return Action::make('withdraw')->label('Withdraw')->color('gray')->size('sm')->requiresConfirmation()
            ->modalDescription('Tenants are no longer offered this list. Holidays they already copied stay in their calendars.')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                app(HolidayLibrary::class)->withdraw($this->find($arguments), auth()->user());

                return 'Withdrawn';
            }));
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')->label('Delete')->color('danger')->size('sm')->requiresConfirmation()
            ->modalDescription('The list and its holidays are removed from the library. Holidays tenants already copied stay in their calendars.')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                app(HolidayLibrary::class)->delete($this->find($arguments), auth()->user());

                return 'Deleted';
            }));
    }

    /** The CSV format guide shown in the upload dialog, built from HolidayLibrary so it matches what the upload accepts. */
    public static function csvFormat(): View
    {
        return view('filament.pages.holiday-csv-format', ['format' => HolidayLibrary::FORMAT, 'regions' => HolidayLibrary::REGION_EXAMPLES, 'sample' => HolidayLibrary::templateCsv(now()->year + 1)]);
    }

    private static function templateDownload(): StreamedResponse
    {
        return response()->streamDownload(fn () => print (HolidayLibrary::templateCsv(now()->year + 1)), 'holiday-library-template.csv', ['Content-Type' => 'text/csv']);
    }

    private function find(array $arguments): HolidayLibraryList
    {
        return HolidayLibraryList::query()->findOrFail((int) ($arguments['list'] ?? 0));
    }

    /** @param  Closure(): string  $work */
    private function attempt(Closure $work): void
    {
        try {
            Notification::make()->success()->title($work())->send();
        } catch (RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }
}
