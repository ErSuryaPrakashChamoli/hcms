<?php

namespace App\Domain\Configuration\Events;

use App\Domain\Configuration\Models\FormSubmission;
use Illuminate\Foundation\Events\Dispatchable;

final class FormSubmitted
{
    use Dispatchable;

    public function __construct(public readonly FormSubmission $submission) {}
}
