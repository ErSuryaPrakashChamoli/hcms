<?php

namespace App\Domain\Bgv\Providers;

use App\Domain\Bgv\Models\BgvCase;

/** In-house verification: no external call, HR records results. */
final class ManualProvider implements BgvProvider
{
    public function initiate(BgvCase $case): ?string
    {
        return null;
    }
}
