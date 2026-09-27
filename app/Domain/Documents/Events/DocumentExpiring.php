<?php

namespace App\Domain\Documents\Events;

use App\Domain\Documents\Models\EmployeeDocument;
use Illuminate\Foundation\Events\Dispatchable;

final class DocumentExpiring
{
    use Dispatchable;

    public function __construct(public readonly EmployeeDocument $document) {}
}
