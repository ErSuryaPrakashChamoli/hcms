<?php

namespace App\Filament\Pages;

use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReport;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Resources\ExpenseGroups\ExpenseGroupResource;
use App\Filament\Resources\ExpenseLocationGroups\ExpenseLocationGroupResource;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\ExpenseTaxTypes\ExpenseTaxTypeResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity XO-1 (HR Desk → Expense panel, matrix 26 §26.1 #1): the expense and travel registers in HROne's process
 * order — Travel (requests, bookings, cancellations) · Advances (requests, dispense, cancellations) · Expense (receipts,
 * reports) · Payments · Reports · Expense setup. HROne's separate registers are preset views (`?view=`) of the one
 * register each record type has; nothing here is a new register. Opens for people who run expenses (expense.view or the
 * travel desk, plus an operations right) and lists only what each of them can open; counts come from each register's own
 * (scoped) query. A manager who only approves their team's claims gets no desk.
 */
class ExpenseDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Expense desk';

    protected static ?string $title = 'Expense desk';

    protected static ?string $slug = 'expense-desk';

    protected static ?int $navigationSort = 0;

    /** Any one of these, with expense.view or expense.travel_desk, makes someone an expense operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['expense.manage', 'expense.approve', 'expense.pay', 'expense.travel_desk'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || (! $user->hasPermission('expense.view') && ! $user->hasPermission('expense.travel_desk'))) {
            return false;
        }
        foreach (self::OPERATOR_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return static::anyEntryOpens();
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Every travel, advance, expense and payment register in one place, in the order a claim moves through them. Open a register on its view, filter by employee, organisation or dates, save the views you use and report on what you see.';
    }

    protected function registers(): array
    {
        return ['travel_requests', 'expense_advances', 'expense_receipts', 'expense_reports'];
    }

    protected function sections(): array
    {
        $trips = fn (\Closure $where) => fn () => $where(TravelRequestResource::getEloquentQuery())->count();
        $advances = fn (\Closure $where) => fn () => $where(ExpenseAdvanceResource::getEloquentQuery())->count();

        return [
            ['label' => 'Travel', 'items' => [
                ['label' => 'Travel requests', 'target' => TravelRequestResource::class, 'params' => ['view' => 'waiting'], 'description' => 'Trips waiting for approval, and whom they wait with',
                    'count' => $trips(fn (Builder $q) => $q->where('status', 'submitted'))],
                ['label' => 'Travel bookings', 'target' => TravelRequestResource::class, 'params' => ['view' => 'bookings'], 'description' => 'Approved trips with company bookings still to make',
                    'count' => $trips(fn (Builder $q) => $q->where('status', 'booking'))],
                ['label' => 'Travel cancellations', 'target' => TravelRequestResource::class, 'params' => ['view' => 'cancellations'], 'description' => 'Cancellations waiting, cancelled trips and cancellations refused',
                    'count' => $trips(fn (Builder $q) => $q->where('status', 'cancel_requested'))],
                ['label' => 'All travel requests', 'target' => TravelRequestResource::class],
            ]],
            ['label' => 'Advances', 'items' => [
                ['label' => 'Advance requests', 'target' => ExpenseAdvanceResource::class, 'params' => ['view' => 'waiting'], 'description' => 'Advances waiting for approval',
                    'count' => $advances(fn (Builder $q) => $q->where('status', 'requested'))],
                ['label' => 'Dispense advances', 'target' => DispenseAdvances::class, 'description' => 'Approved advances to pay out',
                    'count' => fn () => ExpenseAdvance::query()->where('status', 'approved')->count()],
                ['label' => 'Dispensed advances', 'target' => ExpenseAdvanceResource::class, 'params' => ['view' => 'dispensed'], 'description' => 'Paid out, not yet settled by expense reports'],
                ['label' => 'Advance cancellations', 'target' => ExpenseAdvanceResource::class, 'params' => ['view' => 'cancellations'], 'description' => 'Cancellations waiting, cancelled advances and cancellations refused',
                    'count' => $advances(fn (Builder $q) => $q->where('status', 'cancel_requested'))],
            ]],
            ['label' => 'Expense', 'items' => [
                ['label' => 'Expense receipts', 'target' => ExpenseReceiptResource::class, 'description' => 'Every expense filed, with its checks and the report it went in'],
                ['label' => 'Suspected duplicates', 'target' => ExpenseReceiptResource::class, 'params' => ['view' => 'suspected'], 'description' => 'Receipts that resemble another one, or repeat an invoice number',
                    'count' => fn () => ExpenseReceiptResource::getEloquentQuery()->where(fn (Builder $q) => $q->whereJsonContains('flags', 'suspected_duplicate')->orWhereJsonContains('flags', 'same_invoice_number'))
                        ->whereIn('status', ['unreported', 'reported'])->count()],
                ['label' => 'Expense reports', 'target' => ExpenseReportResource::class, 'params' => ['view' => 'waiting'], 'description' => 'Reports waiting for approval',
                    'count' => fn () => ExpenseReportResource::getEloquentQuery()->where('status', 'submitted')->count()],
                ['label' => 'All expense reports', 'target' => ExpenseReportResource::class],
            ]],
            ['label' => 'Payments', 'items' => [
                ['label' => 'Pay expenses', 'target' => PayExpenses::class, 'description' => 'Approved reports with money owed to the employee',
                    'count' => fn () => ExpenseReport::query()->whereIn('status', ['approved', 'partly_approved'])->where('net_payable', '>', 0)->whereNull('paid_on')->count()],
                ['label' => 'Paid expense reports', 'target' => ExpenseReportResource::class, 'params' => ['view' => 'paid']],
            ]],
            ['label' => 'Reports', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made travel, advance, expense and payment reports'],
                ['label' => 'My reports', 'target' => ReportResource::class],
            ]],
            ['label' => 'Expense setup', 'items' => [
                ['label' => 'Setup centre: expense', 'target' => SetupCentre::class, 'params' => ['module' => 'expense'], 'description' => 'Expense and travel settings and policies'],
                ['label' => 'Expense categories', 'target' => ExpenseCategoryResource::class],
                ['label' => 'Expense groups', 'target' => ExpenseGroupResource::class],
                ['label' => 'Location groups', 'target' => ExpenseLocationGroupResource::class],
                ['label' => 'Tax types', 'target' => ExpenseTaxTypeResource::class],
                ['label' => 'Expense and travel forms', 'target' => ExpenseFormSetup::class],
                ['label' => 'Policies', 'target' => PolicyResource::class],
            ]],
        ];
    }
}
