<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseDocumentDownload;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseDocumentDownloads;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Expense\Support\ExpenseReportSummary;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExpenseReceipts\Pages\ViewExpenseReceipt;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\ExpenseReports\Pages\ListExpenseReports;
use App\Filament\Resources\ExpenseReports\Pages\ViewExpenseReport;
use App\Filament\Resources\ExpenseReports\RelationManagers\LinesRelationManager;
use App\Filament\Resources\ExpenseReports\RelationManagers\PartsRelationManager;
use App\Filament\Support\ExpenseReview;
use App\Http\Controllers\ExpenseReportPrintController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| HROne parity XO-3 (matrix 26 §26.4.2, §26.4.3): the expense report review — trips, distance totals, the reimbursable /
| non-reimbursable / rejected split, the column picker, next / previous receipt, every file, the audit tab, a printable
| summary — the register's receipt-number search, payment / period / created filters and bulk "Download documents" (a
| queued zip, audited per file, for the person who asked), and the approver's "Suspected" decision behind
| expense.review.suspect_button (owner decision D-XO7 / EXP-13: held out at 0, cleared later; EXP-1 kept).
*/

beforeEach(function () {
    $this->travelTo('2026-06-10 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'North']);
    $this->south = Company::factory()->create(['name' => 'South']);
    [$this->managerUser, $this->manager] = xo3Person($this->tenant, $this->company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = xo3Person($this->tenant, $this->company, ['expense.claim'], $this->manager);
    [$this->colleagueUser, $this->colleague] = xo3Person($this->tenant, $this->south, ['expense.claim'], $this->manager);
    [$this->payerUser] = xo3Person($this->tenant, $this->company, ['expense.pay', 'expense.claim']);
    $categories = app(ExpenseCategories::class);
    $this->food = $categories->save(null, ['type' => 'amount', 'name' => 'Food', 'code' => 'FOOD']);
    $this->stay = $categories->save(null, ['type' => 'amount', 'name' => 'Stay', 'code' => 'STAY', 'reimbursable' => false]);
    $this->cab = $categories->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    $this->car = $categories->save(null, ['type' => 'mileage', 'name' => 'Car', 'code' => 'CAR', 'rate_per_km' => 10, 'location_input' => 'text']);
    $this->hotel = $categories->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booked_by' => 'employee']);
    xo3Policy('expense', ['categories' => array_map(fn (ExpenseCategory $c) => ['category' => $c->id], [$this->food, $this->stay, $this->cab, $this->car, $this->hotel])]);
    xo3Policy('expense_advance', ['enabled' => true, 'max_open' => 5]);
    $this->receipts = app(ExpenseReceipts::class);
    $this->reports = app(ExpenseReports::class);
});

/** @return array{0: User, 1: Employee} */
function xo3Person(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $employee];
}

function xo3Policy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'XO3_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Everyone', 'conditions' => [], 'priority' => 1000]);
}

function xo3Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function xo3File(string $content, string $name = 'bill.pdf'): string
{
    $path = ExpenseReceipts::directory().'/'.uniqid().'-'.$name;
    Storage::disk('local')->put($path, $content);

    return $path;
}

function xo3Expense(Employee $employee, User $as, ExpenseCategory $category, string $date, float $amount, array $extra = []): ExpenseReceipt
{
    $data = $category->type === ExpenseCategory::MILEAGE
        ? ['distance_km' => $amount, 'from_place' => 'Office', 'to_place' => 'Plant']
        : ['amount' => $amount];

    return app(ExpenseReceipts::class)->capture($employee, ['expense_category_id' => $category->id, 'expense_date' => $date] + $data + $extra, $as);
}

/** A submitted report of these expenses, filed by the employee. */
function xo3Submitted(Employee $employee, User $as, array $receipts, string $title = 'June'): ExpenseReport
{
    $reports = app(ExpenseReports::class);

    return $reports->submit($reports->create($employee, $title, array_map(fn (ExpenseReceipt $r) => $r->id, $receipts), $as), $as);
}

/** @return array<string, string> entry => content */
function xo3Zip(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive;
    $zip->open($tmp);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $parts;
}

