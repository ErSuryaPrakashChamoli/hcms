<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendancePunchController;
use App\Http\Controllers\Api\V1\BgvCallbackController;
use App\Http\Controllers\Api\V1\ComplianceController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\OrganisationController;
use App\Http\Controllers\Api\V1\PreEmployeeController;
use App\Http\Controllers\Api\V1\ReadController;
use App\Http\Controllers\Scim\ScimUserController;
use Illuminate\Support\Facades\Route;

/*
| Versioned integration API (§87). Every route is tenant-aware through the API key.
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('api.key:employees.read')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    });
    Route::middleware('api.key:employees.write')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::post('employees/{employee}/lifecycle', [EmployeeController::class, 'lifecycle'])->name('employees.lifecycle');
    });
    Route::get('organisation/{type}', [OrganisationController::class, 'index'])->middleware('api.key:organisation.read')->name('organisation.index');
    Route::middleware('api.key:attendance.read')->group(function () {
        Route::get('attendance/records', [AttendanceController::class, 'records'])->name('attendance.records');
        Route::get('attendance/records/{employee}/{date}', [AttendanceController::class, 'record'])->name('attendance.record');
        Route::get('attendance/exceptions', [AttendanceController::class, 'exceptions'])->name('attendance.exceptions');
        Route::get('attendance/regularisations', [AttendanceController::class, 'regularisations'])->name('attendance.regularisations.index');
        Route::get('attendance/shifts', [AttendanceController::class, 'shifts'])->name('attendance.shifts');
        Route::get('attendance/schedules', [AttendanceController::class, 'schedules'])->name('attendance.schedules');
    });
    Route::middleware('api.key:attendance.write')->group(function () {
        Route::post('attendance/punches', [AttendanceController::class, 'punch'])->name('attendance.punches.store');
        Route::post('attendance/regularisations', [AttendanceController::class, 'requestRegularisation'])->name('attendance.regularisations.store');
    });
    Route::middleware('api.key:leave.read')->group(function () {
        Route::get('leave/types', [LeaveController::class, 'types'])->name('leave.types');
        Route::get('leave/balances', [LeaveController::class, 'balances'])->name('leave.balances');
        Route::get('leave/transactions', [LeaveController::class, 'transactions'])->name('leave.transactions');
        Route::get('leave/requests', [LeaveController::class, 'requests'])->name('leave.requests');
        Route::get('leave/requests/{leaveRequest}', [LeaveController::class, 'show'])->name('leave.requests.show');
        Route::get('leave/calendar', [LeaveController::class, 'calendar'])->name('leave.calendar');
    });
    Route::middleware('api.key:leave.write')->group(function () {
        Route::post('leave/requests', [LeaveController::class, 'store'])->name('leave.requests.store');
        Route::post('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.requests.cancel');
    });
    Route::get('payroll/runs', [ReadController::class, 'payrollRuns'])->middleware('api.key:payroll.read')->name('payroll.runs');
    Route::get('payroll/runs/{run}', [ReadController::class, 'payrollRun'])->middleware('api.key:payroll.read')->name('payroll.runs.show');
    Route::get('payroll/periods', [ReadController::class, 'payrollPeriods'])->middleware('api.key:payroll.read')->name('payroll.periods');
    Route::get('payroll/payslips/{number}', [ReadController::class, 'payslip'])->middleware('api.key:payroll.read')->name('payroll.payslips.show');
    Route::get('payroll/payslips', [ReadController::class, 'payslips'])->middleware('api.key:payroll.read')->name('payroll.payslips');
    // Phase 5 Part S: read-only compliance API.
    Route::middleware('api.key:compliance.read')->prefix('compliance')->name('compliance.')->group(function () {
        Route::get('establishments', [ComplianceController::class, 'establishments'])->name('establishments');
        Route::get('registrations', [ComplianceController::class, 'registrations'])->name('registrations');
        Route::get('rules', [ComplianceController::class, 'rules'])->name('rules');
        Route::get('rules/{rule}', [ComplianceController::class, 'ruleShow'])->whereNumber('rule')->name('rules.show');
        Route::get('returns', [ComplianceController::class, 'returns'])->name('returns');
        Route::get('returns/{return}', [ComplianceController::class, 'show'])->whereNumber('return')->name('returns.show');
        Route::get('returns/{return}/entries', [ComplianceController::class, 'entries'])->whereNumber('return')->name('returns.entries');
        Route::get('reconciliation/{return}', [ComplianceController::class, 'reconciliation'])->whereNumber('return')->name('reconciliation');
    });
    Route::get('documents', [ReadController::class, 'documents'])->middleware('api.key:documents.read')->name('documents.index');
    Route::get('assets', [ReadController::class, 'assets'])->middleware('api.key:assets.read')->name('assets.index');
    Route::get('performance/appraisals', [ReadController::class, 'appraisals'])->middleware('api.key:performance.read')->name('performance.appraisals');
    Route::get('performance/goals', [ReadController::class, 'goals'])->middleware('api.key:performance.read')->name('performance.goals');
    Route::get('workflows/instances', [ReadController::class, 'workflowInstances'])->middleware('api.key:workflows.read')->name('workflows.instances');
    Route::get('workflows/tasks', [ReadController::class, 'workflowTasks'])->middleware('api.key:workflows.read')->name('workflows.tasks');
    Route::get('reports/{report}/run', [ReadController::class, 'runReport'])->middleware('api.key:reports.run')->name('reports.run');
    Route::post('pre-employees', [PreEmployeeController::class, 'store'])->middleware('api.key:rms.write')->name('pre-employees.store');
    Route::get('pre-employees/{reference}', [PreEmployeeController::class, 'show'])->middleware('api.key:rms.read')->name('pre-employees.show');
    Route::post('bgv/cases/{reference}/checks', [BgvCallbackController::class, 'store'])->middleware('api.key:bgv.write')->name('bgv.checks.store');
    Route::post('attendance/devices/{device}/punches', [AttendancePunchController::class, 'store'])->middleware('api.key:attendance.write')->name('attendance.punches.store');
});

Route::prefix('scim/v2')->middleware('api.key:scim')->name('scim.')->group(function () {
    Route::get('ServiceProviderConfig', [ScimUserController::class, 'serviceProviderConfig'])->name('config');
    Route::get('ResourceTypes', [ScimUserController::class, 'resourceTypes'])->name('resource-types');
    Route::get('Users', [ScimUserController::class, 'index'])->name('users.index');
    Route::post('Users', [ScimUserController::class, 'store'])->name('users.store');
    Route::get('Users/{id}', [ScimUserController::class, 'show'])->name('users.show');
    Route::put('Users/{id}', [ScimUserController::class, 'replace'])->name('users.replace');
    Route::patch('Users/{id}', [ScimUserController::class, 'patch'])->name('users.patch');
    Route::delete('Users/{id}', [ScimUserController::class, 'destroy'])->name('users.destroy');
});
