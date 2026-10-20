<?php

namespace Database\Seeders\Production;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Support\Money\Currency;
use App\Support\Observability\PlatformReadiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The first tenant (PEOPLEOS_BOOTSTRAP_TENANT_NAME), provisioned once through the same action as the operator's Tenants
 * page: system roles, default features and settings, the organisation, leave, payroll, performance, asset, service-desk,
 * letter and analytics defaults, and a Tenant Super Admin who is invited by e-mail and chooses their own password.
 *
 * - A tenant with the slug already existing is left as it is (nothing is topped up or changed).
 * - The slug follows the tenant address rules: lower-case letters, digits and "-", never a reserved word.
 * - The administrator's address must be free; production refuses a reserved, undeliverable one.
 * - No plan, subscription or price is assigned: a new tenant is commercially unconfigured until an operator decides.
 */
class FirstTenantSeeder extends Seeder
{
    public const REASON = 'Production bootstrap: first tenant (ProductionSeeder)';

    public function run(ProvisionTenantAction $provisioner, TenantFirstAdministrator $administrators, TenantContext $tenants): void
    {
        $config = (array) config('peopleos.bootstrap.tenant', []);
        $name = trim((string) ($config['name'] ?? ''));
        if ($name === '') {
            $this->command?->line('  First tenant: skipped (set PEOPLEOS_BOOTSTRAP_TENANT_NAME to provision one; operators can also create tenants on the Tenants page).');

            return;
        }

        $slug = Str::lower(trim((string) ($config['slug'] ?? ''))) ?: Str::limit(Str::slug($name), 48, '');
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 63) {
            throw new RuntimeException('PEOPLEOS_BOOTSTRAP_TENANT_SLUG must be lower-case letters, digits and single hyphens (at most 63 characters).');
        }
        if (in_array($slug, (array) config('peopleos.signup.reserved_slugs', []), true)) {
            throw new RuntimeException("The tenant address [{$slug}] is reserved; set PEOPLEOS_BOOTSTRAP_TENANT_SLUG to another.");
        }

        if ($tenants->bypass(fn () => Tenant::query()->where('slug', $slug)->exists())) {
            $this->command?->line("  First tenant: [{$slug}] already exists, unchanged.");

            return;
        }

        $adminName = trim((string) ($config['admin_name'] ?? ''));
        $adminEmail = Str::lower(trim((string) ($config['admin_email'] ?? '')));
        if ($adminName === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The first tenant needs PEOPLEOS_BOOTSTRAP_TENANT_ADMIN_NAME and a valid PEOPLEOS_BOOTSTRAP_TENANT_ADMIN_EMAIL.');
        }
        if (app()->environment('production') && PlatformReadiness::isReservedEmail($adminEmail)) {
            throw new RuntimeException('PEOPLEOS_BOOTSTRAP_TENANT_ADMIN_EMAIL uses a reserved, undeliverable domain (.local, .test, example.*); production needs a real mailbox.');
        }
        if (User::query()->where('email', $adminEmail)->exists()) {
            throw new RuntimeException('PEOPLEOS_BOOTSTRAP_TENANT_ADMIN_EMAIL already belongs to an account; choose another address.');
        }

        $country = strtoupper(trim((string) ($config['country_code'] ?? 'IN')));
        $timezone = trim((string) ($config['timezone'] ?? 'Asia/Kolkata'));
        $currency = strtoupper(trim((string) ($config['currency'] ?? 'INR')));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new RuntimeException('PEOPLEOS_BOOTSTRAP_TENANT_COUNTRY is a two-letter country code (for example IN).');
        }
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException("PEOPLEOS_BOOTSTRAP_TENANT_TIMEZONE [{$timezone}] is not a known time zone (for example Asia/Kolkata).");
        }
        if (Currency::tryFrom($currency) === null) {
            throw new RuntimeException("PEOPLEOS_BOOTSTRAP_TENANT_CURRENCY [{$currency}] is not a supported currency.");
        }

        $tenant = $provisioner->handle(
            ['name' => Str::limit($name, 255, ''), 'slug' => $slug, 'country_code' => $country, 'timezone' => $timezone, 'currency' => $currency, 'locale' => 'en'],
            ['name' => Str::limit($adminName, 255, ''), 'email' => $adminEmail],
            reason: self::REASON,
        );

        $this->command?->info("  First tenant [{$slug}] provisioned.");
        $this->command?->line($administrators->needsInvitation($tenant)
            ? '  The invitation e-mail to its administrator was not sent: an operator sends it from the Tenants list (Send invitation) once mail works.'
            : "  Its administrator {$adminEmail} was invited by e-mail to choose a password.");
    }
}
