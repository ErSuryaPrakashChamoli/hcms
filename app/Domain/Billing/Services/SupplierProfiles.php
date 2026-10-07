<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Services\TaxRegistry;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Support\Commercial\OperatorChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: Markedge's selling entities (platform catalogue). Each change is a new version from a date (today or
 * later); an invoice copies the version in force at issue. No entity, address or registration is shipped: they
 * are decision B-7.
 */
final class SupplierProfiles
{
    public function __construct(private readonly BillingAudit $audit, private readonly TaxEngine $tax, private readonly TaxRegistry $registry) {}

    /** @param  array{legal_name: string, address_line1: string, address_line2?: ?string, city: string, postal_code?: ?string, country: string, subdivision?: ?string, tax_id_type?: ?string, tax_id_value?: ?string}  $data */
    public function record(string $entityCode, array $data, string $effectiveFrom, string $reason, User $actor): SupplierProfile
    {
        OperatorChange::assert($actor, $reason, 'supplier profiles');
        $entityCode = strtoupper(trim($entityCode));
        if (preg_match('/^[A-Z0-9_-]{2,32}$/', $entityCode) !== 1) {
            throw new RuntimeException('The entity code is 2 to 32 capital letters, digits, dash or underscore.');
        }
        $from = $this->day($effectiveFrom);
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A supplier profile takes effect today or later: issued invoices keep the profile they were issued under.');
        }
        $address = BillingProfiles::address($data);
        [$country, $subdivision] = $this->jurisdiction($data['country'] ?? '', $data['subdivision'] ?? null);
        [$type, $value] = [null, null];
        if (filled($data['tax_id_type'] ?? null) || filled($data['tax_id_value'] ?? null)) {
            $type = TaxIdType::tryFrom((string) ($data['tax_id_type'] ?? '')) ?? throw new RuntimeException('Choose the type of the tax registration.');
            try {
                $value = $this->tax->normaliseTaxId($type, (string) ($data['tax_id_value'] ?? ''), $subdivision)['value'];
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException($e->getMessage());
            }
        }

        return DB::transaction(function () use ($entityCode, $address, $country, $subdivision, $type, $value, $from, $reason, $actor) {
            $previous = SupplierProfile::query()->where('entity_code', $entityCode)->lockForUpdate()->orderByDesc('version')->first();
            $profile = SupplierProfile::query()->create($address + ['entity_code' => $entityCode, 'version' => ($previous?->version ?? 0) + 1,
                'country' => $country, 'subdivision' => $subdivision, 'tax_id_type' => $type, 'tax_id_value' => $value, 'effective_from' => $from,
                'reason' => $reason, 'created_by' => $actor->id]);
            $this->audit->platform(AuditAction::SupplierProfileRecorded, 'billing', $profile, "Supplier {$entityCode} v{$profile->version}",
                [['field' => 'legal_name', 'before' => $previous?->legal_name ?? 'none', 'after' => $profile->legal_name],
                    ['field' => 'jurisdiction', 'before' => $previous ? ($previous->subdivision ?? $previous->country) : 'none', 'after' => $subdivision ?? $country],
                    ['field' => 'tax_registration', 'before' => $previous?->tax_id_value ?? 'none', 'after' => $value ?? 'none']],
                $reason, $actor, ['entity' => $entityCode, 'version' => $profile->version], $from);

            return $profile;
        });
    }

    /** The version in force on $day: the latest started one. */
    public function inForce(string $entityCode, string $day): ?SupplierProfile
    {
        return SupplierProfile::query()->where('entity_code', $entityCode)->whereDate('effective_from', '<=', $day)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** @return array{0: string, 1: ?string} */
    private function jurisdiction(string $country, ?string $subdivision): array
    {
        $country = strtoupper(trim($country));
        $subdivision = $subdivision === null || trim($subdivision) === '' ? null : strtoupper(trim($subdivision));
        try {
            new TaxJurisdiction($country, $subdivision);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $known = $this->registry->subdivisions($country);
        if ($known !== [] && ($subdivision === null || ! isset($known[$subdivision]))) {
            throw new RuntimeException("Choose the state of {$country} the entity is registered in.");
        }

        return [$country, $subdivision];
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
