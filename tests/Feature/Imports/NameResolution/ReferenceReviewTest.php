<?php

use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Resolution\MasterResolver;
use App\Domain\Organisation\Resolution\Models\MasterReferenceMapping;
use App\Domain\Organisation\Resolution\Resolution;
use App\Domain\Organisation\Resolution\ResolutionReport;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Livewire\Resolution\ReferenceReview;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| The review of an upload's references (docs/architecture/name-resolution.md): the table, and the three things a person
| may do about a reference that did not resolve (take a suggestion, pick a record, create the missing one), each
| re-checked by the service.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);

    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->hrMk = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id]);
    $this->hrFe = Department::factory()->create(['name' => 'Human Resources', 'code' => 'FE-HR', 'company_id' => $this->fe->id]);

    $session = app(MasterResolver::class)->session($this->admin, 'employee_imports:5');
    $session->resolve('department', 'Human Resources', companyId: $this->mk->id, sheet: 'Employees', row: 2, column: 'Department');
    $session->resolve('department', 'Human Resource', companyId: $this->mk->id, sheet: 'Employees', row: 3, column: 'Department');
    $session->resolve('department', 'Unknown Department', companyId: $this->mk->id, sheet: 'Employees', row: 4, column: 'Department');
    $this->report = $session->report();
    $this->key = fn (ResolutionStatus $status) => $this->report->entries($status)[0]['key'];
    $this->review = fn () => Livewire::test(ReferenceReview::class, ['report' => $this->report->toArray(), 'scope' => 'employee_imports:5']);
});

it('lists what needs attention, with the record, the company and the status', function () {
    ($this->review)()
        ->assertSee('Human Resource')->assertSee('Unknown Department')->assertSee('Markedge Technologies')
        ->assertSee('Suggested match')->assertSee('Unmatched')->assertSee('Use Human Resources (HR)')
        ->assertSee('Create Unknown Department')
        ->assertDontSee('FE-HR') // FynnEdge's namesake is not offered to a Markedge row
        ->set('all', true)->assertSee('Matched');
});

it('takes a suggestion as a mapping for this upload and tells the page to re-check', function () {
    ($this->review)()
        ->call('useRecord', ($this->key)(ResolutionStatus::Suggested), $this->hrMk->id)
        ->assertDispatched('references-resolved', scope: 'employee_imports:5')
        ->assertSee('by your choice');

    expect(MasterReferenceMapping::query()->where('value', 'Human Resource')->where('scope', 'employee_imports:5')->value('record_id'))->toBe($this->hrMk->id)
        ->and(app(MasterResolver::class)->session($this->admin, 'employee_imports:5')->resolve('department', 'Human Resource', companyId: $this->mk->id))
        ->id->toBe($this->hrMk->id)->via->toBe(Resolution::VIA_MAPPING);
});

it('picks a record from the list and can remember it', function () {
    $key = ($this->key)(ResolutionStatus::Unmatched);
    ($this->review)()->set("choice.{$key}", $this->hrMk->id)->set("remember.{$key}", true)->call('useRecord', $key);

    expect(MasterReferenceMapping::query()->where('value', 'Unknown Department')->first())
        ->record_id->toBe($this->hrMk->id)->scope->toBeNull()->company_id->toBe($this->mk->id);
});

it('creates the missing master in the row\'s company', function () {
    ($this->review)()->call('createMissing', ($this->key)(ResolutionStatus::Unmatched))->assertDispatched('references-resolved');

    expect(Department::query()->where('name', 'Unknown Department')->first())->company_id->toBe($this->mk->id)->code->toBe('UNKNOWN_DEPARTMENT');
});

it('shows a refusal instead of acting, and hides what the person may not do', function () {
    $viewer = tenantUser($this->tenant, ['organisation.view']);
    app(AccessScopes::class)->assign($viewer, ['company' => [$this->fe->id]], 'FynnEdge only');
    $this->actingAs($viewer->fresh());

    ($this->review)()
        ->assertDontSee('Create Unknown Department')->assertDontSee('Remember for later uploads')
        ->call('useRecord', ($this->key)(ResolutionStatus::Suggested), $this->hrMk->id)
        ->assertNotDispatched('references-resolved')
        ->assertNotified('Not mapped');
    ($this->review)()->call('createMissing', ($this->key)(ResolutionStatus::Unmatched))->assertNotDispatched('references-resolved');

    expect(MasterReferenceMapping::query()->count())->toBe(0)->and(Department::query()->where('name', 'Unknown Department')->exists())->toBeFalse();
});

it('does not let the browser change the report or the upload', function () {
    expect(fn () => ($this->review)()->set('scope', 'employee_imports:99'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => ($this->review)()->set('report', []))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps a large file\'s review small: what needs attention first, fifty rows at a time, the rest counted', function () {
    $entry = fn (string $value, ResolutionStatus $status) => [
        'key' => sha1($value), 'master' => 'department', 'master_label' => 'Department', 'company_id' => $this->mk->id, 'company' => 'Markedge Technologies',
        'value' => $value, 'name' => null, 'code' => null, 'supplied' => $value, 'status' => $status->value, 'id' => $status->ok() ? $this->hrMk->id : null,
        'via' => $status->ok() ? 'code' : null, 'record' => $status->ok() ? 'Human Resources (HR)' : null, 'message' => $status->ok() ? null : "Department '{$value}' does not exist.",
        'warnings' => [], 'candidates' => [], 'count' => 2, 'at' => array_map(fn (int $r) => ['sheet' => 'Employees', 'row' => $r, 'column' => 'Department'], range(2, 21)),
    ];
    $entries = [...array_map(fn (int $i) => $entry("Missing {$i}", ResolutionStatus::Unmatched), range(1, 600)),
        ...array_map(fn (int $i) => $entry("Code {$i}", ResolutionStatus::Matched), range(1, 250))];
    $report = ResolutionReport::fromArray(['scope' => 'employee_imports:8', 'entries' => $entries]);

    $review = $report->forReview()->toArray();
    expect($review['entries'])->toHaveCount(ResolutionReport::REVIEW_ATTENTION + ResolutionReport::REVIEW_MATCHED)
        ->and($review['entries'][0]['at'])->toHaveCount(ResolutionReport::REVIEW_OCCURRENCES)
        ->and($review['hidden'])->toBe(['attention' => 100, 'attention_count' => 200, 'matched' => 50, 'matched_count' => 100])
        ->and(ResolutionReport::fromArray($review)->toArray())->toBe($review);

    $page = Livewire::test(ReferenceReview::class, ['report' => $report->toArray(), 'scope' => 'employee_imports:8'])
        ->assertSee('Missing 1')->assertDontSee('Missing 600')->assertViewHas('entries', fn (array $rows) => count($rows) === ReferenceReview::PAGE)
        ->assertSeeInOrder(['1200', 'Need attention'])->assertSeeInOrder(['500', 'Matched'])
        ->assertSee('Show 50 more (450 not shown yet)')
        ->assertSee('100 more names need attention but are not listed');
    $page->call('showMore')->assertViewHas('entries', fn (array $rows) => count($rows) === 2 * ReferenceReview::PAGE)->assertSee('Show 50 more (400 not shown yet)')
        ->set('all', true)->assertSee('50 more names matched one record each');

    expect(fn () => $page->set('shown', 100000))->toThrow(CannotUpdateLockedPropertyException::class);
});