// ---- Defaults ---------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: no Suspected decision, the flag ignored, the employee\'s own screens as before', function () {
    expect(config('peopleos.settings')['expense.review.suspect_button'])->toBeFalse()
        ->and(collect(config('peopleos_setup.modules.expense.settings'))->pluck('key')->all())->toContain('expense.review.suspect_button')
        ->and($this->reports->suspicionOffered())->toBeFalse();

    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300);
    $report = xo3Submitted($this->employee, $this->user, [$food, $cab]);
    $part = $report->parts()->sole();

    // The screen does not offer "Suspected": a line set to 0 is rejected, exactly as before.
    $this->actingAs($this->managerUser);
    Livewire::test(PartsRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])
        ->callTableAction('approve', $part, ['lines' => [['id' => $food->id, 'amount' => 800], ['id' => $cab->id, 'amount' => 0, 'note' => 'No bill', 'suspected' => true]], 'note' => 'OK'])
        ->assertHasNoTableActionErrors();
    expect($cab->refresh()->status)->toBe('rejected')->and($cab->suspected_at)->toBeNull()->and($cab->approver_note)->toBe('No bill')
        ->and($part->refresh()->status)->toBe('partly_approved')
        ->and($report->refresh()->status)->toBe('partly_approved')->and((float) $report->net_payable)->toBe(800.0);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertTableActionHidden('clearSuspicion', $cab);

    // The service ignores the flag too while the setting is off: the line is approved as claimed.
    $taxi = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-09', 250);
    $second = xo3Submitted($this->employee, $this->user, [$taxi], 'Taxi');
    $this->reports->approvePart($second->parts()->sole(), [$taxi->id => ['suspected' => true, 'note' => 'Odd']], null, $this->managerUser);
    expect($taxi->refresh()->status)->toBe('approved')->and((float) $taxi->approved_amount)->toBe(250.0)->and($taxi->suspected_at)->toBeNull()
        ->and($second->refresh()->status)->toBe('approved')
        ->and(fn () => $this->reports->clearSuspicion($taxi, 0, 'x', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'not held as suspected');

    // HR's register gains its filters, but not the held one; the employee's own register and lines look as before.
    $this->actingAs($this->hr);
    Livewire::test(ListExpenseReports::class)->assertOk()->assertTableFilterHidden('held')->assertTableFilterVisible('payment')
        ->assertTableFilterVisible('period')->assertTableFilterVisible('created')->assertActionVisible('downloadDocuments');
    $this->actingAs($this->user);
    Livewire::test(ListExpenseReports::class)->assertOk()->assertTableFilterHidden('payment')->assertTableFilterHidden('period')->assertTableFilterHidden('created')
        ->assertTableFilterHidden('held')->assertTableColumnHidden('created_at')->assertTableColumnHidden('payment')
        ->assertActionHidden('downloadDocuments')->assertTableBulkActionHidden('downloadDocuments')->assertActionVisible('newReport');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertTableActionHidden('reviewColumns');
});

// ---- The review -------------------------------------------------------------------------------------------------------

it('adds up the review: trips, distance totals, the reimbursable / non-reimbursable / rejected split and the paid receipts', function () {
    $this->travelTo('2026-06-01 10:00:00');
    $trips = app(TravelRequests::class);
    $trip = $trips->submit($this->employee, ['purpose' => 'Client visit', 'start_date' => '2026-06-05', 'end_date' => '2026-06-07',
        'legs' => [['expense_category_id' => $this->hotel->id, 'to_city' => 'Mumbai', 'to_state' => 'MH', 'starts_on' => '2026-06-05', 'ends_on' => '2026-06-07']]], $this->user);
    $trips->approve($trip, null, $this->managerUser);
    $this->travelTo('2026-06-10 10:00:00');

    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-05', 800);
    $stay = xo3Expense($this->employee, $this->user, $this->stay, '2026-06-06', 1000);
    $car = xo3Expense($this->employee, $this->user, $this->car, '2026-06-06', 50);
    $hotel = xo3Expense($this->employee, $this->user, $this->hotel, '2026-06-06', 2000, ['travel_request_id' => $trip->id]);
    $report = xo3Submitted($this->employee, $this->user, [$food, $stay, $car, $hotel]);
    expect($report->trips()->pluck('travel_requests.id')->all())->toBe([$trip->id])
        ->and(ExpenseReportSummary::of($report))->toMatchArray(['receipts' => 4, 'mileage_lines' => 1, 'distance_requested' => 50.0, 'distance_approved' => 0.0, 'decided' => 0]);

    $this->reports->approvePart($report->parts()->sole(), [$food->id => 600, $car->id => 400], 'Lunch and fuel capped', $this->managerUser);
    $summary = ExpenseReportSummary::of($report->refresh());
    expect($summary)->toMatchArray(['distance_requested' => 50.0, 'distance_approved' => 40.0, 'approved_reimbursable' => 3000.0, 'approved_non_reimbursable' => 1000.0,
        'rejected' => 300.0, 'held' => 0.0, 'held_count' => 0, 'decided' => 4, 'paid_receipts' => 0])
        ->and((float) $report->reimbursable_approved)->toBe(3000.0)->and((float) $report->total_approved)->toBe(4000.0);

    $this->get(ExpenseReportResource::getUrl('view', ['record' => $report]))->assertOk()
        ->assertSee($trip->number)->assertSee('Distance requested (km)')->assertSee('Distance approved (km)')->assertSee('40.00')
        ->assertSee('Approved, repaid to the employee')->assertSee('3,000.00')->assertSee('Approved, paid by the company')->assertSee('Created on');

    $this->reports->markPaid($report, 'UTR-77', '2026-06-10', $this->payerUser);
    expect(ExpenseReportSummary::of($report->refresh())['paid_receipts'])->toBe(4);
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee('Paid receipts');
});

