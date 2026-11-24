<?php

namespace App\Providers;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Applicability\SharedConfigurationGuard;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

/**
 * Configuration applicability (docs/architecture/configuration-applicability.md): the per-request memos of explicit
 * applicability and overrides never outlive a request, a job or an application instance; companies created later join
 * the records shared with them; and the model-level safeguards of every registered configuration type.
 */
class ConfigurationApplicabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        self::flush();
        $this->app['events']->listen(RequestHandled::class, fn () => self::flush());
        $this->app['events']->listen(JobProcessing::class, fn () => self::flush());
    }

    public function boot(): void
    {
        Company::created(function (Company $company): void {
            if (app(TenantContext::class)->id() !== null) {
                app(EntityApplicability::class)->companyCreated($company);
            }
        });
        app(SharedConfigurationGuard::class)->register();
    }

    private static function flush(): void
    {
        EntityApplicability::flushMemo();
        ConfigurationOverrides::flushMemo();
    }
}
