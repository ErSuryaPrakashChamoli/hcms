<?php

use App\Http\Controllers\Api\V1\AttendancePunchController;
use App\Http\Controllers\Api\V1\BgvCallbackController;
use App\Http\Controllers\Api\V1\EmployeeController;
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
    Route::get('attendance/records', [ReadController::class, 'attendance'])->middleware('api.key:attendance.read')->name('attendance.records');
    Route::get('leave/requests', [ReadController::class, 'leaveRequests'])->middleware('api.key:leave.read')->name('leave.requests');
    Route::get('leave/balances', [ReadController::class, 'leaveBalances'])->middleware('api.key:leave.read')->name('leave.balances');
    Route::get('payroll/runs', [ReadController::class, 'payrollRuns'])->middleware('api.key:payroll.read')->name('payroll.runs');
    Route::get('payroll/payslips', [ReadController::class, 'payslips'])->middleware('api.key:payroll.read')->name('payroll.payslips');
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
