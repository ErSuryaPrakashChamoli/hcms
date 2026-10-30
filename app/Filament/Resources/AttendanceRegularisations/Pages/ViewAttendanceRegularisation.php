<?php

namespace App\Filament\Resources\AttendanceRegularisations\Pages;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** HROne parity TX-1: a regularisation's detail, read-only (decisions stay on the register and in the Approval Center). */
class ViewAttendanceRegularisation extends PeopleViewRecord
{
    protected static string $resource = AttendanceRegularisationResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var AttendanceRegularisation $regularisation */
        $regularisation = $this->getRecord();

        return 'Regularisation: '.$regularisation->auditLabel();
    }
}
