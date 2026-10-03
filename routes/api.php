<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendancePunchController;
use App\Http\Controllers\Api\V1\BgvCallbackController;
use App\Http\Controllers\Api\V1\CareerController;
use App\Http\Controllers\Api\V1\CommunicationController;
use App\Http\Controllers\Api\V1\CompensationController;
use App\Http\Controllers\Api\V1\ComplianceController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\LearningController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\OrganisationController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\PreEmployeeController;
use App\Http\Controllers\Api\V1\ReadController;
use App\Http\Controllers\Api\V1\ServiceDeskController;
use App\Http\Controllers\Api\V1\SuccessionController;
use App\Http\Controllers\Api\V1\TalentController;
use App\Http\Controllers\Api\V1\WorkforceController;
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
    Route::get('performance/goals', [PerformanceController::class, 'goals'])->middleware('api.key:performance.read')->name('performance.goals');
    // Phase 8 learning API: field-filtered; costs need the learning.costs scope; see LearningController.
    Route::middleware('api.key:learning.read')->prefix('learning')->name('learning.')->group(function () {
        Route::get('catalogue', [LearningController::class, 'catalogue'])->name('catalogue');
        Route::get('courses', [LearningController::class, 'courses'])->name('courses');
        Route::get('courses/{code}/versions', [LearningController::class, 'courseVersions'])->name('courses.versions');
        Route::get('learning-paths', [LearningController::class, 'paths'])->name('paths');
        Route::get('programs', [LearningController::class, 'programs'])->name('programs');
        Route::get('enrolments', [LearningController::class, 'enrolments'])->name('enrolments');
        Route::get('assignments', [LearningController::class, 'assignments'])->name('assignments');
        Route::get('completions', [LearningController::class, 'completions'])->name('completions');
        Route::get('certificates', [LearningController::class, 'certificates'])->name('certificates');
        Route::get('skills', [LearningController::class, 'skills'])->name('skills');
        Route::get('employee-skills', [LearningController::class, 'employeeSkills'])->name('employee-skills');
        Route::get('assessments', [LearningController::class, 'assessments'])->name('assessments');
        Route::get('development-plans', [LearningController::class, 'developmentPlans'])->name('development-plans');
        Route::get('analytics', [LearningController::class, 'analytics'])->name('analytics');
    });
    Route::middleware('api.key:learning.write')->prefix('learning')->name('learning.')->group(function () {
        Route::post('enrolments', [LearningController::class, 'enrol'])->name('enrolments.store');
        Route::post('enrolments/{enrolment}/progress', [LearningController::class, 'progress'])->whereNumber('enrolment')->name('enrolments.progress');
    });

    // Phase 9 career, talent and succession APIs: separate scopes, field-filtered, no confidential fields.
    Route::middleware('api.key:career.read')->prefix('career')->name('career.')->group(function () {
        Route::get('tracks', [CareerController::class, 'tracks'])->name('tracks');
        Route::get('paths', [CareerController::class, 'paths'])->name('paths');
        Route::get('role-requirements', [CareerController::class, 'requirements'])->name('requirements');
        Route::get('profiles', [CareerController::class, 'profiles'])->name('profiles');
        Route::get('goals', [CareerController::class, 'goals'])->name('goals');
        Route::get('mobility-interests', [CareerController::class, 'mobility'])->name('mobility');
        Route::get('gaps', [CareerController::class, 'gaps'])->name('gaps');
        Route::get('movements', [CareerController::class, 'movements'])->name('movements');
    });
    Route::middleware('api.key:talent.read')->prefix('talent')->name('talent.')->group(function () {
        Route::get('pools', [TalentController::class, 'pools'])->name('pools');
        Route::get('memberships', [TalentController::class, 'memberships'])->name('memberships');
        Route::get('reviews', [TalentController::class, 'reviews'])->name('reviews');
        Route::get('reviews/{review}', [TalentController::class, 'review'])->whereNumber('review')->name('reviews.show');
        Route::get('analytics', [TalentController::class, 'analytics'])->name('analytics');
    });
    Route::middleware('api.key:succession.read')->prefix('succession')->name('succession.')->group(function () {
        Route::get('critical-positions', [SuccessionController::class, 'positions'])->name('positions');
        Route::get('critical-positions/{position}', [SuccessionController::class, 'position'])->whereNumber('position')->name('positions.show');
        Route::get('plans', [SuccessionController::class, 'plans'])->name('plans');
        Route::get('plans/{plan}', [SuccessionController::class, 'plan'])->whereNumber('plan')->name('plans.show');
        Route::get('successors', [SuccessionController::class, 'successors'])->name('successors');
        Route::get('readiness', [SuccessionController::class, 'readiness'])->name('readiness');
        Route::get('analytics', [SuccessionController::class, 'analytics'])->name('analytics');
    });

    // Phase 10 positions and workforce APIs: read-only, as of ?on=, positions by code, costs with workforce.costs.
    Route::middleware('api.key:positions.read')->prefix('positions')->name('positions.')->group(function () {
        Route::get('/', [PositionController::class, 'index'])->name('index');
        Route::get('vacancies', [PositionController::class, 'vacancies'])->name('vacancies');
        Route::get('{code}', [PositionController::class, 'show'])->name('show');
        Route::get('{code}/occupancy', [PositionController::class, 'occupancy'])->name('occupancy');
    });
    Route::middleware('api.key:workforce.read')->prefix('workforce')->name('workforce.')->group(function () {
        Route::get('plans', [WorkforceController::class, 'plans'])->name('plans');
        Route::get('plans/{code}/versions/{version}', [WorkforceController::class, 'version'])->whereNumber('version')->name('plans.version');
        Route::get('scenarios', [WorkforceController::class, 'scenarios'])->name('scenarios');
        Route::get('headcount', [WorkforceController::class, 'headcount'])->name('headcount');
        Route::get('snapshot', [WorkforceController::class, 'snapshot'])->name('snapshot');
    });

    // Phase 12 HR service desk API: read-only; never restricted cases, internal notes, sensitive fields or attachments.
    Route::middleware('api.key:servicedesk.read')->prefix('service-desk')->name('service-desk.')->group(function () {
        Route::get('services', [ServiceDeskController::class, 'services'])->name('services');
        Route::get('requests', [ServiceDeskController::class, 'requests'])->name('requests');
        Route::get('requests/{number}', [ServiceDeskController::class, 'request'])->name('requests.show');
        Route::get('requests/{number}/comments', [ServiceDeskController::class, 'comments'])->name('requests.comments');
        Route::get('knowledge', [ServiceDeskController::class, 'knowledge'])->name('knowledge');
        Route::get('tasks', [ServiceDeskController::class, 'tasks'])->name('tasks');
    });

    // Phase 14 Integration Hub: signed, idempotent inbound events (processed asynchronously) and
    // lookups of an integration's own external references. Systems of other tenants are 404.
    Route::post('integrations/{system}/events', [IntegrationController::class, 'receive'])->middleware('api.key:integrations.write')->name('integrations.events.store');
    Route::middleware('api.key:integrations.read')->prefix('integrations')->name('integrations.')->group(function () {
        Route::get('{system}/events/{eventId}', [IntegrationController::class, 'event'])->name('events.show');
        Route::get('{system}/references', [IntegrationController::class, 'reference'])->name('references.show');
    });

    // Phase 13 engagement API: read-only. Definitions, aggregate participation and overall results under
    // the application's privacy rules; never responses, comments or anyone's anonymous participation.
    Route::middleware('api.key:engagement.read')->prefix('engagement')->name('engagement.')->group(function () {
        Route::get('surveys', [EngagementController::class, 'surveys'])->name('surveys');
        Route::get('surveys/{code}', [EngagementController::class, 'survey'])->name('surveys.show');
        Route::get('surveys/{code}/participation', [EngagementController::class, 'participation'])->name('surveys.participation');
        Route::get('surveys/{code}/versions/{version}/results', [EngagementController::class, 'results'])->whereNumber('version')->name('surveys.results');
        Route::get('my-surveys', [EngagementController::class, 'mySurveys'])->name('my-surveys');
    });

    // Phase 13 communications API: read-only; published items with aggregate counts and preferences.
    Route::middleware('api.key:communications.read')->prefix('communications')->name('communications.')->group(function () {
        Route::get('/', [CommunicationController::class, 'index'])->name('index');
        Route::get('preferences', [CommunicationController::class, 'preferences'])->name('preferences');
        Route::get('{communication}', [CommunicationController::class, 'show'])->whereNumber('communication')->name('show');
    });

    // Phase 11 compensation API: read-only. Definitions with compensation.read; employee amounts also
    // need compensation.sensitive (audited). No writes: changes need the approval chain in PeopleOS.
    Route::middleware('api.key:compensation.read')->prefix('compensation')->name('compensation.')->group(function () {
        Route::get('structures', [CompensationController::class, 'structures'])->name('structures');
        Route::get('grades', [CompensationController::class, 'grades'])->name('grades');
        Route::get('ranges', [CompensationController::class, 'ranges'])->name('ranges');
        Route::get('cycles', [CompensationController::class, 'cycles'])->name('cycles');
        Route::get('employees/{code}', [CompensationController::class, 'employee'])->name('employee');
        Route::get('employees/{code}/history', [CompensationController::class, 'history'])->name('employee.history');
    });

    // Phase 7 performance API: metadata and finalized outcomes only; see PerformanceController.
    Route::middleware('api.key:performance.read')->prefix('performance')->name('performance.')->group(function () {
        Route::get('cycles', [PerformanceController::class, 'cycles'])->name('cycles');
        Route::get('goals/{goal}/progress', [PerformanceController::class, 'goalProgress'])->whereNumber('goal')->name('goals.progress');
        Route::get('reviews', [PerformanceController::class, 'reviews'])->name('reviews');
        Route::get('check-ins', [PerformanceController::class, 'checkIns'])->name('check-ins');
        Route::get('one-on-ones', [PerformanceController::class, 'oneOnOnes'])->name('one-on-ones');
        Route::get('feedback', [PerformanceController::class, 'feedback'])->name('feedback');
        Route::get('competencies', [PerformanceController::class, 'competencies'])->name('competencies');
        Route::get('pips', [PerformanceController::class, 'pips'])->name('pips');
        Route::get('analytics', [PerformanceController::class, 'analytics'])->name('analytics');
    });
    Route::post('performance/goals/{goal}/progress', [PerformanceController::class, 'recordGoalProgress'])->whereNumber('goal')->middleware('api.key:performance.write')->name('performance.goals.progress.store');
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