it('prints a report summary for whoever may view the report, audited, whether or not cover letters are on', function () {
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800);
    $report = xo3Submitted($this->employee, $this->user, [$food]);
    $this->reports->approvePart($report->parts()->sole(), null, null, $this->managerUser);
    $this->reports->markPaid($report, 'UTR-9', '2026-06-10', $this->payerUser);
    expect(app(SettingsRepository::class)->get('expense.report.cover_letter'))->toBeFalse();

    Livewire::test(ViewExpenseReport::class, ['record' => $report->getRouteKey()])->assertOk()->assertActionVisible('printSummary')->assertActionHidden('coverLetter');
    $this->get(ExpenseReportPrintController::url($report))->assertOk()->assertSee($report->number)->assertSee($food->number)
        ->assertSee('Approvals')->assertSee('Approved, paid by the company')->assertSee('Paid receipts')->assertSee('UTR-9');
    expect(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $report->id)->where('action', 'EXPORT')->count())->toBe(1);
    $this->get(route('expense.reports.print', ['report' => $report->id]))->assertForbidden();

    // The employee prints their own (payment as the organisation shows it); a colleague cannot.
    $this->actingAs($this->user);
    $this->get(ExpenseReportPrintController::url($report))->assertOk()->assertSee('Paid receipts');
    xo3Setting('expense.payment.show_to_employee', false);
    $this->get(ExpenseReportPrintController::url($report))->assertOk()->assertDontSee('Paid receipts');
    $this->actingAs($this->colleagueUser);
    $this->get(ExpenseReportPrintController::url($report))->assertForbidden();
});

it('keeps each person\'s choice of up to five extra receipt fields for the lines of every report', function () {
    CustomField::query()->create(['entity' => 'expense_receipt', 'key' => 'cost_centre', 'label' => 'Cost centre', 'type' => 'text', 'status' => 'active']);
    expect(ExpenseReview::columnOptions())->toHaveKey('purpose')->toHaveKey('cf:cost_centre')
        ->and(ExpenseReview::chosenColumns($this->managerUser))->toBe([]);
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800, ['purpose' => 'Client lunch', 'invoice_number' => 'INV-9']);
    $food->setCustomFields(['cost_centre' => 'CC-7']);
    $report = xo3Submitted($this->employee, $this->user, [$food]);

    $this->actingAs($this->managerUser);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertTableColumnVisible('type')->assertTableColumnVisible('distance_km')
        ->assertTableColumnHidden('pick_purpose')->assertTableColumnHidden('pick_cf_cost_centre')
        ->callTableAction('reviewColumns', data: ['fields' => ['purpose', 'invoice_number', 'cf:cost_centre']])->assertHasNoTableActionErrors();
    expect(app(ExperiencePreferences::class)->for($this->managerUser)['expense_review_columns'])->toBe(['purpose', 'invoice_number', 'cf:cost_centre']);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertTableColumnVisible('pick_purpose')->assertTableColumnVisible('pick_invoice_number')->assertTableColumnVisible('pick_cf_cost_centre')
        ->assertTableColumnHidden('pick_description')->assertSee('Client lunch')->assertSee('INV-9')->assertSee('CC-7');

    // At most five ("Limit exceeded"), only offered fields, and per person.
    $six = ['purpose', 'invoice_number', 'from_place', 'to_place', 'description', 'trip'];
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])
        ->callTableAction('reviewColumns', data: ['fields' => $six])->assertHasTableActionErrors(['fields']);
    expect(fn () => ExpenseReview::saveColumns($this->managerUser, $six))->toThrow(RuntimeException::class, 'Limit exceeded')
        ->and(fn () => ExpenseReview::saveColumns($this->managerUser, ['salary']))->toThrow(RuntimeException::class, 'cannot be shown')
        ->and(ExpenseReview::chosenColumns($this->managerUser))->toBe(['purpose', 'invoice_number', 'cf:cost_centre'])
        ->and(ExpenseReview::chosenColumns($this->hr))->toBe([])
        ->and(app(ExperiencePreferences::class)->update($this->hr, ['expense_review_columns' => $six])['expense_review_columns'])->toHaveCount(5);
});

