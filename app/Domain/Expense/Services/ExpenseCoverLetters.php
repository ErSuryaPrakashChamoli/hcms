<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\HtmlLetter;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HROne setup parity EX-C (Expense → Settings → expense cover letter download): a printable cover sheet for a report,
 * to send with the paper receipts. When expense.report.cover_letter is on, the report offers "Download cover letter":
 * the tenant's active letter template of type `expense_cover` (written or Word) filled with the employee's letter
 * variables and the report's `expense.*` variables, or a built-in sheet. {{ expense.lines }} alone on a line of a
 * written template becomes the table of expenses (in a Word template it prints one line per expense). Audited.
 *
 * Variables: expense.report_number, title, status, period, submitted_on, decided_on, currency, total_claimed,
 * total_approved, advance_adjusted, net_payable, receipts_count, approver, lines.
 */
final class ExpenseCoverLetters
{
    private const LINES_TOKEN = 'PEOPLEOSEXPENSELINESTABLE';

    public function __construct(private readonly Letters $letters, private readonly SettingsRepository $settings, private readonly AuditRecorder $audit) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('expense.report.cover_letter', false);
    }

    /** @return array{content: string, name: string, mime: string} */
    public function download(ExpenseReport $report, User $user): array
    {
        if (! $this->enabled()) {
            throw new ExpenseRuleViolation('Expense cover letters are switched off (Setup → Expense).');
        }
        if (! app(ExpensePolicy::class)->view($user, $report)) {
            throw new ExpenseRuleViolation('You cannot see this report.');
        }
        if ($report->status === 'cancelled') {
            throw new ExpenseRuleViolation('A cancelled report has no cover letter.');
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($report->employee_id);
        $lines = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->with('category')->where('expense_report_id', $report->id)->orderBy('expense_date')->orderBy('id')->get();
        $variables = $this->variables($report, $lines);
        $template = LetterTemplate::query()->where('type', 'expense_cover')->where('status', 'active')->orderBy('id')->first();
        $name = Str::slug($report->number.' cover letter');

        if ($template !== null && $template->isDocx()) {
            $file = $this->filledWord($template, $this->letters->context($employee, ['expense' => ['lines' => $this->linesText($lines)] + $variables]));
            $result = ['content' => $file, 'name' => "{$name}.docx", 'mime' => Letters::DOCX_MIME];
        } elseif ($template !== null) {
            $context = $this->letters->context($employee, ['expense' => ['lines' => self::LINES_TOKEN] + $variables]);
            $body = HtmlLetter::render(str_replace(['{{ expense.lines }}', '{{expense.lines}}'], self::LINES_TOKEN, (string) $template->body), $context);
            $body = str_replace(['<p>'.self::LINES_TOKEN.'</p>', self::LINES_TOKEN], $this->linesTable($lines, $report->currency), $body);
            $result = ['content' => $this->page($context['company']['name'] ?? '', $report, $body), 'name' => "{$name}.html", 'mime' => 'text/html'];
        } else {
            $context = $this->letters->context($employee);
            $result = ['content' => view('expense.cover-letter', ['company' => $context['company']['name'] ?? '', 'employee' => $context['employee'], 'report' => $report,
                'expense' => $variables, 'table' => $this->linesTable($lines, $report->currency)])->render(), 'name' => "{$name}.html", 'mime' => 'text/html'];
        }
        $this->audit->record(AuditAction::Download, 'expense', $report, actor: $user, metadata: ['cover_letter' => true, 'template' => $template?->code]);

        return $result;
    }

    /** @return array<string, mixed> */
    public function variables(ExpenseReport $report, $lines): array
    {
        $money = fn ($v) => number_format((float) $v, 2);

        return [
            'report_number' => $report->number, 'title' => $report->title, 'status' => ExpenseReport::STATUSES[$report->status] ?? $report->status,
            'period' => $report->period_from ? $report->period_from->format('d M Y').' – '.$report->period_to?->format('d M Y') : null,
            'submitted_on' => $report->submitted_at?->format('d M Y'), 'decided_on' => $report->decided_at?->format('d M Y'),
            'currency' => $report->currency, 'total_claimed' => $money($report->total_claimed), 'total_approved' => $money($report->total_approved),
            'advance_adjusted' => $money($report->advance_adjusted), 'net_payable' => $money($report->net_payable), 'receipts_count' => $lines->count(),
            'approver' => $report->decided_by ? User::query()->whereKey($report->decided_by)->value('name') : null,
        ];
    }

    private function linesTable($lines, string $currency): string
    {
        $rows = $lines->map(fn (ExpenseReceipt $r) => '<tr><td>'.e($r->number).'</td><td>'.e($r->expense_date->format('d M Y')).'</td><td>'.e($r->category?->name).'</td><td>'.e($r->merchant ?? $r->purpose ?? '').'</td>'
            .'<td style="text-align:right">'.e(number_format((float) $r->claimable_amount, 2)).'</td><td style="text-align:right">'.e($r->approved_amount === null ? '—' : number_format((float) $r->approved_amount, 2)).'</td></tr>')->implode('');

        return '<table style="width:100%;border-collapse:collapse" border="1" cellpadding="4"><thead><tr><th>Expense</th><th>Date</th><th>Category</th><th>Merchant / purpose</th>'
            .'<th style="text-align:right">Claimed ('.e($currency).')</th><th style="text-align:right">Approved ('.e($currency).')</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function linesText($lines): string
    {
        return $lines->map(fn (ExpenseReceipt $r) => $r->number.' · '.$r->expense_date->format('d M Y').' · '.$r->category?->name.' · '.number_format((float) $r->claimable_amount, 2))->implode('; ');
    }

    private function filledWord(LetterTemplate $template, array $context): string
    {
        $disk = Storage::disk($template->file_disk ?: config('peopleos.documents.disk'));
        if (blank($template->file_path) || ! $disk->exists($template->file_path)) {
            throw new ExpenseRuleViolation("The Word file of the template {$template->name} is missing. Upload it again.");
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cover');
        file_put_contents($tmp, (string) $disk->get($template->file_path));
        try {
            return DocxLetter::render($tmp, $context);
        } finally {
            @unlink($tmp);
        }
    }

    private function page(string $company, ExpenseReport $report, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>'.e($report->number).'</title><style>body{font-family:Georgia,serif;max-width:760px;margin:40px auto;line-height:1.5;color:#111}'
            .'header{border-bottom:2px solid #111;margin-bottom:24px;padding-bottom:8px}td,th{vertical-align:top;font-size:13px}@media print{body{margin:0 auto}}</style></head><body>'
            .'<header><strong>'.e($company).'</strong><div style="float:right">'.e($report->number).'</div></header>'.$body.'</body></html>';
    }
}
