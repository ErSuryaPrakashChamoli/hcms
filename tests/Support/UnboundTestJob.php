<?php

namespace Tests\Support;

use App\Domain\Organisation\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A job that forgets to carry its tenant: must fail closed. */
class UnboundTestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Company::factory()->create(['name' => 'Should not exist']);
    }
}
