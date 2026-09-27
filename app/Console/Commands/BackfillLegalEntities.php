<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Organisation\Services\LegalEntities;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * ADR-0001 transition for existing data: one primary legal entity and one principal establishment
 * per company (state from the legacy statutory profile, else the first located state); the legacy
 * registration columns become statutory registrations, the legacy flags become establishment
 * profiles, and every employee gets an establishment assignment from their position. Idempotent,
 * and every row it creates is audited inside one operation per tenant.
 */
class BackfillLegalEntities extends Command
{
    protected $signature = 'peopleos:legal-entities:backfill {--tenant=}';

    protected $description = 'Give every company an explicit legal entity and principal establishment (ADR-0001)';

    public function handle(LegalEntities $entities, StatutoryRegistrations $registrations, EstablishmentAssignments $assignments, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($entities, $registrations, $assignments, $tenants) {
                $tenants->runAs($tenant, function () use ($entities, $registrations, $assignments, $tenant) {
                    $created = 0;
                    $statutory = 0;
                    $assigned = 0;

                    app(AuditRecorder::class)->operation('organisation', 'ADR-0001 legal entity transition', function () use ($entities, $registrations, $assignments, &$created, &$statutory, &$assigned) {
                        Company::query()->orderBy('id')->each(function (Company $company) use ($entities, $registrations, &$created, &$statutory) {
                            $legacy = CompanyStatutoryProfile::query()->where('company_id', $company->id)->first();
                            $state = $legacy?->pt_state
                                ?? Location::query()->where('company_id', $company->id)->whereNotNull('state_code')->orderBy('id')->value('state_code');

                            $result = $entities->ensureFor($company, $state);
                            $created += $result['created'] ? 1 : 0;

                            if ($legacy !== null) {
                                $statutory += $registrations->backfillFromLegacyProfile($legacy, $result['establishment']->fresh());
                            }
                        });

                        Employee::query()->orderBy('id')->each(function (Employee $employee) use ($assignments, &$assigned) {
                            $assigned += $assignments->backfill($employee) !== null ? 1 : 0;
                        });

                        return ['succeeded' => $created + $statutory + $assigned];
                    }, entityType: LegalEntity::class);

                    $this->info("{$tenant->slug}: {$created} compan(y/ies) transitioned, {$statutory} registration/profile row(s), {$assigned} employee assignment(s)");
                });
            });

        return self::SUCCESS;
    }
}
