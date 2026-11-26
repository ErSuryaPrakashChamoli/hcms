<?php

namespace App\Domain\Partners\Imports;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Compliance\Support\RegistrationNumberFormat;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Resolution\NameKey;
use App\Domain\Organisation\Resolution\ReferenceMappings;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\Vendors;
use App\Support\Geography\Countries;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Data import (Workforce → Vendor): business partners that are organisations — staffing agencies, contractors and
 * other vendors — keyed by vendor code, written through Partners\Services\Vendors (its validation, the PAN encrypted and
 * shown only by its last four characters, the audit). A code on file is updated with the cells given (blank cells keep
 * what is on file); any other code adds a vendor, which then needs a name and a kind. Names first (2026-11-26): the
 * vendor code may be left blank — the row then changes the vendor of that name, or adds one with a code made from the
 * name; the state and country may be given by name. Validation checks what can go wrong in a sheet before the run; the
 * service checks everything again when the file runs.
 */
final class VendorImports extends RowImports
{
    public const TYPE = 'vendors';

    /** @var array<int, array{names: array<string, list<array{code: string, live: bool}>>, codes: array<string, true>, rows: array<string, int>}> import id => vendors by name and code, for a run */
    private array $onFile = [];

    public static function fields(): array
    {
        return [
            'code' => ['Vendor code', false], 'name' => ['Vendor name', false], 'kind' => ['Kind', false], 'gstin' => ['GST number', false],
            'pan' => ['PAN', false], 'contact_name' => ['Contact name', false], 'email' => ['Email', false], 'phone' => ['Phone', false],
            'address_line1' => ['Address line 1', false], 'address_line2' => ['Address line 2', false], 'city' => ['City', false],
            'state' => ['State', false], 'postal_code' => ['Postal code', false], 'country' => ['Country', false], 'status' => ['Status', false],
        ];
    }

    public function label(): string
    {
        return 'Vendor upload';
    }

    protected function module(): string
    {
        return 'partners';
    }

    protected function entityType(): string
    {
        return Vendor::class;
    }

    protected function aliases(): array
    {
        return ['vendor' => 'code', 'name_of_vendor' => 'name', 'vendor_kind' => 'kind', 'type' => 'kind', 'gst' => 'gstin', 'gstin' => 'gstin',
            'gst_registration_number' => 'gstin', 'contact_person' => 'contact_name', 'mobile' => 'phone', 'address' => 'address_line1',
            'state' => 'state', 'state_code' => 'state', 'pin_code' => 'postal_code', 'pincode' => 'postal_code', 'country' => 'country', 'country_code' => 'country'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        $kinds = app(Vendors::class)->kinds();
        $byName = [];
        foreach ($kinds as $code => $label) {
            $byName[mb_strtolower((string) $code)] = (string) $code;
            $byName[mb_strtolower((string) $label)] = (string) $code;
        }

        return ['kinds' => $byName, 'seen' => [], 'vendors' => $this->vendorsOnFile()];
    }

    /** Vendors on file by name (the comparison key) and by code, for rows that leave the code blank. */
    private function vendorsOnFile(): array
    {
        $state = ['names' => [], 'codes' => [], 'rows' => []];
        foreach (Vendor::query()->get(['code', 'name', 'status']) as $vendor) {
            $state['codes'][mb_strtolower((string) $vendor->code)] = true;
            $key = NameKey::key((string) $vendor->name);
            if ($key !== '') {
                $state['names'][$key][] = ['code' => (string) $vendor->code, 'live' => mb_strtolower((string) $vendor->status) !== 'inactive'];
            }
        }

        return $state;
    }

    /**
     * Names first: a row that leaves the vendor code blank gets one here. The vendor of that name gives its code (the
     * active one when an inactive vendor shares the name; several of that name are refused: the code says which); a new
     * vendor gets a code made from its name (ReferenceMappings::codeFrom, then _2, _3 … when taken). Two rows may not
     * name the same vendor without a code. Returns the row with its code, and the problem (null when there is none).
     *
     * @param  array<string, string>  $r
     * @param  array{names: array<string, list<array{code: string, live: bool}>>, codes: array<string, true>, rows: array<string, int>}  $state
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function keyed(array $r, array &$state, int $rowNumber): array
    {
        $code = trim((string) ($r['code'] ?? ''));
        $name = trim((string) ($r['name'] ?? ''));
        $key = NameKey::key($name);
        if ($code !== '') {
            $state['codes'][mb_strtolower($code)] = true;
            if ($key !== '') {
                $state['rows'][$key] ??= $rowNumber;
            }

            return [$r, null];
        }
        if ($key === '') {
            return [$r, 'Vendor name is required (or give the vendor code of the vendor to change).'];
        }
        if (isset($state['rows'][$key]) && $state['rows'][$key] !== $rowNumber) {
            return [$r, "The same vendor name is in row {$state['rows'][$key]}."];
        }
        $state['rows'][$key] = $rowNumber;
        $matches = $state['names'][$key] ?? [];
        $live = array_values(array_filter($matches, fn (array $m) => $m['live']));
        $matches = count($matches) > 1 && count($live) === 1 ? $live : $matches;
        if (count($matches) > 1) {
            return [$r, "Several vendors are called '{$name}' (".implode(', ', array_column(array_slice($matches, 0, 5), 'code')).'): put the code of the one you mean in the Vendor code column.'];
        }
        if ($matches !== []) {
            $r['code'] = $matches[0]['code'];

            return [$r, null];
        }
        $base = ReferenceMappings::codeFrom($name);
        for ($attempt = 1; ; $attempt++) {
            $code = $attempt === 1 ? $base : mb_substr($base, 0, 28).'_'.$attempt;
            if (! isset($state['codes'][mb_strtolower($code)])) {
                break;
            }
        }
        $state['codes'][mb_strtolower($code)] = true;
        $state['names'][$key][] = ['code' => $code, 'live' => true];
        $r['code'] = $code;

        return [$r, null];
    }

    /** Names first: the country and state as the service keeps them (codes), from a name or a code. */
    private function located(array $r, ?Vendor $existing): array
    {
        if (filled($r['country'] ?? null)) {
            $r['country'] = Countries::codeOf($r['country']) ?? $r['country'];
        }
        if (filled($r['state'] ?? null)) {
            $r['state'] = app(CountryRegions::class)->match($r['country'] ?? $existing?->country ?? 'IN', $r['state']) ?? $r['state'];
        }

        return $r;
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$r, $problem] = $this->keyed($r, $context['vendors'], (int) $row->row_number);
        if ($problem !== null) {
            return ['action' => 'error', 'errors' => [$problem]];
        }
        $code = trim((string) $r['code']);
        $key = mb_strtolower($code);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same vendor code is in row {$context['seen'][$key]}."]];
        }
        $context['seen'][$key] = $row->row_number;

