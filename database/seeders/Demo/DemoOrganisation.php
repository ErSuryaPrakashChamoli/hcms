<?php

namespace Database\Seeders\Demo;

use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use RuntimeException;

/**
 * The demo organisation's identity: one tenant, "Demo Group" at the address "demo", every sign-in on demo.local.
 *
 * The tenant carries metadata.demo_organisation = true once a demo seeder has claimed it. On production the demo seeders
 * refuse a tenant at that address that is not marked (a real customer can never be filled with fictional people).
 */
final class DemoOrganisation
{
    public const SLUG = 'demo';

    public const NAME = 'Demo Group';

    public const ADMIN_EMAIL = 'admin@demo.local';

    public const MARKER = 'demo_organisation';

    public const MIN_PASSWORD_LENGTH = 12;

    /** The demo's policies are in force from before its first joiner (2021-06-14), so its history has a policy. */
    public const POLICIES_FROM = '2021-01-01';

    public static function tenant(): ?Tenant
    {
        return app(TenantContext::class)->bypass(fn () => Tenant::query()->where('slug', self::SLUG)->first());
    }

    public static function isMarked(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->metadata, self::MARKER, false);
    }

    /** Refuses, on production, a tenant at the demo address that a demo seeder did not create. */
    public static function assertClaimable(?Tenant $tenant): void
    {
        if ($tenant !== null && app()->isProduction() && ! self::isMarked($tenant)) {
            throw new RuntimeException('A tenant at the address ['.self::SLUG.'] already exists and is not the demo organisation. '
                .'Demo data is never added to it; the demo seeders stop here.');
        }
    }

    /** Marks the tenant as the demo organisation (once; audited like any tenant change). Check assertClaimable first. */
    public static function mark(Tenant $tenant): void
    {
        if (self::isMarked($tenant)) {
            return;
        }
        app(TenantContext::class)->bypass(fn () => $tenant
            ->forceFill(['metadata' => array_merge((array) $tenant->metadata, [self::MARKER => true])])
            ->withAuditReason('Demo organisation: tenant marked as fictional demo data')
            ->save());
    }
}