it('walks through the receipts of a report on the receipt page, among those the viewer may open', function () {
    $a = xo3Expense($this->employee, $this->user, $this->food, '2026-06-05', 100);
    $b = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-06', 200);
    $c = xo3Expense($this->employee, $this->user, $this->car, '2026-06-07', 30);
    $report = $this->reports->create($this->employee, 'June', [$c->id, $a->id, $b->id], $this->user);
    [$a, $b, $c] = [$a->refresh(), $b->refresh(), $c->refresh()];

    $walk = ExpenseReview::neighbours($b);
    expect($walk['position'])->toBe(2)->and($walk['count'])->toBe(3)->and($walk['previous']?->id)->toBe($a->id)->and($walk['next']?->id)->toBe($c->id)
        ->and(ExpenseReview::neighbours($a)['previous'])->toBeNull()->and(ExpenseReview::neighbours($c)['next'])->toBeNull();
    Livewire::test(ViewExpenseReceipt::class, ['record' => $b->getRouteKey()])->assertOk()->assertSee('Receipt 2 of 3')
        ->assertActionVisible('previousReceipt')->assertActionVisible('nextReceipt')->assertActionVisible('backToReport');
    Livewire::test(ViewExpenseReceipt::class, ['record' => $a->getRouteKey()])->assertOk()->assertActionHidden('previousReceipt')->assertActionVisible('nextReceipt');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertTableActionVisible('open', $a)->filterTable('type', 'mileage')->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);

    // The owner walks their own report; a receipt in no report has nothing to walk.
    $this->actingAs($this->user);
    expect(ExpenseReview::neighbours($b)['count'])->toBe(3);
    $loose = xo3Expense($this->employee, $this->user, $this->food, '2026-06-09', 90);
    Livewire::test(ViewExpenseReceipt::class, ['record' => $loose->getRouteKey()])->assertOk()
        ->assertActionHidden('previousReceipt')->assertActionHidden('nextReceipt')->assertActionHidden('backToReport');
});

it('shows the report\'s own history to audit viewers and every file of the report through the signed route', function () {
    $stay = xo3Expense($this->employee, $this->user, $this->stay, '2026-06-08', 1180, ['attachments' => [xo3File('hotel-bill', 'hotel.pdf')]]);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300, ['attachments' => [xo3File('cab-bill', 'cab.pdf')]]);
    $report = xo3Submitted($this->employee, $this->user, [$stay, $cab]);

    expect(ExpenseReportResource::getRelations())->toContain(AuditHistoryRelationManager::class)
        ->and(AuditHistoryRelationManager::canViewForRecord($report, ViewExpenseReport::class))->toBeTrue();
    Livewire::test(AuditHistoryRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertCanSeeTableRecords(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $report->id)->get());
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee('All files')
        ->assertSee('hotel.pdf')->assertSee('cab.pdf')->assertSee('/files/expense-receipt/'.$stay->id.'/0', false)->assertSee('/files/expense-receipt/'.$cab->id.'/0', false);

    $this->actingAs($this->user);
    expect(AuditHistoryRelationManager::canViewForRecord($report, ViewExpenseReport::class))->toBeFalse();
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee('hotel.pdf');
});

// ---- Register ---------------------------------------------------------------------------------------------------------

it('searches the register by a receipt number and filters it by payment status, period and created date, inside the viewer\'s scope', function () {
    $this->travelTo('2026-06-01 09:00:00');
    $draft = $this->reports->create($this->employee, 'Draft', [], $this->user);
    $this->travelTo('2026-06-10 10:00:00');
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-02', 800);
    $waiting = xo3Submitted($this->employee, $this->user, [$food], 'Waiting');
    $this->reports->approvePart($waiting->parts()->sole(), null, null, $this->managerUser);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300);
    $paid = xo3Submitted($this->employee, $this->user, [$cab], 'Paid');
    $this->reports->approvePart($paid->parts()->sole(), null, null, $this->managerUser);
    $this->reports->markPaid($paid, 'UTR-1', '2026-06-10', $this->payerUser);
    $theirs = xo3Expense($this->colleague, $this->colleagueUser, $this->cab, '2026-06-05', 450);
    $other = xo3Submitted($this->colleague, $this->colleagueUser, [$theirs], 'Theirs');

    $list = fn () => Livewire::test(ListExpenseReports::class);
    $list()->searchTable($cab->number)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$draft, $waiting, $other]);
    $list()->searchTable($paid->number)->assertCanSeeTableRecords([$paid])->assertCountTableRecords(1);
    $list()->filterTable('payment', ['paid_bank'])->assertCanSeeTableRecords([$paid])->assertCountTableRecords(1);
    $list()->filterTable('payment', ['waiting'])->assertCanSeeTableRecords([$waiting])->assertCountTableRecords(1);
    $list()->filterTable('payment', ['paid_payroll', 'scheduled'])->assertCountTableRecords(0);
    $list()->filterTable('payment', ['nothing'])->assertCanSeeTableRecords([$draft, $other])->assertCanNotSeeTableRecords([$waiting, $paid]);
    $list()->filterTable('period', ['from' => '2026-06-04', 'until' => '2026-06-06'])->assertCanSeeTableRecords([$other])->assertCountTableRecords(1);
    $list()->filterTable('created', ['until' => '2026-06-05'])->assertCanSeeTableRecords([$draft])->assertCountTableRecords(1);
    expect($list()->filterTable('payment', ['paid_bank'])->filterTable('period', ['from' => '2026-06-01'])->instance()->registerReportFilters())
        ->toContain(['field' => 'payment_status', 'operator' => 'in', 'value' => 'Paid outside payroll'])
        ->toContain(['field' => 'period_to', 'operator' => 'gte', 'value' => '2026-06-01']);
    $list()->assertTableColumnExists('created_at')->assertTableColumnExists('payment');

    // HR of the North company finds nothing of the South colleague, not even by the receipt number.
    $north = tenantUser($this->tenant, ['expense.view']);
    UserAccessScope::query()->create(['user_id' => $north->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $this->actingAs($north);
    $list()->searchTable($theirs->number)->assertCountTableRecords(0);
    $list()->assertCanSeeTableRecords([$draft, $waiting, $paid])->assertCanNotSeeTableRecords([$other]);

    // The employee's own list searches the report number only, as before.
    $this->actingAs($this->user);
    $list()->searchTable($cab->number)->assertCountTableRecords(0);
    $list()->searchTable($paid->number)->assertCanSeeTableRecords([$paid]);
});

