<?php

namespace Database\Seeders;

use Database\Seeders\Production\FirstTenantSeeder;
use Database\Seeders\Production\PermissionCatalogueSeeder;
use Database\Seeders\Production\PlatformOperatorSeeder;
use Database\Seeders\Production\StatutoryPackSeeder;
use Illuminate\Database\Seeder;

/**
 * The production baseline: everything a fresh (or upgraded) installation needs that PeopleOS can create without
 * anyone's decision. Safe in every environment and idempotent: run it on every deploy with
 * `php artisan db:seed --force` (DatabaseSeeder runs only this in production), or alone with
 * `--class=ProductionSeeder`. It creates no demo data and sets no password.
 *
 * 1. The permission catalogue (config/peopleos.php), and every tenant's system roles topped up (additive; a permission
 *    a tenant removed stays removed).
 * 2. The platform statutory packs (database/data/compliance): rule versions (DRAFT or REVIEW, never VERIFIED),
 *    regulatory notices and statutory export layouts.
 * 3. The first platform operator, when PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL is set: created once, sent a password-reset
 *    link, MFA enrolled at the first sign-in.
 * 4. The first tenant, when PEOPLEOS_BOOTSTRAP_TENANT_NAME is set: provisioned once with its system roles, defaults and
 *    an invited first administrator.
 *
 * Deliberately not seeded (an owner decision or a second operator's approval, entered on the platform pages): plans and
 * prices, billing markets, the selling entity and invoice series, commercial policies, loading and verifying the billing
 * statutory dataset, statutory rule verification, retention policies. See NEXT_STEPS.
 */
class ProductionSeeder extends Seeder
{
    public const NEXT_STEPS = [
        'Sign in as the platform operator, enrol MFA, then run `php artisan peopleos:readiness` (no FAIL lines).',
        'Plans: Platform > Plans (no plan, price or default plan is shipped; decision D-1).',
        'Billing: Billing catalogue, Tax & invoicing, Commercial policies; each change needs a second operator\'s approval (docs/operations/commercial-configuration-runbook.md).',
        'Billing tax dataset: Tax & invoicing > Load statutory dataset (maker), then Verify statutory dataset (a second operator).',
        'Payroll statutory rules: verified by two platform administrators with official evidence (Compliance rules). Until then payroll finalisation is blocked.',
        'Retention: Platform > Retention policies (none shipped; nothing is deletable until Markedge decides, D-8).',
    ];

    public function run(): void
    {
        $this->call([
            PermissionCatalogueSeeder::class,
            StatutoryPackSeeder::class,
            PlatformOperatorSeeder::class,
            FirstTenantSeeder::class,
        ]);

        if ($this->command !== null) {
            $this->command->newLine();
            $this->command->info('Production baseline seeded. Remaining set-up is by platform operators, never by a seeder:');
            foreach (self::NEXT_STEPS as $i => $step) {
                $this->command->line('  '.($i + 1).'. '.$step);
            }
        }
    }
}
