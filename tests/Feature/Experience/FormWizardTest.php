<?php

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\Shift;
use App\Filament\Pages\SecurityPolicyPage;
use App\Filament\Resources\AttendanceDevices\Pages\ManageAttendanceDevices;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\ExchangeRates\Pages\ManageExchangeRates;
use App\Filament\Resources\Shifts\Pages\CreateShift;
use App\Filament\Resources\Shifts\Pages\EditShift;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Support\Actions\PeopleCreateAction;
use App\Filament\Support\Actions\PeopleEditAction;
use App\Filament\Support\Forms\PeopleWizard;
use App\Filament\Support\FormWizard;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| Record forms are followed as wizards (FormWizard): every record page, and every drawer whose form has sections,
| goes section by section and ends in Review, on the same fields, validation and save path as before.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    Filament::getPanel('admin')->boot(); // as a request does: the panel's PeopleOS defaults (drawers, wizard actions)
});

/** The page's (or mounted drawer's) wizard. */
function formWizard(Testable $page, bool $drawer = false): Wizard
{
    $schema = $drawer ? $page->instance()->getSchema($page->instance()->getMountedActionSchemaName()) : $page->instance()->form;

    return $schema->getComponents()[0];
}

/** Fields a person fills in: at the top and inside layout (a repeater counts once), not the reason for change. */
function countFields(array $components): int
{
    return collect($components)->sum(fn (mixed $c) => match (true) {
        $c instanceof Field => $c->getName() === 'audit_reason' || $c instanceof Hidden ? 0 : 1,
        $c instanceof Component => countFields((array) $c->getDefaultChildComponents()),
        default => 0,
    });
}

/** @return list<string> */
function stepLabels(Wizard $wizard): array
{
    return array_values(array_map(fn (Step $step) => $step->getLabel(), $wizard->getChildSchema()->getComponents()));
}

it('turns a record page into a wizard, one section per step, ending in Review', function () {
    $page = Livewire::test(CreateShift::class);

    expect($page->instance()->isFormWizard())->toBeTrue()
        ->and(formWizard($page))->toBeInstanceOf(PeopleWizard::class)
        ->and(stepLabels(formWizard($page)))->toBe(['Shift', 'Hours and thresholds', 'Absence criteria', 'Advanced', 'Breaks', 'Overtime', 'Effective dates', 'Review'])
        ->and(formWizard($page)->isSkippable())->toBeFalse(); // creating goes in order

    $this->get(ShiftResource::getUrl('create'))->assertOk()
        ->assertSee('pos-wizard pos-wizard-page', false)
        ->assertSee("posFormReview('create')", false)
        ->assertSee('Create shift')
        ->assertSee('Go step by step');
});

it('checks each step on Next and still creates through the page', function () {
    $page = Livewire::test(CreateShift::class);
    $wizard = formWizard($page);

    $page->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', ['currentStepIndex' => 0])
        ->assertHasFormErrors(['name' => 'required', 'code' => 'required']);

    $page->fillForm(['name' => 'Night', 'code' => 'NIGHT', 'type' => 'fixed', 'start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true])
        ->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', ['currentStepIndex' => 0])
        ->assertHasNoFormErrors()
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Shift::query()->where('code', 'NIGHT')->first())
        ->name->toBe('Night')
        ->full_day_minutes->toBe(480);
});

it('lets an edit open any step, checks the step on Next, keeps the reason for change on Review and saves', function () {
    $shift = generalShift();
    $page = Livewire::test(EditShift::class, ['record' => $shift->getKey()]);
    $wizard = formWizard($page);
    $review = collect($wizard->getChildSchema()->getComponents())->last();

    expect($wizard->isSkippable())->toBeTrue()
        ->and($review->getLabel())->toBe('Review')
        ->and(collect($review->getChildSchema()->getFlatFields())->keys()->all())->toContain('audit_reason');

    $page->fillForm(['name' => ''])
        ->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', ['currentStepIndex' => 0])
        ->assertHasFormErrors(['name' => 'required']);

    $page->fillForm(['name' => 'General (day)', 'grace_in_minutes' => 10, 'audit_reason' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($shift->refresh())->name->toBe('General (day)')->grace_in_minutes->toBe(10);
});

it('keeps a page that builds its own wizard (the hire wizard) as it is', function () {
    $page = Livewire::test(CreateEmployee::class);

    expect($page->instance()->isFormWizard())->toBeFalse()
        ->and(formWizard($page))->not->toBeInstanceOf(PeopleWizard::class)
        ->and(stepLabels(formWizard($page)))->toContain('Person', 'Employment');
});

it('turns a drawer with sections into a wizard and leaves a short one as a single screen', function () {
    $page = Livewire::test(ManageAttendanceDevices::class)->mountAction('create');
    $action = $page->instance()->getMountedAction();

    expect($action)->toBeInstanceOf(PeopleCreateAction::class)
        ->and($action->isWizard())->toBeTrue()
        ->and(stepLabels(formWizard($page, drawer: true)))->toBe(['Attendance device', 'Sync server', 'Alert when nothing arrives', 'Review']);

    $short = Livewire::test(ManageExchangeRates::class)->mountAction('create');
    expect($short->instance()->getMountedAction()->isWizard())->toBeFalse();
});

it('creates and edits through a drawer wizard as before', function () {
    Livewire::test(ManageAttendanceDevices::class)
        ->callAction(CreateAction::class, ['name' => 'Front gate', 'code' => 'FRONT', 'adapter' => 'generic', 'status' => 'active'])
        ->assertHasNoActionErrors();
    $device = AttendanceDevice::query()->where('code', 'FRONT')->firstOrFail();

    $page = Livewire::test(ManageAttendanceDevices::class)->mountTableAction(EditAction::class, $device);
    expect($page->instance()->getMountedAction())->toBeInstanceOf(PeopleEditAction::class)
        ->and($page->instance()->getMountedAction()->isWizard())->toBeTrue();

    Livewire::test(ManageAttendanceDevices::class)
        ->callTableAction(EditAction::class, $device, ['name' => 'Front gate (north)', 'audit_reason' => 'Moved'])
        ->assertHasNoTableActionErrors();
    expect($device->refresh()->name)->toBe('Front gate (north)');
});

it('gives the hire wizard the same step rail as the other record wizards', function () {
    expect(formWizard(Livewire::test(CreateEmployee::class))->getExtraAttributes()['class'] ?? '')->toContain('pos-wizard-page');
});

it('follows the security policy as a wizard, saved from Review', function () {
    $page = Livewire::test(SecurityPolicyPage::class);

    expect(stepLabels(formWizard($page)))->toBe(['Access', 'Passwords', 'Disable login', 'Retention', 'Data warehouse feed', 'Review'])
        ->and(formWizard($page)->isSkippable())->toBeTrue();

    $page->fillForm(['security__session_idle_minutes' => 40])->call('save')->assertNotified('Security policy saved');
});

it('keeps long drawer forms in sections, so they open as wizards', function () {
    // A drawer form with this many fields and fewer than two sections is one long screen again.
    $offenders = [];
    foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
        $resource = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');
        if (! class_exists($resource) || array_intersect(['create', 'edit'], array_keys($resource::getPages())) !== []) {
            continue;
        }
        $form = $resource::form(Schema::make());
        $fields = countFields($form->getComponents(withHidden: true));
        if ($fields >= 8 && FormWizard::contentSteps($form) < FormWizard::DRAWER_MINIMUM) {
            $offenders[] = class_basename($resource)." ({$fields} fields)";
        }
    }

    expect($offenders)->toBe([]);
});
