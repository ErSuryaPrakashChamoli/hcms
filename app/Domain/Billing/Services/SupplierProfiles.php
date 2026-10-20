<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Models\FinancialApproval;
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
 *
 * SaaS.7 configuration closure: an entity's identity and registrations (GSTIN, the LUT, foreign VAT, TRN, US state
 * permits) decide how invoices are taxed, so a version is proposed by one operator and applies only once another
 * approves it on the approval desk (`supplier_profile_change`); until then it is pending and never used.
 */
final class SupplierProfiles
{
    public function __construct(private readonly BillingAudit $audit, private readonly FinancialApprovals $approvals, private readonly TaxEngine $tax,
        private readonly TaxRegistry $registry) {}

    /**
     * @param  array{legal_name: string, address_line1: string, address_line2?: ?string, city: string, postal_code?: ?string, country: string, subdivision?: ?string,
     *     tax_id_type?: ?string, tax_id_value?: ?string, registrations?: list<array{type: string, reference: string, valid_from: string, valid_to?: ?string}>}  $data
     *     registrations: undertakings and registrations elsewhere that tax conditions require (e.g. IN_LUT for an export under
     *     a letter of undertaking, GB_VAT, EU_OSS_NON_UNION, AE_TRN, US_STATE:US-TX), each with its validity
     */
    public function propose(string $entityCode, array $data, string $effectiveFrom, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'supplier profiles');
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

        $registrations = $this->registrations((array) ($data['registrations'] ?? []));

