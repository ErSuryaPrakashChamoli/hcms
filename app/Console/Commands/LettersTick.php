<?php

namespace App\Console\Commands;

use App\Domain\Letters\Services\LetterDownloads;
use App\Domain\Letters\Services\LetterSchedules;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne parity WO-13: the daily letter duties, per tenant —
 * 1. queue the letter schedules due today (event schedules now; one-time schedules at their publish time);
 * 2. draft the transfer policy's `letter_on_effect` letter for transfers that take effect today (approved earlier);
 * 3. remove bulk letter downloads (zips) whose link has expired.
 * Each step is idempotent: schedules keep what they did per employee and occurrence, a transfer gets one such letter.
 */
class LettersTick extends Command
{
    protected $signature = 'peopleos:letters:tick {--date= : Run as of this date (testing)} {--tenant=}';

    protected $description = 'Run due letter schedules, transfer letters on the effective date and expire bulk letter downloads';

    public function handle(TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $now = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $now) {
                $queued = app(LetterSchedules::class)->dispatchDue($now);
                $transfers = app(LetterSchedules::class)->transferEffects($now);
                $expired = app(LetterDownloads::class)->prune();
                $this->info("{$tenant->slug}: {$queued} schedule(s) queued, {$transfers} transfer letter(s) drafted, {$expired} download(s) expired");
            })));

        return $runner->exitCode();
    }
}
