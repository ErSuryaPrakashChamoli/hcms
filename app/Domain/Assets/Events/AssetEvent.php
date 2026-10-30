<?php

namespace App\Domain\Assets\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * asset.assigned / returned / transferred / repair / disposed / lost. HROne setup parity AS-B: asset.requested /
 * asset.return_requested (workflows start on them), asset.request_to_fulfil / _approved / _rejected / _fulfilled /
 * _cancelled — with the users told in-app when no notification rule sends one (else the employee).
 */
final class AssetEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}
