<?php

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\MyHr;
use App\Support\Storage\StagedUpload;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| QA security: a file field's value is a path the browser can set ($wire.set('mountedActions.0.data.file', {x: 'any/path'})).
| Only a file the person uploaded in that form (or the one the record already holds) may reach the code that copies,
| serves or deletes it — never another organisation's report, another person's attachment or any other stored file.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = provisionTenant('Alpha');
    actAsTenant($this->tenant);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->employee = forceLifecycle(employeeWithUser(null, ['document.own', 'employee.self']), LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $this->actingAs($this->employee->user);
    $this->passport = DocumentType::query()->where('code', 'PASSPORT')->firstOrFail();
    $this->passport->update(['employee_can_upload' => true]);
    // Someone else's file on the same disk: another organisation's payroll export.
    $this->victim = 'tenants/999/reports/payroll-register-20261101.csv';
    Storage::disk('local')->put($this->victim, "employee,net\nEMP1,100000\n");
});

it('refuses a typed-in path in place of an upload, leaving the target untouched and uncopied', function () {
    Livewire::test(MyHr::class, ['tab' => 'documents'])
        ->mountAction('uploadDocument')
        ->set('mountedActions.0.data.document_type_id', $this->passport->id)
        ->set('mountedActions.0.data.expires_on', '2030-01-01')
        ->set('mountedActions.0.data.file', ['tampered' => $this->victim])
        ->callMountedAction();

    expect(Storage::disk('local')->exists($this->victim))->toBeTrue()
        ->and(EmployeeDocument::query()->where('employee_id', $this->employee->id)->count())->toBe(0);
});

it('still accepts a real upload through the same form', function () {
    Livewire::test(MyHr::class, ['tab' => 'documents'])
        ->callAction('uploadDocument', ['document_type_id' => $this->passport->id, 'expires_on' => '2030-01-01', 'file' => UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 passport')])
        ->assertHasNoActionErrors();

    expect(EmployeeDocument::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($this->victim))->toBeTrue();
});

it('switches the path check on for every file field of the panel', function () {
    expect(FileUpload::make('any')->shouldPreventFilePathTampering())->toBeTrue();
});

it('never reads or deletes a staged path outside the staging folder', function () {
    expect(fn () => StagedUpload::toUploadedFile($this->victim))->toThrow(RuntimeException::class)
        ->and(fn () => StagedUpload::discard($this->victim))->toThrow(RuntimeException::class)
        ->and(fn () => StagedUpload::toUploadedFile('tmp-uploads/../'.$this->victim))->toThrow(RuntimeException::class)
        ->and(Storage::disk('local')->exists($this->victim))->toBeTrue();

    Storage::disk('local')->put('tmp-uploads/mine.pdf', '%PDF-1.4 mine');
    $copy = StagedUpload::toUploadedFile('tmp-uploads/mine.pdf');
    StagedUpload::discard('tmp-uploads/mine.pdf', $copy);
    expect(Storage::disk('local')->exists('tmp-uploads/mine.pdf'))->toBeFalse();
});