// ---- Download documents -----------------------------------------------------------------------------------------------

it('downloads the documents of chosen reports as one zip: only files the person may open, each audited, for them alone until it expires', function () {
    $stay = xo3Expense($this->employee, $this->user, $this->stay, '2026-06-08', 1180, ['attachments' => [xo3File('hotel-bill', 'hotel.pdf')]]);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300, ['attachments' => [xo3File('cab-bill', 'cab.pdf'), xo3File('toll-bill', 'toll.pdf')]]);
    $report = xo3Submitted($this->employee, $this->user, [$stay, $cab]);
    $theirs = xo3Expense($this->colleague, $this->colleagueUser, $this->cab, '2026-06-08', 450, ['attachments' => [xo3File('their-bill', 'their.pdf')]]);
    $other = xo3Submitted($this->colleague, $this->colleagueUser, [$theirs], 'Theirs');
    // A path outside the tenant's own folder (not something capture accepts) never goes in.
    $foreign = 'tenants/'.($this->tenant->id + 1000).'/expense/receipts/foreign.pdf';
    Storage::disk('local')->put($foreign, 'not ours');
    ExpenseReceipt::query()->whereKey($stay->id)->update(['attachments' => json_encode([...$stay->attachments, $foreign])]);

    $north = tenantUser($this->tenant, ['expense.view']);
    UserAccessScope::query()->create(['user_id' => $north->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $downloads = app(ExpenseDocumentDownloads::class);
    expect(fn () => $downloads->request($this->user, [$report->id]))->toThrow(RuntimeException::class, 'cannot download')
        ->and(fn () => $downloads->request($north, [$report->id], ['cover_letters']))->toThrow(RuntimeException::class, 'switched off')
        ->and(fn () => $downloads->request($north, [$report->id], []))->toThrow(RuntimeException::class, 'Choose the documents')
        ->and(fn () => $downloads->request($north, []))->toThrow(RuntimeException::class, 'at least one');

    $this->actingAs($north);
    $download = $downloads->request($north, [$report->id, $other->id]);
    expect($download->status)->toBe('ready')->and($download->included)->toBe(1)->and($download->skipped)->toBe(1)->and($download->files)->toBe(3)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'ATTACHMENT_DOWNLOADED')->where('metadata->purpose', 'bulk_download')->count())->toBe(3)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'ATTACHMENT_DOWNLOADED')->where('entity_id', $theirs->id)->where('entity_type', ExpenseReceipt::class)->count())->toBe(0);
    $file = $downloads->fetch($download, $north);
    $entries = array_keys(xo3Zip($file['content']));
    expect($entries)->toHaveCount(3)
        ->and(collect($entries)->every(fn (string $name) => str_starts_with($name, $report->number.' ')))->toBeTrue()
        ->and(collect($entries)->contains(fn (string $name) => str_contains($name, 'their') || str_contains($name, 'foreign')))->toBeFalse()
        ->and(collect($entries)->contains(fn (string $name) => str_ends_with($name, $cab->number.' - '.pathinfo($cab->attachments[1], PATHINFO_FILENAME).'.pdf')))->toBeTrue()
        ->and(fn () => $downloads->fetch($download, $this->hr))->toThrow(RuntimeException::class, 'Only the person');

    // Only the person who asked fetches it, through the signed link.
    $this->get(ExpenseDocumentDownloads::url($download))->assertOk();
    $this->get(route('expense.documents.zip', ['download' => $download->id]))->assertForbidden();
    $this->actingAs($this->hr);
    $this->get(ExpenseDocumentDownloads::url($download))->assertForbidden();

    // Cover letters go in while they are switched on (each audited as a download of the report).
    xo3Setting('expense.report.cover_letter', true);
    $this->actingAs($north);
    $withLetters = $downloads->request($north, [$report->id], ['receipts', 'cover_letters']);
    expect($withLetters->files)->toBe(4)
        ->and(collect(array_keys(xo3Zip($downloads->fetch($withLetters, $north)['content'])))->contains(fn (string $name) => str_ends_with($name, '.html')))->toBeTrue()
        ->and(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $report->id)->where('action', 'DOWNLOAD')->count())->toBe(1);

    // Expired zips are removed by the daily expense run; the rows stay.
    $this->travel(25)->hours();
    Artisan::call('peopleos:expense:expire', ['--tenant' => $this->tenant->id]);
    expect($download->refresh()->status)->toBe('expired')->and($download->file_path)->toBeNull()
        ->and(ExpenseDocumentDownload::query()->count())->toBe(2)
        ->and(fn () => $downloads->fetch($download, $north))->toThrow(RuntimeException::class, 'expired');
    $this->get(ExpenseDocumentDownloads::url($withLetters->refresh()))->assertForbidden();
});