        $existing = $this->find($code);
        // A record not on file in an "existing records only" upload is skipped (RowImports::validate), never an error.
        if ($existing === null && ! ImportMode::of($import)->adds()) {
            return ['action' => 'create'];
        }
        $errors = [];
        if (filled($r['country'] ?? null) && Countries::codeOf($r['country']) === null) {
            $errors[] = "Country '{$r['country']}' is not a country: use its English name (India) or its two-letter code (IN).";
        }
        $r = $this->located($r, $existing);
        if (preg_match(Vendors::CODE_PATTERN, $code) !== 1 || mb_strlen($code) > 32) {
            $errors[] = 'A vendor code uses letters, digits, - _ and / (starting with a letter or digit), at most 32 characters.';
        }
        if ($existing === null) {
            foreach (['name' => 'Vendor name', 'kind' => 'Kind'] as $field => $label) {
                if (($r[$field] ?? '') === '') {
                    $errors[] = "{$label} is required for a new vendor.";
                }
            }
        }
        if (isset($r['kind']) && ! isset($context['kinds'][mb_strtolower($r['kind'])])) {
            $errors[] = "Kind '{$r['kind']}' is not in the list (Setup → Option lists → Vendor kinds).";
        }
        $pan = isset($r['pan']) ? strtoupper(preg_replace('/\s+/', '', $r['pan'])) : null;
        if ($pan !== null && preg_match(Vendors::PAN_PATTERN, $pan) !== 1) {
            $errors[] = 'A PAN has 10 characters: five letters, four digits and a letter.';
        }
        if (isset($r['gstin'])) {
            $gst = strtoupper(preg_replace('/\s+/', '', $r['gstin']));
            $country = strtoupper($r['country'] ?? ($existing?->country ?? 'IN'));
            try {
                RegistrationNumberFormat::assertValid(['label' => 'GST number'] + (array) (config('peopleos.compliance.registration_types')['gstin'] ?? []), $gst,
                    $country === 'IN' ? (isset($r['state']) ? strtoupper($r['state']) : $existing?->state) : null);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
            if ($pan !== null && strlen($gst) === 15 && substr($gst, 2, 10) !== $pan) {
                $errors[] = 'The PAN differs from the one inside the GST number.';
            }
        }
        if (isset($r['status']) && ! array_key_exists(mb_strtolower($r['status']), Vendor::STATUSES)) {
            $errors[] = 'Status must be active or inactive.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors];
        }

        return $existing === null ? ['action' => 'create'] : ['action' => 'update', 'match' => ['id' => $existing->getKey(), 'name' => $existing->name]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $this->onFile[$import->getKey()] ??= $this->vendorsOnFile();
        [$r, $problem] = $this->keyed($r, $this->onFile[$import->getKey()], (int) $row->row_number);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        $r = $this->located($r, $this->find(trim((string) $r['code'])));
        $data = array_intersect_key($r, self::fields());
        if (isset($data['kind'])) {
            $data['kind'] = $this->prepare($import)['kinds'][mb_strtolower($data['kind'])] ?? $data['kind'];
        }
        if (isset($data['status'])) {
            $data['status'] = mb_strtolower($data['status']);
        }
        $data['audit_reason'] = $this->label()." #{$import->getKey()} ({$import->original_name})";
        $vendors = app(Vendors::class);
        $existing = $this->find(trim((string) $r['code']));
        try {
            $vendor = $existing === null ? $vendors->create($data, $user) : $vendors->update($existing, $data, $user);
        } catch (ValidationException $e) {
            throw new RuntimeException(implode(' ', array_merge(...array_values($e->errors()))));
        }

        return ($existing === null ? 'Added vendor ' : 'Updated vendor ').$vendor->code;
    }

    public function format(): array
    {
        $kinds = app(Vendors::class)->kinds();

        return [
            'columns' => [
                ['Vendor code', 'No', ReferenceGuide::ownCode('vendor').' Letters, digits, - _ and /.', ''],
                ['Vendor name', 'To add', 'The registered name. Needed to add a vendor, and to find one when Vendor code is blank.', 'Skyline Staffing Services Pvt Ltd'],
                ['Kind', 'To add', 'A vendor kind code or name: '.implode(', ', array_map(fn ($c, $l) => "{$c} ({$l})", array_keys($kinds), $kinds)).'.', (string) (array_key_first($kinds) ?? 'staffing_agency')],
                ['GST number', 'No', 'The 15-character GST registration number; its state code must match the State.', ''],
                ['PAN', 'No', 'Ten characters (ABCDE1234F); when a GST number is given it must be the PAN inside it. Kept encrypted.', 'AABCS1234Q'],
                ['Contact name', 'No', 'The person to contact.', 'Neha Kapoor'],
                ['Email', 'No', 'Contact e-mail.', 'accounts@skyline.example'],
                ['Phone', 'No', 'Digits; may start with + and use spaces or dashes.', '+91 80 4000 1234'],
                ['Address line 1', 'No', '', '4th floor, Prestige Tower'],
                ['Address line 2', 'No', '', 'Residency Road'],
                ['City', 'No', '', 'Bengaluru'],
                ['State', 'No', 'The state or region\'s name; its code works too (KA for Karnataka).', 'Karnataka'],
                ['Postal code', 'No', '', '560025'],
                ['Country', 'No', 'The country\'s name; its two-letter ISO code works too. Blank means India for a new vendor.', 'India'],
                ['Status', 'No', 'active (default) or inactive. A vendor is never deleted: one no longer used is made inactive.', 'active'],
            ],
            'notes' => [
                'Rows are matched by vendor code. The vendor code may be left blank: the row then changes the vendor of that name, or adds one with a code made from the name. Give the code to rename a vendor or to choose a code of your own.',
                'Updating changes only the columns that have a value.',
                'People of a vendor who sign in (business partners) are added on Workforce → Business partners.',
            ],
        ];
    }

    protected function examples(): array
    {
        $kind = (string) (array_key_first(app(Vendors::class)->kinds()) ?? 'staffing_agency');

        return [
            ['', 'Skyline Staffing Services Pvt Ltd', $kind, '', 'AABCS1234Q', 'Neha Kapoor', 'accounts@skyline.example', '+91 80 4000 1234', '4th floor, Prestige Tower', 'Residency Road', 'Bengaluru', 'Karnataka', '560025', 'India', 'active'],
            ['', 'Metro Facility Contractors', $kind, '', '', 'Arjun Mehta', 'ops@metrofc.example', '+91 22 6100 2200', 'Plot 12, MIDC', 'Andheri East', 'Mumbai', 'Maharashtra', '400093', 'India', 'active'],
        ];
    }

    private function find(string $code): ?Vendor
    {
        return Vendor::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();
    }
}
