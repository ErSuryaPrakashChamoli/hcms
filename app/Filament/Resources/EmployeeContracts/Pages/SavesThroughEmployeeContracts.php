<?php

namespace App\Filament\Resources\EmployeeContracts\Pages;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeContract;
use App\Domain\Employment\Services\EmployeeContracts;
use App\Filament\Support\AuditReasonField;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/** The contract pages save through EmployeeContracts (permission, scope, validation, audit, timeline). */
trait SavesThroughEmployeeContracts
{
    protected function saveContract(Employee $employee, array $data, ?EmployeeContract $contract): EmployeeContract
    {
        $reason = AuditReasonField::extract($data);
        try {
            return app(EmployeeContracts::class)->save($employee, $data, auth()->user(), $contract, $reason);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all());
        } catch (ProfileChangeRefused $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();

            throw new Halt;
        }
    }
}
