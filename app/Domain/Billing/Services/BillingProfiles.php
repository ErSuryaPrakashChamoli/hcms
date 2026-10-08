<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Services\TaxRegistry;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Support\Commercial\OperatorChange;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: a tenant's billing identity (tenant-owned, versioned). The billing jurisdiction is what the operator
 * records here, never derived from IP, locale, currency, server or employee location, nor from the tenant's
 * operating country. B2B or B2C is explicit. A tax identifier must be one its country issues; its format is checked
 * where a jurisdiction module can (India GSTIN), otherwise it is stored as "not validated". No profile is created
 * for any tenant by SaaS.7: a tenant without one simply cannot be invoiced. Launch rule (B-5): business customers
 * only (the B2B policy, configurable on Platform > Commercial policies).
 */
final class BillingProfiles
{
    public function __construct(private readonly BillingAudit $audit, private readonly TaxEngine $tax, private readonly TaxRegistry $registry,
        private readonly TenantContext $tenants) {}

    /**
     * @param  array{customer_type: string, legal_name: string, billing_email: string, billing_contact?: ?string, address_line1: string,
     *     address_line2?: ?string, city: string, postal_code?: ?string, country: string, subdivision?: ?string, tax_registration: string,
     *     tax_id_type?: ?string, tax_id_value?: ?string, special_tax_status?: ?string}  $data
     */
    public function record(Tenant $tenant, BillingMarket $market, array $data, string $effectiveFrom, string $reason, User $actor, ?string $reference = null): TenantBillingProfile
    {
        OperatorChange::assert($actor, $reason, 'billing profiles');
        $from = $this->day($effectiveFrom);
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A billing profile takes effect today or later: issued invoices keep the profile they were issued under.');
        }
        $attributes = $this->validated($market, $data) + ['effective_from' => $from, 'market_id' => $market->id, 'reason' => $reason,
            'reference' => $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 100), 'created_by' => $actor->id];

        // Serialised on the tenant's own profile rows (never on entitlement tables); the version unique index is the backstop.
        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $market, $attributes, $from, $reason, $actor) {
            $previous = TenantBillingProfile::query()->with('market')->orderByDesc('version')->lockForUpdate()->first();
            $profile = TenantBillingProfile::query()->create($attributes + ['version' => ($previous?->version ?? 0) + 1]);
            $this->audit->both(AuditAction::BillingProfileRecorded, 'billing', $tenant, $profile, "billing profile v{$profile->version}", [
                ['field' => 'market', 'before' => $previous?->market?->code ?? 'none', 'after' => $market->code],
                ['field' => 'customer_type', 'before' => $previous?->customer_type?->value ?? 'none', 'after' => $profile->customer_type->value],
                ['field' => 'legal_name', 'before' => $previous?->legal_name ?? 'none', 'after' => $profile->legal_name],
                ['field' => 'jurisdiction', 'before' => $previous ? ($previous->subdivision ?? $previous->country) : 'none', 'after' => $profile->subdivision ?? $profile->country],
                ['field' => 'tax_registration', 'before' => $previous ? trim("{$previous->tax_registration->value} {$previous->tax_id_value}") : 'none',
                    'after' => trim("{$profile->tax_registration->value} {$profile->tax_id_value}")],
            ], $reason, $actor, ['billing_profile_id' => $profile->id, 'version' => $profile->version, 'reference' => $profile->reference], $from);

            return $profile;
        }));
    }

    /** The version in force on $day (the latest started; on one day, the latest version). Read within the tenant. */
    public function inForce(Tenant $tenant, string $day): ?TenantBillingProfile
    {
        return $this->tenants->runAs($tenant, fn () => TenantBillingProfile::query()->with('market')->whereDate('effective_from', '<=', $day)
            ->orderByDesc('effective_from')->orderByDesc('version')->first());
    }

    /** @param  array<string, mixed>  $data  @return array{legal_name: string, address_line1: string, address_line2: ?string, city: string, postal_code: ?string} */
    public static function address(array $data): array
    {
        $legal = trim((string) ($data['legal_name'] ?? ''));
        $line1 = trim((string) ($data['address_line1'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        if ($legal === '' || mb_strlen($legal) > 200 || $line1 === '' || mb_strlen($line1) > 200 || $city === '' || mb_strlen($city) > 100) {
            throw new RuntimeException('A legal name, address line and city are required (at most 200, 200 and 100 characters).');
        }
        $line2 = trim((string) ($data['address_line2'] ?? ''));
        $postal = trim((string) ($data['postal_code'] ?? ''));

        return ['legal_name' => $legal, 'address_line1' => $line1, 'address_line2' => $line2 === '' ? null : mb_substr($line2, 0, 200), 'city' => $city,
            'postal_code' => $postal === '' ? null : mb_substr($postal, 0, 20)];
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function validated(BillingMarket $market, array $data): array
    {
        $type = CustomerType::tryFrom((string) ($data['customer_type'] ?? '')) ?? throw new RuntimeException('Say whether the customer is a business (B2B) or a consumer (B2C).');
        // B-5 (approved): PeopleOS is sold to businesses only at launch. B2C stays representable for a later decision.
        if ($type === CustomerType::Consumer && app(CommercialConfiguration::class)->required(ConfigurationKey::B2bOnly) === true) {
            throw new RuntimeException('PeopleOS is sold to businesses only (B2B): a consumer billing profile cannot be recorded.');
        }
        $registration = TaxRegistration::tryFrom((string) ($data['tax_registration'] ?? '')) ?? throw new RuntimeException('Say whether the customer is registered for tax.');
        $email = trim((string) ($data['billing_email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new RuntimeException('A valid billing e-mail address is required.');
        }
        $country = strtoupper(trim((string) ($data['country'] ?? '')));
        $subdivision = trim((string) ($data['subdivision'] ?? '')) === '' ? null : strtoupper(trim((string) $data['subdivision']));
        try {
            new TaxJurisdiction($country, $subdivision);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $known = $this->registry->subdivisions($country);
        if ($known !== [] && ($subdivision === null || ! isset($known[$subdivision]))) {
            throw new RuntimeException("Choose the state or territory of {$country}: tax determination needs it.");
        }
        if ($type === CustomerType::Consumer && $registration !== TaxRegistration::NotApplicable) {
            throw new RuntimeException('A consumer (B2C) has no business tax registration: choose "not applicable".');
        }
        if ($type === CustomerType::Business && $registration === TaxRegistration::NotApplicable) {
            throw new RuntimeException('A business (B2B) is either registered for tax or not registered.');
        }
        [$idType, $idValue, $idStatus] = [null, null, null];
        if ($registration === TaxRegistration::Registered) {
            $idType = TaxIdType::tryFrom((string) ($data['tax_id_type'] ?? '')) ?? throw new RuntimeException('A registered customer needs the type of its tax identifier.');
            $issuers = $idType->issuers();
            if ($issuers !== null && ! in_array($country, $issuers, true)) {
                throw new RuntimeException("A {$idType->label()} is not issued in {$country}.");
            }
            try {
                ['value' => $idValue, 'status' => $idStatus] = $this->tax->normaliseTaxId($idType, (string) ($data['tax_id_value'] ?? ''), $subdivision);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException($e->getMessage());
            }
        } elseif (filled($data['tax_id_value'] ?? null)) {
            throw new RuntimeException('Only a registered customer has a tax identifier.');
        }
        $special = trim((string) ($data['special_tax_status'] ?? '')) === '' ? null : (string) $data['special_tax_status'];
        if ($special !== null && ! array_key_exists($special, $this->registry->specialStatuses($country))) {
            throw new RuntimeException("\"{$special}\" is not a special tax status known for {$country}.");
        }
        $contact = trim((string) ($data['billing_contact'] ?? ''));

        return self::address($data) + ['customer_type' => $type, 'billing_email' => $email, 'billing_contact' => $contact === '' ? null : mb_substr($contact, 0, 120),
            'country' => $country, 'subdivision' => $subdivision, 'tax_registration' => $registration, 'tax_id_type' => $idType, 'tax_id_value' => $idValue,
            'tax_id_status' => $idStatus, 'special_tax_status' => $special];
    }

    private function day(string $day): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
    }
}