it('asks for the zip from the register: the rows chosen, or every report matching a filter', function () {
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800, ['attachments' => [xo3File('lunch-bill', 'lunch.pdf')]]);
    $report = xo3Submitted($this->employee, $this->user, [$food]);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300, ['attachments' => [xo3File('cab-bill', 'cab.pdf')]]);
    $draft = $this->reports->create($this->employee, 'Draft', [$cab->id], $this->user);

    Livewire::test(ListExpenseReports::class)->assertTableBulkActionVisible('downloadDocuments')
        ->callTableBulkAction('downloadDocuments', [$report, $draft], ['documents' => ['receipts']])->assertHasNoTableBulkActionErrors();
    $chosen = ExpenseDocumentDownload::query()->latest('id')->firstOrFail();
    expect($chosen->selection)->toBe('selected')->and($chosen->report_ids)->toEqualCanonicalizing([$report->id, $draft->id])
        ->and($chosen->status)->toBe('ready')->and($chosen->files)->toBe(2)->and($chosen->requested_by)->toBe($this->hr->id);

    // "Download documents" for all matching needs a filter or a search first.
    Livewire::test(ListExpenseReports::class)->callAction('downloadDocuments', ['documents' => ['receipts']]);
    expect(ExpenseDocumentDownload::query()->count())->toBe(1);
    Livewire::test(ListExpenseReports::class)->filterTable('status', ['submitted'])->callAction('downloadDocuments', ['documents' => ['receipts']]);
    $matching = ExpenseDocumentDownload::query()->latest('id')->firstOrFail();
    expect(ExpenseDocumentDownload::query()->count())->toBe(2)->and($matching->selection)->toBe('all_matching')->and($matching->report_ids)->toBe([$report->id]);
});

// ---- Suspected (D-XO7) ------------------------------------------------------------------------------------------------

