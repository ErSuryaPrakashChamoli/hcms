<?php

namespace App\Domain\Partners\Imports;

use App\Domain\Compliance\Support\RegistrationNumberFormat;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Identity\Models\User;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\Vendors;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Data import (Workforce → Vendor): business partners that are organisations — staffing agencies, contractors and
 * other vendors — keyed by vendor code, written through Partners\Services\Vendors (its validation, the PAN encrypted and
 * shown only by its last four characters, the audit). A code on file is updated with the cells given (blank cells keep
 * what is on file); any other code adds a vendor, which then needs a name and a kind. Validation checks what can go wrong
 * in a sheet before the run; the service checks everything again when the file runs.
 */
final class VendorImports extends RowImports
{
    public const TYPE = 'vendors';

    public static function fields(): array
    {
        return [
            'code' => ['Vendor code', true], 'name' => ['Vendor name', false], 'kind' => ['Kind', false], 'gstin' => ['GST number', false],
            'pan' => ['PAN', false], 'contact_name' => ['Contact name', false], 'email' => ['Email', false], 'phone' => ['Phone', false],
            'address_line1' => ['Address line 1', false], 'address_line2' => ['Address line 2', false], 'city' => ['City', false],
            'state' => ['State code', false], 'postal_code' => ['Postal code', false], 'country' => ['Country code', false], 'status' => ['Status', false],
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
            'state' => 'state', 'pin_code' => 'postal_code', 'pincode' => 'postal_code', 'country' => 'country'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        $kinds = app(Vendors::class)->kinds();
        $byName = [];
        foreach ($kinds as $code => $label) {
            $byName[mb_strtolower((string) $code)] = (string) $code;
            $byName[mb_strtolower((string) $label)] = (string) $code;
        }

        return ['kinds' => $byName, 'seen' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
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
                ['Vendor code', 'Yes', 'The vendor\'s code: letters, digits, - _ and /. A code on file updates that vendor; a new code adds one.', 'AGY-001'],
                ['Vendor name', 'To add', 'The registered name.', 'Skyline Staffing Services Pvt Ltd'],
                ['Kind', 'To add', 'A vendor kind code or name: '.implode(', ', array_map(fn ($c, $l) => "{$c} ({$l})", array_keys($kinds), $kinds)).'.', (string) (array_key_first($kinds) ?? 'staffing_agency')],
                ['GST number', 'No', 'The 15-character GST registration number; its state code must match the State code.', ''],
                ['PAN', 'No', 'Ten characters (ABCDE1234F); when a GST number is given it must be the PAN inside it. Kept encrypted.', 'AABCS1234Q'],
                ['Contact name', 'No', 'The person to contact.', 'Neha Kapoor'],
                ['Email', 'No', 'Contact e-mail.', 'accounts@skyline.example'],
                ['Phone', 'No', 'Digits; may start with + and use spaces or dashes.', '+91 80 4000 1234'],
                ['Address line 1', 'No', '', '4th floor, Prestige Tower'],
                ['Address line 2', 'No', '', 'Residency Road'],
                ['City', 'No', '', 'Bengaluru'],
                ['State code', 'No', 'The state or region code, e.g. KA for Karnataka.', 'KA'],
                ['Postal code', 'No', '', '560025'],
                ['Country code', 'No', 'Two letters; blank means IN for a new vendor.', 'IN'],
                ['Status', 'No', 'active (default) or inactive. A vendor is never deleted: one no longer used is made inactive.', 'active'],
            ],
            'notes' => [
                'Rows are matched by vendor code. Updating changes only the columns that have a value.',
                'People of a vendor who sign in (business partners) are added on Workforce → Business partners.',
            ],
        ];
    }

    protected function examples(): array
    {
        $kind = (string) (array_key_first(app(Vendors::class)->kinds()) ?? 'staffing_agency');

        return [
            ['AGY-001', 'Skyline Staffing Services Pvt Ltd', $kind, '', 'AABCS1234Q', 'Neha Kapoor', 'accounts@skyline.example', '+91 80 4000 1234', '4th floor, Prestige Tower', 'Residency Road', 'Bengaluru', 'KA', '560025', 'IN', 'active'],
            ['CON-014', 'Metro Facility Contractors', $kind, '', '', 'Arjun Mehta', 'ops@metrofc.example', '+91 22 6100 2200', 'Plot 12, MIDC', 'Andheri East', 'Mumbai', 'MH', '400093', 'IN', 'active'],
        ];
    }

    private function find(string $code): ?Vendor
    {
        return Vendor::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();
    }
}
