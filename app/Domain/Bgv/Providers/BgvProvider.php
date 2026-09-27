<?php

namespace App\Domain\Bgv\Providers;

use App\Domain\Bgv\Models\BgvCase;

/** Vendor adapter (§22, §109): initiate a case with the provider and return its reference. */
interface BgvProvider
{
    public function initiate(BgvCase $case): ?string;
}