        return DB::transaction(function () use ($entityCode, $address, $country, $subdivision, $type, $value, $registrations, $from, $reason, $maker) {
            $previous = SupplierProfile::query()->where('entity_code', $entityCode)->lockForUpdate()->orderByDesc('version')->first();
            $current = $this->inForce($entityCode, $from);
            $profile = SupplierProfile::query()->create($address + ['entity_code' => $entityCode, 'version' => ($previous?->version ?? 0) + 1,
                'country' => $country, 'subdivision' => $subdivision, 'tax_id_type' => $type, 'tax_id_value' => $value,
                'registrations' => $registrations === [] ? null : $registrations, 'effective_from' => $from, 'status' => SupplierProfile::PENDING,
                'reason' => $reason, 'created_by' => $maker->id]);
            $changes = $this->changes($current, $profile);
            $this->audit->platform(AuditAction::SupplierProfileProposed, 'billing', $profile, "Supplier {$entityCode} v{$profile->version}", $changes, $reason, $maker,
                ['entity' => $entityCode, 'version' => $profile->version], $from);
            $approval = $this->approvals->request(ApprovalAction::SupplierProfileChange, $profile, null,
                ['entity' => $entityCode, 'version' => $profile->version, 'legal_name' => $profile->legal_name, 'jurisdiction' => $subdivision ?? $country,
                    'tax_registration' => $value ?? 'none', 'registrations' => $this->describeRegistrations($registrations), 'effective_from' => $from],
                collect($changes)->mapWithKeys(fn (array $c) => [$c['field'] => $c['before']])->all(), collect($changes)->mapWithKeys(fn (array $c) => [$c['field'] => $c['after']])->all(),
                "{$entityCode}:v{$profile->version}", $reason, $maker);
            $profile->forceFill(['approval_id' => $approval->id])->save();

            return $approval;
        }, 3);   // MySQL: two first proposals of one entity can deadlock on the version's gap lock; the retry numbers the loser next
    }

    /** Executes an approved selling-entity version (called by the approval desk, inside its transaction). */
    public function executeChange(FinancialApproval $approval): SupplierProfile
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::SupplierProfileChange);
        $locked = SupplierProfile::query()->lockForUpdate()->findOrFail($approval->subject_id);
        if ($locked->status !== SupplierProfile::PENDING) {
            throw new RuntimeException("This version is already {$locked->status}.");
        }
        if ($locked->effective_from->toDateString() < now()->toDateString()) {
            throw new RuntimeException('This version was to start on '.$locked->effective_from->toDateString().', which has passed: reject it and propose it again from today or later.');
        }
        $checker = User::query()->findOrFail($approval->checker_id);
        $current = $this->inForce($locked->entity_code, $locked->effective_from->toDateString());
        $locked->forceFill(['status' => SupplierProfile::APPROVED, 'approved_by' => $checker->id, 'approved_at' => now()])->save();
        $this->audit->platform(AuditAction::SupplierProfileRecorded, 'billing', $locked, "Supplier {$locked->entity_code} v{$locked->version}", $this->changes($current, $locked),
            (string) $approval->checker_reason, $checker, ['entity' => $locked->entity_code, 'version' => $locked->version, 'approval' => $approval->reference,
                'maker_id' => $approval->maker_id, 'checker_id' => $checker->id, 'correlation_id' => $approval->correlation_key], $locked->effective_from->toDateString());
        $this->approvals->executed($approval, "Supplier {$locked->entity_code} v{$locked->version} from {$locked->effective_from->toDateString()}");

        return $locked;
    }

    /** Keeps a version in step when its approval request is rejected or withdrawn (it never applies). */
    public function close(FinancialApproval $approval): void
    {
        $profile = SupplierProfile::query()->find($approval->subject_id);
        if ($profile !== null && $profile->status === SupplierProfile::PENDING && in_array($approval->status->value, [SupplierProfile::REJECTED, SupplierProfile::WITHDRAWN], true)) {
            $profile->forceFill(['status' => $approval->status->value])->save();
        }
    }

    /** The approved version in force on $day: the latest started one. A pending, rejected or withdrawn version never applies. */
    public function inForce(string $entityCode, string $day): ?SupplierProfile
    {
        return SupplierProfile::query()->where(['entity_code' => $entityCode, 'status' => SupplierProfile::APPROVED])->whereDate('effective_from', '<=', $day)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** The latest approved version of an entity, whatever its start (numbering rules follow its jurisdiction). */
    public function latestApproved(string $entityCode): ?SupplierProfile
    {
        return SupplierProfile::query()->where(['entity_code' => $entityCode, 'status' => SupplierProfile::APPROVED])->orderByDesc('version')->first();
    }

    /** @return list<array{field: string, before: ?string, after: ?string}> */
    private function changes(?SupplierProfile $before, SupplierProfile $after): array
    {
        return [['field' => 'legal_name', 'before' => $before?->legal_name ?? 'none', 'after' => $after->legal_name],
            ['field' => 'jurisdiction', 'before' => $before ? ($before->subdivision ?? $before->country) : 'none', 'after' => $after->subdivision ?? $after->country],
            ['field' => 'tax_registration', 'before' => $before?->tax_id_value ?? 'none', 'after' => $after->tax_id_value ?? 'none'],
            ['field' => 'registrations', 'before' => $this->describeRegistrations($before?->registrations ?? []), 'after' => $this->describeRegistrations($after->registrations ?? [])]];
    }

    /** @return list<array{type: string, reference: string, valid_from: string, valid_to: ?string}> */
    private function registrations(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $kind = strtoupper(trim((string) ($row['type'] ?? '')));
            $reference = trim((string) ($row['reference'] ?? ''));
            if (preg_match('/^[A-Z0-9_:-]{2,40}$/', $kind) !== 1 || $reference === '' || mb_strlen($reference) > 100) {
                throw new RuntimeException('Each registration has a type (e.g. IN_LUT, GB_VAT, US_STATE:US-TX) and a reference of at most 100 characters.');
            }
            $from = $this->day((string) ($row['valid_from'] ?? ''));
            $to = blank($row['valid_to'] ?? null) ? null : $this->day((string) $row['valid_to']);
            if ($to !== null && $to < $from) {
                throw new RuntimeException("The registration {$kind} ends after it starts.");
            }
            $out[] = ['type' => $kind, 'reference' => $reference, 'valid_from' => $from, 'valid_to' => $to];
        }

        return $out;
    }

    private function describeRegistrations(array $rows): string
    {
        return $rows === [] ? 'none' : implode('; ', array_map(fn (array $r) => "{$r['type']} {$r['reference']} ({$r['valid_from']} to ".($r['valid_to'] ?? 'open').')', $rows));
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
