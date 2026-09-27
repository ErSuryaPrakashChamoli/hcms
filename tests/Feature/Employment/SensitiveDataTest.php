<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->payroll = tenantUser($this->tenant, ['employee.view', 'employee.sensitive.*']);
    $this->actingAs($this->payroll);
    $this->employee = Employee::factory()->create();
});

it('encrypts identifiers at rest and masks them in the audit trail', function () {
    $detail = EmployeeStatutoryDetail::create(['employee_id' => $this->employee->id, 'pan' => 'ABCDE1234F', 'uan' => '100200300400']);
    $account = EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'R Sharma', 'bank_name' => 'HDFC', 'account_number' => '50100123456789', 'ifsc' => 'HDFC0001234', 'is_primary' => true]);

    $rawPan = DB::table('employee_statutory_details')->where('id', $detail->id)->value('pan');
    $rawAccount = DB::table('employee_bank_accounts')->where('id', $account->id)->value('account_number');

    expect($rawPan)->not->toBe('ABCDE1234F')
        ->and($detail->fresh()->pan)->toBe('ABCDE1234F')
        ->and($rawAccount)->not->toContain('50100123456789')
        ->and($account->account_number_last4)->toBe('6789')
        ->and($account->maskedAccountNumber())->toBe('••••6789')
        ->and($account->toArray())->not->toHaveKey('account_number');

    $changes = $detail->auditEvents()->first()->fieldChanges->pluck('after', 'field');
    expect($changes['pan'])->toBe(config('peopleos.audit.mask'))
        ->and($changes['uan'])->toBe(config('peopleos.audit.mask'));

    $bankChanges = $account->auditEvents()->first()->fieldChanges;
    expect($bankChanges->firstWhere('field', 'account_number')->after)->toBe(config('peopleos.audit.mask'))
        ->and($bankChanges->firstWhere('field', 'account_number')->is_sensitive)->toBeTrue()
        ->and($bankChanges->pluck('field')->all())->not->toContain('account_number_last4');
});

it('keeps a single primary bank account per employee', function () {
    $first = EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'R', 'bank_name' => 'A', 'account_number' => '111', 'is_primary' => true]);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'R', 'bank_name' => 'B', 'account_number' => '222', 'is_primary' => true]);

    expect($first->refresh()->is_primary)->toBeFalse()
        ->and($this->employee->bankAccounts()->where('is_primary', true)->count())->toBe(1);
});

it('audits sensitive views once per scope and purpose per request', function () {
    $auditor = app(SensitiveAccessAuditor::class);

    $auditor->recordView($this->employee, 'bank_accounts');
    $auditor->recordView($this->employee, 'bank_accounts');
    $auditor->recordView($this->employee, 'bank_account:1', 'Payroll processing');

    $views = AuditEvent::query()->where('action', 'VIEW')->where('entity_id', (string) $this->employee->id)->get();

    expect($views)->toHaveCount(2)
        ->and($views->last()->reason)->toBe('Payroll processing')
        ->and($views->last()->metadata['scope'])->toBe('bank_account:1')
        ->and($views->last()->actor_id)->toBe($this->payroll->id);
});

it('does not audit views when the tenant disables the feature', function () {
    app(FeatureFlags::class)->set('audit.sensitive_access', false);

    app(SensitiveAccessAuditor::class)->recordView($this->employee, 'statutory');

    expect(AuditEvent::query()->where('action', 'VIEW')->exists())->toBeFalse();
});

it('separates sensitive permissions from ordinary employee access', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'employee.update']);
    $account = EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'R', 'bank_name' => 'A', 'account_number' => '111']);

    expect(Gate::forUser($hr)->allows('viewSensitive', $this->employee))->toBeFalse()
        ->and(Gate::forUser($hr)->allows('view', $account))->toBeFalse()
        ->and(Gate::forUser($hr)->allows('update', $this->employee))->toBeTrue()
        ->and(Gate::forUser($this->payroll)->allows('viewSensitive', $this->employee))->toBeTrue()
        ->and(Gate::forUser($this->payroll)->allows('update', $account))->toBeTrue()
        ->and(Gate::forUser($this->payroll)->allows('update', $this->employee))->toBeFalse();
});
