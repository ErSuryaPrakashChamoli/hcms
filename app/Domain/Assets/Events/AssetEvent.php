<?php

namespace App\Domain\Assets\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** asset.assigned / returned / transferred / repair / disposed / lost */
final class AssetEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = []) {}
}
