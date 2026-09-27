<?php

namespace App\Domain\Configuration\Events;

use App\Domain\Configuration\Models\ConfigurationChange;
use Illuminate\Foundation\Events\Dispatchable;

final class ConfigurationChangeProposed
{
    use Dispatchable;

    public function __construct(public readonly ConfigurationChange $change) {}
}
