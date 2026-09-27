<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\LegalEntities;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * ADR-0001 transition for existing data: one primary legal entity and one principal establishment
 * per company (state from the legacy statutory profile, else the first located state). Idempotent,
 * and every row it creates is audited inside one operation per tenant.
 */
class BackfillLegalEntities extends Command
{
    protected $signature = 'peopleos:legal-entities:backfill {--tenant=}';

    protected $description = 'Give every company an explicit legal entity and principal establishment (ADR-0001)';

    public function handle(LegalEntities $entities, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($entities, $tenants) {
                $tenants->runAs($tenant, function () use ($entities, $tenant) {
                    $created = 0;

                    app(AuditRecorder::class)->operation('organisation', 'ADR-0001 legal entity transition', function () use ($entities, &$created) {
                        Company::query()->orderBy('id')->each(function (Company $company) use ($entities, &$created) {
                            $state = CompanyStatutoryProfile::query()->where('company_id', $company->id)->value('pt_state')
                                ?? Location::query()->where('company_id', $company->id)->whereNotNull('state_code')->orderBy('id')->value('state_code');

                            $created += $entities->ensureFor($company, $state)['created'] ? 1 : 0;
                        });

                        return ['succeeded' => $created];
                    }, entityType: LegalEntity::class);

                    $this->info("{$tenant->slug}: {$created} compan(y/ies) transitioned");
                });
            });

        return self::SUCCESS;
    }
}
