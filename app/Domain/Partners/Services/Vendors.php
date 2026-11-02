<?php

namespace App\Domain\Partners\Services;

use App\Domain\Compliance\Support\RegistrationNumberFormat;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Identity\Models\User;
use App\Domain\Partners\Models\Vendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * HROne parity WO-15 (setup matrix 01 §1.33): the one write path of the vendor master — the Vendors screen and the vendor
 * upload (another package) both call create() / update().
 *
 * - Rights: `partner.manage` (PartnerPolicy), for the actor's own organisation; a vendor of another organisation is never
 *   written.
 * - Validation here, whoever calls (ValidationException keyed by field): code (letters, digits, - _ /, at most 32, unique in
 *   the organisation without regard to case), name, kind (option list `vendor_kinds`), the GST registration number in the
 *   format of the `gstin` registration type (peopleos.compliance.registration_types: pattern, check character, the state
 *   code it starts with when an Indian state is given — RegistrationNumberFormat, as Statutory registrations check it), PAN
 *   (ABCDE1234F; when both are given it must be the one inside the GST number), contact, address, ISO country, status.
 * - update() takes the fields given and keeps the others (an upload may send only some columns); tenant_id and unknown keys
 *   are ignored. `audit_reason` becomes the audit trail's reason. Each write is audited by the model (the PAN masked).
 */
final class Vendors
{
    /** Every field create() / update() accept. */
    public const FIELDS = ['code', 'name', 'kind', 'gstin', 'pan', 'contact_name', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'status'];

    public const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-\/]*$/';

    public const PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public function __construct(private readonly TenantContext $tenants, private readonly OptionLists $lists) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Vendor
    {
        $this->authorise($by, 'create', Vendor::class);
        $reason = $this->reason($data);
        $input = array_replace(array_fill_keys(self::FIELDS, null), $this->normalise(array_intersect_key($data, array_flip(self::FIELDS))));
        $input['country'] ??= 'IN';
        $input['status'] ??= 'active';
        $values = $this->validated($input, null);

        return DB::transaction(function () use ($values, $reason) {
            $vendor = new Vendor;
            $vendor->forceFill($values)->withAuditReason($reason)->save();

            return $vendor;
        });
    }

    /** @param array<string, mixed> $data the fields to change; the others keep their values */
    public function update(Vendor $vendor, array $data, User $by): Vendor
    {
        $this->assertOwn($vendor);
        $this->authorise($by, 'update', $vendor);
        $reason = $this->reason($data);
        $current = array_intersect_key($vendor->only(self::FIELDS), array_flip(self::FIELDS));
        $current['pan'] = $vendor->pan;
        $values = $this->validated(array_replace($current, $this->normalise(array_intersect_key($data, array_flip(self::FIELDS)))), $vendor);

        return DB::transaction(function () use ($vendor, $values, $reason) {
            $vendor->forceFill($values)->withAuditReason($reason)->save();

            return $vendor;
        });
    }

    /** @return array<string, string> code => label of the vendor kinds offered now */
    public function kinds(): array
    {
        return $this->lists->options('vendor_kinds');
    }

    private function authorise(User $by, string $ability, mixed $subject): void
    {
        if (! $by->isPlatformAdmin() && (int) $by->tenant_id !== (int) $this->tenants->id()) {
            throw new AuthorizationException('Vendors are managed by people of the organisation.');
        }
        if (Gate::forUser($by)->denies($ability, $subject)) {
            throw new AuthorizationException('You cannot manage vendors.');
        }
    }

    private function assertOwn(Vendor $vendor): void
    {
        $tenantId = $this->tenants->id() ?? throw new RuntimeException('Vendors are managed inside an organisation.');
        if ((int) $vendor->tenant_id !== (int) $tenantId) {
            throw new AuthorizationException('This vendor belongs to another organisation.');
        }
    }

    private function reason(array &$data): ?string
    {
        $reason = $data['audit_reason'] ?? null;
        unset($data['audit_reason']);

        return filled($reason) ? mb_substr((string) $reason, 0, 1000) : null;
    }

    /**
     * Trimmed; blanks are null; codes for numbers (GST, PAN, state, country) upper-cased without spaces; e-mail lower-cased.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach ($data as $key => $value) {
            // Numbers typed into text fields (a sheet's phone or PIN code) are text.
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (is_string($value)) {
                $value = trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }
        foreach (['gstin', 'pan', 'state', 'country'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = strtoupper(preg_replace('/\s+/', '', $data[$key]));
            }
        }
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function validated(array $values, ?Vendor $vendor): array
    {
        $kinds = array_keys($this->kinds());
        $currentKind = $vendor?->getAttributes()['kind'] ?? null;
        $gstType = (array) (config('peopleos.compliance.registration_types')['gstin'] ?? []);

        $validator = Validator::make($values, [
            'code' => ['required', 'string', 'max:32', 'regex:'.self::CODE_PATTERN],
            'name' => ['required', 'string', 'max:200'],
            // A kind hidden from the option list since stays valid on the vendors that already have it.
            'kind' => ['required', 'string', Rule::in(array_values(array_filter([...$kinds, $currentKind], fn ($v) => $v !== null)))],
            'gstin' => ['nullable', 'string', 'size:15'],
            'pan' => ['nullable', 'string', 'regex:'.self::PAN_PATTERN],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:255', 'email'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s\-\.\(\)]{5,31}$/'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:16', 'regex:/^[A-Z0-9\-]+$/'],
            'postal_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'status' => ['required', Rule::in(array_keys(Vendor::STATUSES))],
        ], [
            'code.regex' => 'A vendor code uses letters, digits, - _ and / (starting with a letter or digit).',
            'pan.regex' => 'A PAN has 10 characters: five letters, four digits and a letter (ABCDE1234F).',
            'phone.regex' => 'A phone number has digits, and may start with + and use spaces, dashes, dots and brackets.',
            'state.regex' => 'Use the state or region code, e.g. KA for Karnataka.',
            'country.regex' => 'Use the two-letter country code, e.g. IN.',
            'kind.in' => 'Choose a vendor kind from the list (Setup → Option lists → Vendor kinds).',
        ]);

        $validator->after(function ($validator) use ($values, $vendor, $gstType): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $taken = Vendor::query()->whereRaw('lower(code) = ?', [mb_strtolower((string) $values['code'])])
                ->when($vendor !== null, fn ($q) => $q->whereKeyNot($vendor->getKey()))->exists();
            if ($taken) {
                $validator->errors()->add('code', 'Another vendor already has the code '.$values['code'].'.');
            }
            $gst = $values['gstin'] ?? null;
            if ($gst !== null) {
                try {
                    RegistrationNumberFormat::assertValid(['label' => 'GST registration number'] + $gstType, (string) $gst, ($values['country'] ?? null) === 'IN' ? ($values['state'] ?? null) : null);
                } catch (RuntimeException $e) {
                    $validator->errors()->add('gstin', $e->getMessage());

                    return;
                }
                if (($values['pan'] ?? null) !== null && substr((string) $gst, 2, 10) !== $values['pan']) {
                    $validator->errors()->add('pan', 'The PAN differs from the one inside the GST registration number ('.substr((string) $gst, 2, 10).').');
                }
            }
        });

        $validator->validate();

        return array_intersect_key($values, array_flip(self::FIELDS));
    }
}
