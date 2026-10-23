<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\HolidayLibraryStatus;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Geography\Countries;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Holiday library: the platform uploads holiday lists per country, region and year; a tenant picks the holidays its
 * organisation observes and copies them into its own calendar. A copy is the tenant's from then on: later library
 * uploads never change a tenant calendar.
 */
final class HolidayLibrary
{
    /** CSV header: date and name are required, the rest optional. A blank region is the national list. */
    public const COLUMNS = ['date', 'name', 'type', 'half_day', 'region_code'];

    /** Accepted and ignored: files made with the first template named regions; names now come from the region list. */
    private const IGNORED_COLUMNS = ['region_name'];

    /** The upload guide, column by column (same order as COLUMNS): column => [required, what to enter, example]. */
    public const FORMAT = [
        'date' => [true, 'The holiday\'s date, inside the year you upload for: YYYY-MM-DD (or YYYY/MM/DD), or with the month as an English word, day first or month first. Never 01/05/2027: it could be January or May.', '2027-01-26, 26 Jan 2027 or Jan 26, 2027'],
        'name' => [true, 'The holiday as employees will see it (up to 255 characters), in any language.', 'Republic Day'],
        'type' => [false, 'public (also national, statutory, gazetted, bank or federal) or optional (also restricted or floating). Empty means public.', 'public'],
        'half_day' => [false, 'yes for a half-day holiday. Empty means no.', 'no'],
        'region_code' => [false, 'Empty for a national holiday. Otherwise a code from the country\'s region list (Platform → Regions), with or without the country prefix (MH or IN-MH). Its name comes from that list. Each code becomes its own list.', 'MH'],
    ];

    /** Region codes in other countries, for the upload guide (ISO 3166-2). */
    public const REGION_EXAMPLES = ['US-CA' => 'California, United States', 'GB-SCT' => 'Scotland, United Kingdom', 'AU-NSW' => 'New South Wales, Australia',
        'CA-ON' => 'Ontario, Canada', 'AE-DU' => 'Dubai, United Arab Emirates', 'DE-BY' => 'Bayern, Germany'];

    /** Library holidays are public or optional; company holidays are each tenant's own. */
    public const TYPES = ['public', 'optional'];

    /** The words countries use for each type: type => accepted values. */
    private const TYPE_ALIASES = [
        'public' => ['public', 'national', 'statutory', 'gazetted', 'bank', 'federal'],
        'optional' => ['optional', 'restricted', 'floating'],
    ];

    private const MAX_ROWS = 2000;

    private const MAX_ERRORS_SHOWN = 10;

