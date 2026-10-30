<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** HROne parity TX-1: a leave request's detail, read-only (decisions stay on the register and in the Approval Center). */
class ViewLeaveRequest extends PeopleViewRecord
{
    protected static string $resource = LeaveRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var LeaveRequest $request */
        $request = $this->getRecord();

        return 'Leave: '.$request->auditLabel();
    }
}
