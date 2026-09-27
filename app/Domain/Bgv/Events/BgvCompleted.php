<?php

namespace App\Domain\Bgv\Events;

use App\Domain\Bgv\Models\BgvCase;
use Illuminate\Foundation\Events\Dispatchable;

final class BgvCompleted
{
    use Dispatchable;

    public function __construct(public readonly BgvCase $case) {}
}
