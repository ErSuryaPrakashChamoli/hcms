<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Services\ExpenseDocumentDownloads;
use App\Domain\Expense\Support\ExpenseReportSummary;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * HROne parity XO-3 ("Download" on the report, matrix 26 §26.4.3 #24): a printable summary of an expense report — its
 * lines, approvals, totals and, as on the report page, its payment — whether or not the cover letter is switched on (the
 * browser prints or saves it as PDF). Signed link → the full protected chain → the report's `view` policy (the employee's
 * own, or expense HR in scope) → audited (EXPORT) → render. Payment details follow expense.payment.show_to_employee for the
 * employee, as on the page.
 */
class ExpenseReportPrintController extends Controller
{
    public static function url(ExpenseReport $report): string
    {
        return URL::temporarySignedRoute('expense.reports.print', now()->addMinutes(30), ['report' => $report->getKey()]);
    }

    public function __invoke(Request $request, int $report, AuditRecorder $audit, SettingsRepository $settings): View
    {
        abort_unless($request->hasValidSignature(), 403);
        $report = ExpenseReport::query()->with(['employee.person', 'decider', 'payer'])->findOrFail($report);
        abort_unless(Gate::allows('view', $report), 403);
        $lines = ExpenseReportSummary::lines($report);
        $showsPayment = ExpenseDocumentDownloads::mayDownload($request->user()) || (bool) $settings->get('expense.payment.show_to_employee', true);
        $audit->record(AuditAction::Export, 'expense', $report, metadata: ['document' => 'expense_report_summary'], reason: 'Report summary printed');

        return view('expense.report-print', [
            'report' => $report,
            'lines' => $lines,
            'summary' => ExpenseReportSummary::of($report, $lines),
            'parts' => ExpenseReportPart::query()->with('decider')->where('expense_report_id', $report->id)->whereNotIn('status', ['returned', 'cancelled'])->orderBy('id')->get(),
            'trips' => $report->trips()->orderBy('start_date')->get(),
            'showsPayment' => $showsPayment && ($report->paid_on !== null || $report->isScheduledForPayroll()),
        ]);
    }
}
