<?php

namespace App\Filament\Resources\ExpenseReceipts\Pages;

use App\Domain\Expense\Models\ExpenseReceipt;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Support\ExpenseReview;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;

/**
 * HROne parity XO-2 (matrix 26 §26.4.1 #17): one expense receipt in full, read-only, for whoever may view it (the
 * employee's own, or in the viewer's expense access scope). Changing or withdrawing stays on the register; decisions stay
 * on the report and in the Approval Center.
 *
 * HROne parity XO-3 (§26.4.3 #8, the receipt carousel): a receipt in a report walks to the previous / next receipt of that
 * report (among those the viewer may open) and back to the report. Navigation only: nothing changes on a receipt here.
 */
class ViewExpenseReceipt extends PeopleViewRecord
{
    protected static string $resource = ExpenseReceiptResource::class;

    /** @var array{previous: ?ExpenseReceipt, next: ?ExpenseReceipt, position: int, count: int}|null */
    private ?array $neighbours = null;

    public function getTitle(): string|Htmlable
    {
        /** @var ExpenseReceipt $receipt */
        $receipt = $this->getRecord();

        return $receipt->auditLabel();
    }

    /** The record's one-line context, and where the receipt stands in its report. */
    public function getSubheading(): ?string
    {
        $walk = $this->neighbours();
        $position = $walk['count'] > 1 && $walk['position'] > 0 ? 'Receipt '.$walk['position'].' of '.$walk['count'].' in report '.$this->getRecord()->report?->number : null;

        return implode(' · ', array_filter([parent::getSubheading(), $position])) ?: null;
    }

    protected function getHeaderActions(): array
    {
        $url = fn (?ExpenseReceipt $receipt) => $receipt === null ? null : ExpenseReceiptResource::getUrl('view', ['record' => $receipt]);

        return [
            Action::make('previousReceipt')->label('Previous receipt')->icon('heroicon-m-chevron-left')->color('gray')
                ->visible(fn () => $this->neighbours()['previous'] !== null)
                ->url(fn () => $url($this->neighbours()['previous'])),
            Action::make('nextReceipt')->label('Next receipt')->icon('heroicon-m-chevron-right')->iconPosition('after')->color('gray')
                ->visible(fn () => $this->neighbours()['next'] !== null)
                ->url(fn () => $url($this->neighbours()['next'])),
            Action::make('backToReport')->label('Back to report')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                ->visible(fn () => ($report = $this->getRecord()->report) !== null && (auth()->user()?->can('view', $report) ?? false))
                ->url(fn () => ExpenseReportResource::getUrl('view', ['record' => $this->getRecord()->report])),
        ];
    }

    /** @return array{previous: ?ExpenseReceipt, next: ?ExpenseReceipt, position: int, count: int} */
    private function neighbours(): array
    {
        /** @var ExpenseReceipt $receipt */
        $receipt = $this->getRecord();

        return $this->neighbours ??= ExpenseReview::neighbours($receipt);
    }
}
