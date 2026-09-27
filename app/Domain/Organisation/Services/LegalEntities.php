<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0001 transition: guarantees every company has an explicit primary legal entity and a primary
 * establishment, and that its locations point at an establishment. Idempotent; never rewrites an
 * existing legal entity or establishment (other than filling a missing establishment state).
 */
final class LegalEntities
{
    /** @return array{legal_entity: LegalEntity, establishment: Establishment, created: bool} */
    public function ensureFor(Company $company, ?string $state = null): array
    {
        return DB::transaction(function () use ($company, $state) {
            $created = false;
            $entity = LegalEntity::query()->withoutGlobalScope(AccessScope::class)
                ->where('company_id', $company->getKey())->orderByDesc('is_primary')->orderBy('id')->first();

            if ($entity === null) {
                $entity = LegalEntity::query()->create([
                    'company_id' => $company->getKey(),
                    'code' => $this->uniqueCode((string) ($company->code ?: 'LE'.$company->getKey())),
                    'legal_name' => $company->legal_name ?: $company->name,
                    'trade_name' => $company->name,
                    'country' => strtoupper((string) ($company->country_code ?: 'IN')),
                    'is_primary' => true,
                    'status' => 'active',
                    'effective_from' => ($company->effective_from ?? $company->created_at ?? now())->toDateString(),
                    'metadata' => ['source' => 'adr-0001-transition'],
                ]);
                $created = true;
            }

            $establishment = Establishment::query()->withoutGlobalScope(AccessScope::class)
                ->where('legal_entity_id', $entity->getKey())->orderByDesc('is_primary')->orderBy('id')->first();

            if ($establishment === null) {
                $establishment = Establishment::query()->create([
                    'legal_entity_id' => $entity->getKey(),
                    'code' => 'MAIN',
                    'name' => $company->name.' — principal establishment',
                    'country' => $entity->country,
                    'state' => $state,
                    'establishment_type' => 'registered_office',
                    'is_primary' => true,
                    'status' => 'active',
                    'effective_from' => $entity->effective_from->toDateString(),
                    'metadata' => ['source' => 'adr-0001-transition'],
                ]);
                $created = true;
            } elseif ($establishment->state === null && $state !== null) {
                $establishment->withAuditReason('ADR-0001 transition: state from the legacy statutory profile')->update(['state' => $state]);
            }

            Location::query()->withoutGlobalScope(AccessScope::class)
                ->where('company_id', $company->getKey())->whereNull('establishment_id')->get()
                ->each(fn (Location $location) => $location->withAuditReason('ADR-0001 transition: location attached to the principal establishment')->update(['establishment_id' => $establishment->getKey()]));

            return ['legal_entity' => $entity, 'establishment' => $establishment, 'created' => $created];
        });
    }

    public function primaryEstablishment(int $companyId): ?Establishment
    {
        return Establishment::query()->withoutGlobalScope(AccessScope::class)
            ->where('company_id', $companyId)->orderByDesc('is_primary')->orderBy('id')->first();
    }

    private function uniqueCode(string $base): string
    {
        $base = substr(strtoupper($base), 0, 28);
        $code = $base;
        $i = 1;

        while (LegalEntity::query()->withoutGlobalScope(AccessScope::class)->where('code', $code)->exists()) {
            $code = $base.'-'.(++$i);
        }

        return $code;
    }
}