    /** Unambiguous date formats only (year first, or the month as a word): 01/05/2027 could be January or May. */
    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d', 'j M Y', 'j-M-Y', 'j F Y', 'M j, Y', 'F j, Y', 'M j Y', 'F j Y'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CountryRegions $regions,
    ) {}

    /**
     * Upload one country's holidays for one year. Rows group by region into lists; each list's holidays are replaced
     * by the file's (dates kept keep their entry, so tenant copies keep their link) and the list goes back to draft
     * until an operator publishes it. All or nothing: one invalid row refuses the whole file.
     *
     * @return Collection<int, HolidayLibraryList>
     */
    public function upload(string $country, int $year, string $csv, ?string $source, ?User $actor): Collection
    {
        PlatformOperator::assert($actor, 'upload holiday lists');
        $country = strtoupper(trim($country));
        if (! Countries::exists($country)) {
            throw new InvalidArgumentException("'{$country}' is not a country: use its ISO 3166-1 two-letter code (e.g. IN, US, GB, AE).");
        }
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('The year must be between 2000 and 2100.');
        }
        $regions = $this->parse($csv, $year, $country);
        $source = filled($source) ? trim($source) : null;

        return DB::transaction(function () use ($country, $year, $regions, $source, $actor) {
            $lists = collect();
            foreach ($regions as $code => $region) {
                $list = HolidayLibraryList::query()->firstOrNew(['country_code' => $country, 'region_code' => $code, 'year' => $year]);
                $previous = $list->exists ? $list->status->value : null;
                $list->fill(['region_name' => $region['name'], 'status' => HolidayLibraryStatus::Draft, 'source' => $source,
                    'uploaded_by' => $actor?->id, 'uploaded_at' => now(), 'published_by' => null, 'published_at' => null])->save();

                $existing = $list->entries()->get()->keyBy(fn (HolidayLibraryEntry $e) => $e->date->toDateString());
                foreach ($region['rows'] as $date => $row) {
                    $entry = $existing->pull($date) ?? new HolidayLibraryEntry(['holiday_library_list_id' => $list->id, 'date' => $date]);
                    $entry->fill($row)->save();
                }
                HolidayLibraryEntry::query()->whereKey($existing->modelKeys())->delete();

                $this->record(AuditAction::Import, $list, $actor, ['holidays' => count($region['rows']), 'removed' => $existing->count(), 'previous_status' => $previous, 'source' => $source]);
                $lists->push($list);
            }

            return $lists;
        });
    }

    /**
     * Validate a holiday CSV: region code => the region's name and its rows by date.
     *
     * @return array<string, array{name: ?string, rows: array<string, array{name: string, type: string, is_half_day: bool}>}>
     */
    public function parse(string $csv, int $year, string $country): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $csv)));
        $header = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim((string) $h))), str_getcsv((string) array_shift($lines)));
        $unknown = array_diff(array_filter($header, fn ($h) => $h !== ''), self::COLUMNS, self::IGNORED_COLUMNS);
        if ($unknown !== [] || ! in_array('date', $header, true) || ! in_array('name', $header, true)) {
            throw new InvalidArgumentException('The first row must be the header: '.implode(',', self::COLUMNS).' (date and name required).'
                .($unknown !== [] ? ' Unknown column: '.implode(', ', $unknown).'.' : ''));
        }

        $country = strtoupper($country);
        $known = $this->regions->active($country);
        $regions = [];
        $errors = [];
        $count = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $rowNumber = $index + 2;
            if (++$count > self::MAX_ROWS) {
                throw new InvalidArgumentException('The file has more than '.self::MAX_ROWS.' holidays; split it by region.');
            }
            $cells = array_map(fn ($c) => trim((string) $c), str_getcsv($line));
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));

            $date = $this->date($row['date']);
            $type = $this->type($row['type'] ?? '');
            $halfDay = $this->flag($row['half_day'] ?? '');
            $region = in_array($row['region_code'] ?? '', ['', HolidayLibraryList::NATIONAL], true) ? HolidayLibraryList::NATIONAL : CountryRegions::code($row['region_code'], $country);
            $problems = array_filter([
                $date === null ? "date '{$row['date']}' is not a date: use YYYY-MM-DD (e.g. {$year}-01-26), 26 Jan {$year} or Jan 26, {$year}" : null,
                $date !== null && (int) substr($date, 0, 4) !== $year ? "{$date} is not in {$year}" : null,
                $row['name'] === '' ? 'the name is missing' : null,
                mb_strlen($row['name']) > 255 ? 'the name is longer than 255 characters' : null,
                $type === null ? "type '{$row['type']}' must be public or optional" : null,
                $halfDay === null ? "half_day '{$row['half_day']}' must be yes or no" : null,
                $region !== HolidayLibraryList::NATIONAL && ! isset($known[$region]) ? "region_code '{$row['region_code']}' is not on the region list for ".Countries::name($country).' (Platform → Regions)' : null,
                $date !== null && isset($regions[$region]['rows'][$date]) ? "{$date} appears twice for ".($region === HolidayLibraryList::NATIONAL ? 'the national list' : $region) : null,
            ]);
            if ($problems !== []) {
                $errors[] = "Row {$rowNumber}: ".implode('; ', $problems).'.';

                continue;
            }

            $regions[$region]['name'] = $region === HolidayLibraryList::NATIONAL ? null : $known[$region];
            $regions[$region]['rows'][$date] = ['name' => $row['name'], 'type' => $type, 'is_half_day' => $halfDay];
        }

        if ($errors !== []) {
            $more = count($errors) - self::MAX_ERRORS_SHOWN;
            throw new InvalidArgumentException('Nothing was uploaded. '.implode(' ', array_slice($errors, 0, self::MAX_ERRORS_SHOWN)).($more > 0 ? " And {$more} more." : ''));
        }
        if ($regions === []) {
            throw new InvalidArgumentException('The file has no holidays.');
        }
        ksort($regions);

        return $regions;
    }

    public function publish(HolidayLibraryList $list, ?User $actor): void
    {
        PlatformOperator::assert($actor, 'publish holiday lists');
        if ($list->status === HolidayLibraryStatus::Published) {
            throw new RuntimeException('This holiday list is already published.');
        }
        if (! $list->entries()->exists()) {
            throw new RuntimeException('An empty holiday list cannot be published.');
        }
        $list->update(['status' => HolidayLibraryStatus::Published, 'published_by' => $actor?->id, 'published_at' => now()]);
        $this->record(AuditAction::StatusChange, $list, $actor, ['status' => 'published']);
    }

    /** Tenants stop being offered the list; holidays they already copied stay in their calendars. */
    public function withdraw(HolidayLibraryList $list, ?User $actor): void
    {
        PlatformOperator::assert($actor, 'withdraw holiday lists');
        if ($list->status !== HolidayLibraryStatus::Published) {
            throw new RuntimeException('Only a published holiday list can be withdrawn.');
        }
        $list->update(['status' => HolidayLibraryStatus::Withdrawn]);
        $this->record(AuditAction::StatusChange, $list, $actor, ['status' => 'withdrawn']);
    }

    public function delete(HolidayLibraryList $list, ?User $actor): void
    {
        PlatformOperator::assert($actor, 'delete holiday lists');
        if ($list->status === HolidayLibraryStatus::Published) {
            throw new RuntimeException('Withdraw a published holiday list before deleting it.');
        }
        $this->record(AuditAction::Delete, $list, $actor, ['holidays' => $list->entries()->count()]);
        $list->delete();
    }

    /** @return array<string, string> countries with a published list: code => name, by name */
    public function countries(): array
    {
        return HolidayLibraryList::query()->published()->distinct()->pluck('country_code')
            ->mapWithKeys(fn (string $code) => [$code => Countries::name($code)])->sort()->all();
    }

    /** @return array<int, string> years with a published list for the country, newest first */
    public function years(?string $country): array
    {
        return HolidayLibraryList::query()->published()->where('country_code', (string) $country)->distinct()->orderByDesc('year')->pluck('year')
            ->mapWithKeys(fn ($year) => [(int) $year => (string) $year])->all();
    }

    /** @return array<string, string> region code => name; '*' offers the national holidays alone */
    public function regions(?string $country, ?int $year): array
    {
        $lists = HolidayLibraryList::query()->published()->where('country_code', (string) $country)->where('year', (int) $year)->get();
        $regional = $lists->reject(fn (HolidayLibraryList $l) => $l->isNational())->sortBy(fn (HolidayLibraryList $l) => $l->regionLabel())
            ->mapWithKeys(fn (HolidayLibraryList $l) => [$l->region_code => $l->regionLabel()])->all();

        return ($lists->contains(fn (HolidayLibraryList $l) => $l->isNational()) ? [HolidayLibraryList::NATIONAL => 'National holidays only'] : []) + $regional;
    }

    /**
     * Where the organisation's active locations in the country are, matched to regions by their state code or name.
     *
     * @return array<string, list<string>> region code => location names
     */
    public function locationRegions(?string $country): array
    {
        if (blank($country)) {
            return [];
        }
        $country = strtoupper((string) $country);
        $known = $this->regions->active($country);
        $found = [];
        foreach (Location::query()->where('status', ActiveStatus::Active->value)->whereNotNull('state_code')->orderBy('name')->get(['name', 'state_code', 'country_code']) as $location) {
            $code = strtoupper((string) $location->country_code) === $country ? $this->regions->match($country, $location->state_code, $known) : null;
            if ($code !== null) {
                $found[$code][] = $location->name;
            }
        }

        return $found;
    }

    /** @return Collection<int, HolidayLibraryEntry> the published national holidays plus the region's, by date */
    public function entries(?string $country, ?int $year, ?string $region): Collection
    {
        if (blank($country) || blank($year) || blank($region)) {
            return collect();
        }

        return HolidayLibraryEntry::query()->with('libraryList')
            ->whereHas('libraryList', fn ($query) => $query->published()->where('country_code', $country)->where('year', (int) $year)
                ->whereIn('region_code', array_unique([HolidayLibraryList::NATIONAL, (string) $region])))
            ->orderBy('date')->orderBy('id')->get();
    }

    /**
     * Copy the chosen library holidays into a tenant calendar. Dates the calendar already has are left alone and
     * reported. Only entries of published lists can be copied, whatever ids the form sends.
     *
     * @param  list<int|string>  $entryIds
     * @return array{added: int, skipped: list<string>}
     */
    public function adopt(HolidayCalendar $calendar, array $entryIds, User $actor, ?string $reason = null): array
    {
        if (! $actor->can('update', $calendar)) {
            throw new RuntimeException('You cannot change this holiday calendar.');
        }
        $ids = array_values(array_unique(array_map('intval', $entryIds)));
        if ($ids === []) {
            throw new InvalidArgumentException('Choose at least one holiday.');
        }
        $entries = HolidayLibraryEntry::query()->whereKey($ids)->whereHas('libraryList', fn ($query) => $query->published())->orderBy('date')->orderBy('id')->get();
        if ($entries->count() !== count($ids)) {
            throw new RuntimeException('Some of the chosen holidays are no longer offered by the holiday library. Open the list again and choose again.');
        }

        $added = [];
        $skipped = [];
        // Block closures throughout: an arrow function would capture $added and $skipped by value.
        DB::transaction(function () use ($calendar, $entries, $reason, &$added, &$skipped) {
            $this->audit->operation('attendance', "Holidays added to {$calendar->name} from the holiday library", function () use ($calendar, $entries, $reason, &$added, &$skipped) {
                $taken = Holiday::query()->where('holiday_calendar_id', $calendar->id)->get(['date'])
                    ->mapWithKeys(fn (Holiday $h) => [$h->date->toDateString() => true])->all();
                foreach ($entries as $entry) {
                    $date = $entry->date->toDateString();
                    if (isset($taken[$date])) {
                        $skipped[] = $entry->date->format('d M Y')." {$entry->name}";

                        continue;
                    }
                    $holiday = new Holiday(['holiday_calendar_id' => $calendar->id, 'holiday_library_entry_id' => $entry->id, 'date' => $date,
                        'name' => $entry->name, 'type' => $entry->type, 'is_half_day' => $entry->is_half_day]);
                    $holiday->withAuditReason($reason)->save();
                    $taken[$date] = true;
                    $added[] = $holiday->id;
                }

                return ['succeeded' => count($added), 'ids' => $added];
            }, $reason, Holiday::class);
        });

        return ['added' => count($added), 'skipped' => $skipped];
    }

    /**
     * A new calendar holding the chosen library holidays. Who it applies to is set afterwards on its rules.
     *
     * @param  array{name: string, code: string, year?: int|string|null}  $data
     * @param  list<int|string>  $entryIds
     * @return array{calendar: HolidayCalendar, added: int, skipped: list<string>}
     */
    public function createCalendar(array $data, array $entryIds, User $actor): array
    {
        if (! $actor->can('create', HolidayCalendar::class)) {
            throw new RuntimeException('You cannot create holiday calendars.');
        }

        return DB::transaction(function () use ($data, $entryIds, $actor) {
            $calendar = HolidayCalendar::create(['name' => $data['name'], 'code' => $data['code'], 'year' => filled($data['year'] ?? null) ? (int) $data['year'] : null, 'status' => ActiveStatus::Active]);

            return ['calendar' => $calendar] + $this->adopt($calendar, $entryIds, $actor);
        });
    }

    /** "Mon, 26 Jan 2027 · Republic Day" with "National · Public holiday · Half day" beneath, for the pickers. */
    public static function optionLabel(HolidayLibraryEntry $entry): string
    {
        return $entry->date->format('D, d M Y').' · '.$entry->name;
    }

    public static function optionDescription(HolidayLibraryEntry $entry): string
    {
        return implode(' · ', array_filter([
            $entry->libraryList?->regionLabel(),
            config("peopleos.attendance.holiday_types.{$entry->type}", $entry->type),
            $entry->is_half_day ? 'Half day' : null,
        ]));
    }

    /** A filled-in template for the upload. */
    public static function templateCsv(int $year): string
    {
        return implode("\n", [
            implode(',', self::COLUMNS),
            "{$year}-01-26,Republic Day,public,no,",
            "{$year}-08-15,Independence Day,public,no,",
            "{$year}-10-02,Gandhi Jayanti,public,no,",
            "{$year}-05-01,Maharashtra Day,public,no,MH",
            "{$year}-11-01,Karnataka Rajyotsava,public,no,KA",
        ])."\n";
    }

    private function date(string $value): ?string
    {
        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function type(string $value): ?string
    {
        $value = strtolower($value);
        if ($value === '') {
            return 'public';
        }
        foreach (self::TYPE_ALIASES as $type => $aliases) {
            if (in_array($value, $aliases, true)) {
                return $type;
            }
        }

        return null;
    }

    private function flag(string $value): ?bool
    {
        return match (strtolower($value)) {
            '', 'no', 'n', 'false', '0' => false,
            'yes', 'y', 'true', '1' => true,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $metadata */
    private function record(AuditAction $action, HolidayLibraryList $list, ?User $actor, array $metadata): void
    {
        $this->audit->record($action, 'attendance', null, [], null, entityLabel: "Holiday library {$list->label()}", actor: $actor,
            metadata: ['country_code' => $list->country_code, 'region_code' => $list->region_code, 'year' => $list->year] + $metadata, platform: true);
    }
}
