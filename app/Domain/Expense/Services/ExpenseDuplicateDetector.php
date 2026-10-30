<?php

namespace App\Domain\Expense\Services;

use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * HROne setup parity EX-C (Expense → Settings: check duplicate receipts, threshold percentage, duplicate invoice number):
 * compares a receipt with everyone's receipts in the organisation (the employee's own and colleagues', never withdrawn
 * ones).
 *
 * - The same file (sha256) already claimed: refused — a 100% match.
 * - Otherwise a score: the same amount in the base currency (40), the same date (30) or a day apart (15), the same
 *   merchant (15) and the same invoice number (15). At or above expense.duplicates.threshold_percent the receipt is
 *   flagged `suspected_duplicate` (with the receipt it resembles) for the approver.
 * - The same invoice number from the same merchant (expense.duplicates.same_invoice_number): refused, flagged
 *   (`same_invoice_number`) or allowed.
 *
 * With expense.duplicates.check off only the invoice-number rule applies.
 *
 * HROne parity XO-2: the result also lists the receipts the verdicts were reached against (`matches`: each receipt at or
 * above the threshold with its score, and each receipt with the same invoice number from the same merchant), kept on the
 * receipt as `duplicate_matches` so the receipt page can name them. The verdicts themselves are unchanged.
 */
final class ExpenseDuplicateDetector
{
    /** At most this many matched receipts are kept per reason (XO-2). */
    public const MATCHES_KEPT = 10;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @param  array{expense_date: string, base_amount: float, merchant: ?string, invoice_number: ?string, attachment_hashes: list<string>}  $receipt
     * @return array{refusal: ?string, score: ?int, of: ?int, flags: list<string>, matches: list<array{id: int, score: ?int, reasons: list<string>}>}
     */
    public function assess(array $receipt, ?int $exceptId = null): array
    {
        $flags = [];
        $matches = [];
        $others = fn () => ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->where('status', '!=', 'withdrawn')->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId));

        $invoice = self::invoiceKey($receipt['invoice_number'] ?? null);
        $merchant = self::merchantKey($receipt['merchant'] ?? null);
        $invoiceRule = (string) $this->settings->get('expense.duplicates.same_invoice_number', 'flag');
        if ($invoice !== null && $invoiceRule !== 'allow') {
            $claimed = $others()->where('invoice_number', $invoice)->orderBy('id')->get(['id', 'number', 'merchant'])
                ->filter(fn (ExpenseReceipt $r) => self::merchantKey($r->merchant) === $merchant)->values();
            $same = $claimed->first();
            if ($same !== null) {
                if ($invoiceRule === 'refuse') {
                    return ['refusal' => "Invoice {$invoice}".($merchant !== null ? ' from '.trim((string) $receipt['merchant']) : '')." was already claimed ({$same->number}).", 'score' => null, 'of' => null, 'flags' => [], 'matches' => []];
                }
                $flags[] = 'same_invoice_number';
                foreach ($claimed->take(self::MATCHES_KEPT) as $match) {
                    $matches[(int) $match->id] = ['id' => (int) $match->id, 'score' => null, 'reasons' => ['invoice']];
                }
            }
        }

        if (! (bool) $this->settings->get('expense.duplicates.check', true)) {
            return ['refusal' => null, 'score' => null, 'of' => null, 'flags' => $flags, 'matches' => array_values($matches)];
        }

        foreach (array_values(array_filter($receipt['attachment_hashes'] ?? [])) as $hash) {
            $copy = $others()->whereJsonContains('attachment_hashes', $hash)->first(['id', 'number']);
            if ($copy !== null) {
                return ['refusal' => "This receipt file was already claimed ({$copy->number}): the same file cannot be claimed twice.", 'score' => 100, 'of' => $copy->id, 'flags' => [], 'matches' => []];
            }
        }

        $date = CarbonImmutable::parse($receipt['expense_date'])->startOfDay();
        $candidates = $others()->where(fn ($q) => $q->whereDate('expense_date', '>=', $date->subDay()->toDateString())->whereDate('expense_date', '<=', $date->addDay()->toDateString())
            ->when($invoice !== null, fn ($q) => $q->orWhere('invoice_number', $invoice)))
            ->orderBy('id')->limit(500)->get(['id', 'expense_date', 'base_amount', 'merchant', 'invoice_number']);
        [$best, $of] = [0, null];
        $scores = [];
        foreach ($candidates as $candidate) {
            $days = (int) round(abs($candidate->expense_date->copy()->startOfDay()->diffInDays($date)));
            $score = (abs((float) $candidate->base_amount - (float) $receipt['base_amount']) < 0.005 ? 40 : 0)
                + ($days === 0 ? 30 : ($days === 1 ? 15 : 0))
                + ($merchant !== null && self::merchantKey($candidate->merchant) === $merchant ? 15 : 0)
                + ($invoice !== null && $candidate->invoice_number === $invoice ? 15 : 0);
            $scores[(int) $candidate->id] = $score;
            if ($score > $best) {
                [$best, $of] = [$score, (int) $candidate->id];
            }
        }
        $threshold = max(1, min(100, (int) $this->settings->get('expense.duplicates.threshold_percent', 80)));
        if ($best >= $threshold) {
            $flags[] = 'suspected_duplicate';
            // XO-2: every receipt at or above the threshold, best first (the verdict's own receipt leads).
            $similar = array_filter($scores, fn (int $score) => $score >= $threshold);
            uksort($similar, fn (int $a, int $b) => [$similar[$b], $a] <=> [$similar[$a], $b]);
            foreach (array_slice($similar, 0, self::MATCHES_KEPT, true) as $id => $score) {
                $matches[$id] = ['id' => $id, 'score' => $score, 'reasons' => [...($matches[$id]['reasons'] ?? []), 'similar']];
            }
        }
        // Each receipt once: the scored ones first, then those matched on the invoice number only.
        foreach ($matches as $id => $match) {
            $matches[$id]['score'] ??= $scores[$id] ?? null;
        }
        uasort($matches, fn (array $a, array $b) => [$b['score'] ?? -1, $a['id']] <=> [$a['score'] ?? -1, $b['id']]);

        return ['refusal' => null, 'score' => $best > 0 ? $best : null, 'of' => $best >= $threshold ? $of : null, 'flags' => $flags, 'matches' => array_values($matches)];
    }

    public static function invoiceKey(mixed $invoice): ?string
    {
        $invoice = strtoupper(trim((string) $invoice));

        return $invoice === '' ? null : $invoice;
    }

    private static function merchantKey(mixed $merchant): ?string
    {
        $merchant = preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $merchant)));

        return $merchant === '' ? null : $merchant;
    }
}