it('holds a suspected line out with a note, approved at 0 for now, and decides it later with "Clear suspicion"', function () {
    xo3Setting('expense.review.suspect_button', true);
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300);
    $report = xo3Submitted($this->employee, $this->user, [$food, $cab]);
    $part = $report->parts()->sole();
    $plain = xo3Submitted($this->employee, $this->user, [xo3Expense($this->employee, $this->user, $this->food, '2026-06-09', 120)], 'Plain');
    expect(fn () => $this->reports->approvePart($part, [$cab->id => ['suspected' => true]], null, $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'say why the expense is suspected');

    $this->actingAs($this->managerUser);
    Livewire::test(PartsRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])
        ->callTableAction('approve', $part, ['lines' => [['id' => $food->id, 'amount' => 800], ['id' => $cab->id, 'amount' => 300, 'note' => 'Same cab as last week?', 'suspected' => true]], 'note' => 'Food fine'])
        ->assertHasNoTableActionErrors();
    expect($cab->refresh()->status)->toBe('suspected')->and((float) $cab->approved_amount)->toBe(0.0)->and($cab->suspected_by)->toBe($this->managerUser->id)
        ->and($cab->suspicion_note)->toBe('Same cab as last week?')->and($cab->isHeldAsSuspected())->toBeTrue()
        ->and($part->refresh()->status)->toBe('partly_approved')->and((float) $part->total_approved)->toBe(800.0)
        ->and($report->refresh()->status)->toBe('partly_approved')->and((float) $report->total_approved)->toBe(800.0)->and((float) $report->net_payable)->toBe(800.0)
        ->and(ExpenseReportSummary::of($report))->toMatchArray(['held' => 300.0, 'held_count' => 1, 'rejected' => 0.0, 'approved_reimbursable' => 800.0])
        ->and(AuditEvent::query()->where('entity_type', ExpenseReportPart::class)->where('entity_id', $part->id)->where('metadata->held_as_suspected', 1)->count())->toBe(1);
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee('Held as suspected');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->assertSee('Held as suspected: Same cab as last week?');

    // HR finds the report by the held filter.
    $this->actingAs($this->hr);
    Livewire::test(ListExpenseReports::class)->assertTableFilterVisible('held')->filterTable('held')->assertCanSeeTableRecords([$report])->assertCanNotSeeTableRecords([$plain]);

    // The employee cannot clear their own; the approver can.
    $this->actingAs($this->user);
    expect(fn () => $this->reports->clearSuspicion($cab, 300, 'Mine', $this->user))->toThrow(ExpenseRuleViolation::class, 'cannot decide your own');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertTableActionHidden('clearSuspicion', $cab);
    $this->actingAs($this->managerUser);
    expect(fn () => $this->reports->clearSuspicion($cab, 301, 'Too much', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'approve between 0 and the claimable')
        ->and(fn () => $this->reports->clearSuspicion($cab, 100, ' ', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'Say how the suspicion was cleared');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertTableActionVisible('clearSuspicion', $cab)
        ->callTableAction('clearSuspicion', $cab, ['amount' => 250, 'note' => 'Checked with the vendor'])->assertHasNoTableActionErrors();
    expect($cab->refresh()->status)->toBe('partly_approved')->and((float) $cab->approved_amount)->toBe(250.0)->and($cab->approver_note)->toBe('Checked with the vendor')
        ->and($cab->suspicion_cleared_by)->toBe($this->managerUser->id)->and($cab->isHeldAsSuspected())->toBeFalse()->and($cab->suspicion_note)->toBe('Same cab as last week?')
        ->and((float) $part->refresh()->total_approved)->toBe(1050.0)->and($part->status)->toBe('partly_approved')
        ->and($report->refresh()->status)->toBe('partly_approved')->and((float) $report->total_approved)->toBe(1050.0)->and((float) $report->net_payable)->toBe(1050.0)
        ->and(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $report->id)->where('metadata->suspicion', 'cleared')->count())->toBe(1)
        ->and(fn () => $this->reports->clearSuspicion($cab, 0, 'Again', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'not held as suspected');
});

it('never changes a paid report, nor one a payroll run carries; a part whose every line is held is not a rejection', function () {
    xo3Setting('expense.review.suspect_button', true);
    // Once paid, a held line can only be rejected; the payment stays as it was.
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 500);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 200);
    $paid = xo3Submitted($this->employee, $this->user, [$food, $cab]);
    $this->reports->approvePart($paid->parts()->sole(), [$cab->id => ['suspected' => true, 'note' => 'Check the route']], null, $this->managerUser);
    $this->reports->markPaid($paid->refresh(), 'UTR-5', '2026-06-10', $this->payerUser);
    expect(fn () => $this->reports->clearSuspicion($cab, 200, 'Fine', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'already paid');
    $this->reports->clearSuspicion($cab, 0, 'Personal trip', $this->managerUser);
    expect($cab->refresh()->status)->toBe('rejected')->and($paid->refresh()->status)->toBe('paid')->and((float) $paid->net_payable)->toBe(500.0)
        ->and(ExpenseReportSummary::of($paid))->toMatchArray(['rejected' => 200.0, 'held' => 0.0]);

    // Every line held: partly approved with nothing to pay yet; rejecting the held line then rejects the report.
    $only = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-09', 450);
    $held = xo3Submitted($this->employee, $this->user, [$only], 'Held');
    $this->reports->approvePart($held->parts()->sole(), [$only->id => ['suspected' => true, 'note' => 'Bill looks edited']], null, $this->managerUser);
    expect($held->refresh()->status)->toBe('partly_approved')->and((float) $held->net_payable)->toBe(0.0)->and($held->paymentStatus())->toBe('Nothing to pay')
        ->and($held->parts()->sole()->status)->toBe('partly_approved');

    // While a payroll run carries the report, releasing waits (nothing is paid twice or changed under the run).
    app()->instance(PayrollCommitments::class, new class($held->id) implements PayrollCommitments
    {
        public function __construct(private readonly int $reportId) {}

        public function pending(string $key, int $employeeId): array
        {
            return $key === PayrollCommitments::REPORT ? [$this->reportId => 'the payroll of June 2026'] : [];
        }
    });
    expect(fn () => app(ExpenseReports::class)->clearSuspicion($only, 450, 'Fine', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'is being paid in the payroll of June 2026');
    app(ExpenseReports::class)->clearSuspicion($only, 0, 'Edited bill', $this->managerUser);
    expect($held->refresh()->status)->toBe('rejected')->and($only->refresh()->status)->toBe('rejected');
});

it('settles open advances against what a cleared line adds, once, and sends it to payroll when there is no payment step', function () {
    xo3Setting('expense.review.suspect_button', true);
    xo3Setting('expense.payment.step_required', false);
    $advances = app(ExpenseAdvances::class);
    $advance = $advances->approve($advances->request($this->employee, ['amount' => 1000, 'purpose' => 'Trip'], $this->user), null, null, $this->managerUser);
    $advances->dispense($advance, 'UTR-1', '2026-06-10', $this->payerUser);
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300);
    $report = xo3Submitted($this->employee, $this->user, [$food, $cab]);

    $this->reports->approvePart($report->parts()->sole(), [$cab->id => ['suspected' => true, 'note' => 'Check']], null, $this->managerUser);
    expect($report->refresh()->status)->toBe('partly_approved')->and((float) $report->advance_adjusted)->toBe(800.0)->and((float) $report->net_payable)->toBe(0.0)
        ->and($report->payment_channel)->toBeNull()->and((float) $advance->refresh()->outstanding)->toBe(200.0);

    $this->reports->clearSuspicion($cab, null, 'Verified', $this->managerUser);
    expect((float) $cab->refresh()->approved_amount)->toBe(300.0)->and($cab->status)->toBe('approved')
        ->and($report->refresh()->status)->toBe('approved')->and((float) $report->total_approved)->toBe(1100.0)->and((float) $report->reimbursable_approved)->toBe(1100.0)
        ->and((float) $report->advance_adjusted)->toBe(1000.0)->and((float) $report->net_payable)->toBe(100.0)
        ->and($report->payment_channel)->toBe('payroll')->and($report->isScheduledForPayroll())->toBeTrue()
        ->and((float) $advance->refresh()->outstanding)->toBe(0.0)->and($advance->status)->toBe('settled')
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'adjusted')->orderBy('id')->pluck('entry_key')->all())
        ->toBe(['report-'.$report->id, 'report-'.$report->id.'-receipt-'.$cab->id]);
});

