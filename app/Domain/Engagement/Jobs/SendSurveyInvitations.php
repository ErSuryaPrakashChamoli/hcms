<?php

namespace App\Domain\Engagement\Jobs;

use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\SurveyNotices;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Phase 13: invitations for one opened survey version — tenant-bound and idempotent (one log row per participation). */
class SendSurveyInvitations implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> Phase 14: retry with backoff instead of hammering a failing dependency. */
    public array $backoff = [60, 300];

    public function __construct(public ?int $tenantId, public int $surveyVersionId) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'survey-invitations-'.$this->surveyVersionId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(SurveyNotices $notices): void
    {
        $version = SurveyVersion::query()->find($this->surveyVersionId);
        if ($version !== null) {
            $notices->invite($version);
        }
    }
}
