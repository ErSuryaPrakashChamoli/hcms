<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Exit\Models\ExitClearance;
use Illuminate\Database\Eloquent\Model;

/** HROne parity WO-11: how a clearance stage is named in a bulk run ("EXIT-2026-00001 · IT clearance · E001 · Asha"). */
final class ClearanceRecordLabel
{
    public static function for(Model $record): string
    {
        if (! $record instanceof ExitClearance) {
            return class_basename($record).' #'.$record->getKey();
        }
        $case = $record->loadMissing('exitCase.employee.person')->exitCase;
        $employee = $case?->employee;

        return mb_substr(implode(' · ', array_filter([$case?->number, $record->name, $employee?->employee_code, $employee?->person?->display_name])), 0, 191);
    }
}