it('keeps EXP-1: returning a report discards every line decision of the other approvers, a suspicion included', function () {
    xo3Setting('expense.review.suspect_button', true);
    xo3Setting('expense.workflow.split_by', 'category');
    xo3Setting('expense.workflow.split_scope', 'all');
    $food = xo3Expense($this->employee, $this->user, $this->food, '2026-06-08', 800);
    $cab = xo3Expense($this->employee, $this->user, $this->cab, '2026-06-08', 300);
    $report = xo3Submitted($this->employee, $this->user, [$food, $cab]);
    $foodPart = $report->parts()->where('workflow_group', 'category:FOOD')->sole();
    $cabPart = $report->parts()->where('workflow_group', 'category:CAB')->sole();

    $this->reports->approvePart($foodPart, [$food->id => ['suspected' => true, 'note' => 'Lunch for whom?']], null, $this->managerUser);
    expect($food->refresh()->isHeldAsSuspected())->toBeTrue()->and($report->refresh()->status)->toBe('submitted');

    $this->reports->returnReport($cabPart, 'Attach the cab bill', $this->managerUser);
    expect($report->refresh()->status)->toBe('returned')->and($foodPart->refresh()->status)->toBe('returned')
        ->and($food->refresh()->status)->toBe('reported')->and($food->approved_amount)->toBeNull()->and($food->approver_note)->toBeNull()
        ->and($food->suspected_at)->toBeNull()->and($food->suspected_by)->toBeNull()->and($food->suspicion_note)->toBeNull();

    // Resubmitted, every part is decided again; a suspicion cleared while another part waits counts when the report is decided.
    $again = $this->reports->submit($report->refresh(), $this->user);
    $foodPart = $again->parts()->where('workflow_group', 'category:FOOD')->where('status', 'pending')->sole();
    $cabPart = $again->parts()->where('workflow_group', 'category:CAB')->where('status', 'pending')->sole();
    $this->reports->approvePart($foodPart, [$food->id => ['suspected' => true, 'note' => 'Still unsure']], null, $this->managerUser);
    $this->reports->clearSuspicion($food, null, 'Team lunch, approved', $this->managerUser);
    expect($again->refresh()->status)->toBe('submitted')->and($foodPart->refresh()->status)->toBe('approved')->and((float) $foodPart->total_approved)->toBe(800.0);
    $this->reports->approvePart($cabPart, null, null, $this->managerUser);
    expect($again->refresh()->status)->toBe('approved')->and((float) $again->total_approved)->toBe(1100.0)->and((float) $again->net_payable)->toBe(1100.0);
});
