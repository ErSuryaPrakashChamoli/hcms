<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Support\ExpenseReportSummary;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Http\Controllers\ParityFileController;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use RuntimeException;
use WeakMap;

/**
 * HROne parity XO-3 (matrix 26 §26.4.2, §26.4.3): the expense report review and the report register's depth.
 *
 * - Review: the report's summary figures (ExpenseReportSummary, once per rendered record), the trips it covers (linked
 *   where the viewer may open them), every file of its lines (the signed, audited ParityFileController route, only for
 *   lines the viewer may open), the previous / next receipt of a receipt's report, and the approver's column picker — up
 *   to `peopleos.expense.review_columns_max` extra receipt fields (custom fields included), kept per person in
 *   ExperiencePreferences (`expense_review_columns`, presentation only).
 * - Register (HR side): search by a receipt number in the report, the payment status as ExpenseReport::paymentStatus()
 *   decides it (each payment channel apart), and the period a report covers.
 */
final class ExpenseReview
{
    /** Receipt fields an approver may add to the lines of a report (merchant and approver note are always offered as columns). */
    public const FIELDS = [
        'purpose' => 'Purpose',
        'invoice_number' => 'Invoice number',
        'from_place' => 'From',
        'to_place' => 'To',
        'location' => 'Where',
        'participants' => 'Participants',
        'description' => 'Description',
        'supplier_gstin' => 'Supplier GST registration number',
        'receipt_amount' => 'Amount (receipt currency)',
        'limit_amount' => 'Limit when filed',
        'over_limit_amount' => 'Over the limit',
        'reimbursable' => 'Repaid to the employee',
        'exception_reason' => 'Reason it is exceptional',
        'trip' => 'Travel request',
        'created_at' => 'Filed on',
    ];

    /** The report's payment status (filter), each as ExpenseReport::paymentStatus() words it. */
    public const PAYMENT = [
        'waiting' => 'Waiting for payment',
        'scheduled' => 'Scheduled for payroll',
        'paid_bank' => 'Paid outside payroll',
        'paid_payroll' => 'Paid with payroll',
        'paid_settlement' => 'Paid in the full and final settlement',
        'nothing' => 'Nothing to pay',
    ];

    /** @var WeakMap<ExpenseReport, array<string, mixed>>|null */
    private static ?WeakMap $summaries = null;

    /** @return array<string, mixed> the report's summary figures, computed once per loaded record */
    public static function summary(ExpenseReport $report): array
    {
        self::$summaries ??= new WeakMap;

        return self::$summaries[$report] ??= ExpenseReportSummary::of($report);
    }

    // --- Column picker ------------------------------------------------------------------------------------------------

    public static function maxColumns(): int
    {
        return max(1, (int) config('peopleos.expense.review_columns_max', 5));
    }

    /** @return array<string, string> key => label: the standard fields, then the expense receipts' custom fields (cf:<key>) */
    public static function columnOptions(): array
    {
        return self::FIELDS + self::customFields()->mapWithKeys(fn (CustomField $f) => ['cf:'.$f->key => $f->label])->all();
    }

    /** @return list<string> the person's chosen fields that are still offered */
    public static function chosenColumns(?User $user): array
    {
        $chosen = (array) (app(ExperiencePreferences::class)->for($user)['expense_review_columns'] ?? []);

        return array_values(array_intersect($chosen, array_keys(self::columnOptions())));
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> what is kept
     */
    public static function saveColumns(User $user, array $keys): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn (string $k) => $k !== '')));
        if (count($keys) > self::maxColumns()) {
            throw new RuntimeException('Limit exceeded: choose at most '.self::maxColumns().' fields.');
        }
        $unknown = array_diff($keys, array_keys(self::columnOptions()));
        if ($unknown !== []) {
            throw new RuntimeException('These fields cannot be shown: '.implode(', ', $unknown).'.');
        }

        return app(ExperiencePreferences::class)->update($user, ['expense_review_columns' => $keys])['expense_review_columns'];
    }

    /**
     * One column per offered field, shown only while the person has chosen it.
     *
     * @return list<TextColumn>
     */
    public static function pickedColumns(): array
    {
        // The saved list itself (ExperiencePreferences keeps it for the request): no catalogue query per column.
        $shown = fn (string $key) => fn () => in_array($key, (array) (app(ExperiencePreferences::class)->for(auth()->user())['expense_review_columns'] ?? []), true);
        $columns = [];
        foreach (self::FIELDS as $key => $label) {
            $columns[] = TextColumn::make('pick_'.$key)->label($label)->placeholder('—')->wrap()
                ->state(fn (ExpenseReceipt $r) => self::fieldValue($key, $r))->visible($shown($key));
        }
        foreach (self::customFields() as $field) {
            $columns[] = TextColumn::make('pick_cf_'.$field->key)->label($field->label)->placeholder('—')->wrap()
                ->state(fn (ExpenseReceipt $r) => self::customValue($field, $r->customField($field->key)))->visible($shown('cf:'.$field->key));
        }

        return $columns;
    }

    public static function fieldValue(string $key, ExpenseReceipt $r): ?string
    {
        $money = fn ($v) => $v === null ? null : number_format((float) $v, 2);

        return match ($key) {
            'location' => implode(', ', array_filter([$r->location_city, $r->location_state])) ?: null,
            'participants' => implode(', ', (array) $r->participants) ?: null,
            'receipt_amount' => $r->currency.' '.number_format((float) $r->amount, 2),
            'limit_amount' => $money($r->limit_amount),
            'over_limit_amount' => (float) $r->over_limit_amount > 0 ? $money($r->over_limit_amount) : null,
            'reimbursable' => $r->reimbursable ? 'Yes' : 'No',
            'trip' => $r->travel_request_id === null ? null : TravelRequest::query()->whereKey($r->travel_request_id)->value('number'),
            'created_at' => $r->created_at?->format('j M Y'),
            default => filled($v = $r->getAttribute($key)) ? (string) $v : null,
        };
    }

    // --- Review panels -----------------------------------------------------------------------------------------------

    /** The trips the report covers, linked where the viewer may open them. */
    public static function tripsHtml(ExpenseReport $report): ?HtmlString
    {
        $user = auth()->user();
        $trips = $report->trips()->orderBy('start_date')->get();
        if ($trips->isEmpty()) {
            return null;
        }

        return new HtmlString($trips->map(function ($trip) use ($user) {
            $text = e($trip->number.' · '.$trip->start_date?->format('j M').'–'.$trip->end_date?->format('j M Y'));
            $url = $user?->can('view', $trip) ? rescue(fn () => TravelRequestResource::getUrl('view', ['record' => $trip]), null, false) : null;

            return $url !== null ? '<a class="text-primary-600 underline" href="'.e($url).'">'.$text.'</a>' : $text;
        })->implode('<br>'));
    }

    /** Every file of the report's lines the viewer may open, through the signed, audited file route. */
    public static function filesHtml(ExpenseReport $report): ?HtmlString
    {
        $user = auth()->user();
        $rows = [];
        foreach (ExpenseReportSummary::lines($report) as $receipt) {
            $paths = array_values((array) ($receipt->attachments ?? []));
            if ($paths === [] || ! $user?->can('view', $receipt)) {
                continue;
            }
            foreach ($paths as $i => $path) {
                if (! is_string($path) || $path === '') {
                    continue;
                }
                $rows[] = e($receipt->number.' · '.$receipt->expense_date?->format('j M Y').' · ').'<a class="text-primary-600 underline" target="_blank" href="'
                    .e(ParityFileController::url('expense-receipt', $receipt, $i)).'">'.e(basename($path)).'</a>';
            }
        }

        return $rows === [] ? null : new HtmlString(implode('<br>', $rows));
    }

    public static function hasFiles(ExpenseReport $report): bool
    {
        return ExpenseReceipt::query()->where('expense_report_id', $report->id)->whereNotNull('attachments')->exists();
    }

    /**
     * The receipts before and after this one in its report (the report's own order: expense date, then filing order),
     * among those the viewer may open.
     *
     * @return array{previous: ?ExpenseReceipt, next: ?ExpenseReceipt, position: int, count: int}
     */
    public static function neighbours(ExpenseReceipt $receipt): array
    {
        if ($receipt->expense_report_id === null) {
            return ['previous' => null, 'next' => null, 'position' => 0, 'count' => 0];
        }
        $user = auth()->user();
        $siblings = ExpenseReceiptResource::getEloquentQuery()->where('expense_report_id', $receipt->expense_report_id)
            ->reorder()->orderBy('expense_date')->orderBy('id')->get()->filter(fn (ExpenseReceipt $r) => $user?->can('view', $r))->values();
        $index = $siblings->search(fn (ExpenseReceipt $r) => (int) $r->id === (int) $receipt->id);
        if ($index === false) {
            return ['previous' => null, 'next' => null, 'position' => 0, 'count' => $siblings->count()];
        }

        return ['previous' => $siblings->get($index - 1), 'next' => $siblings->get($index + 1), 'position' => $index + 1, 'count' => $siblings->count()];
    }

    // --- Register ----------------------------------------------------------------------------------------------------

    /** The number column's search: the report's number, or the number of a receipt in it (HROne "Expense Receipt code"). */
    public static function numberSearch(): Closure
    {
        return fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
            ->where($q->getModel()->qualifyColumn('number'), 'like', "%{$search}%")
            ->orWhereHas('receipts', fn (Builder $r) => $r->where($r->getModel()->qualifyColumn('number'), 'like', "%{$search}%")));
    }

    /** "Payment status" of a report, each payment channel apart (multi-select). */
    public static function paymentFilter(Closure $hrSide): SelectFilter
    {
        return SelectFilter::make('payment')
            ->label('Payment status')
            ->multiple()
            ->options(self::PAYMENT)
            ->query(function (Builder $query, array $data): Builder {
                $values = array_values(array_intersect((array) ($data['values'] ?? []), array_keys(self::PAYMENT)));
                if ($values === []) {
                    return $query;
                }
                $m = $query->getModel();
                $payable = fn (Builder $q) => $q->whereIn($m->qualifyColumn('status'), ['approved', 'partly_approved'])->where($m->qualifyColumn('net_payable'), '>', 0)->whereNull($m->qualifyColumn('paid_on'));
                $channel = $m->qualifyColumn('payment_channel');

                return $query->where(function (Builder $q) use ($values, $payable, $channel, $m) {
                    foreach ($values as $value) {
                        $q->orWhere(fn (Builder $w) => match ($value) {
                            'waiting' => $payable($w)->where(fn (Builder $c) => $c->whereNull($channel)->orWhere($channel, '!=', 'payroll')),
                            'scheduled' => $payable($w)->where($channel, 'payroll'),
                            'paid_bank' => $w->whereNotNull($m->qualifyColumn('paid_on'))->where(fn (Builder $c) => $c->whereNull($channel)->orWhereNotIn($channel, ['payroll', 'settlement'])),
                            'paid_payroll' => $w->whereNotNull($m->qualifyColumn('paid_on'))->where($channel, 'payroll'),
                            'paid_settlement' => $w->whereNotNull($m->qualifyColumn('paid_on'))->where($channel, 'settlement'),
                            'nothing' => $w->whereNull($m->qualifyColumn('paid_on'))->where(fn (Builder $n) => $n->whereNotIn($m->qualifyColumn('status'), ['approved', 'partly_approved'])
                                ->orWhere($m->qualifyColumn('net_payable'), '<=', 0)),
                        });
                    }
                });
            })
            ->visible(fn () => $hrSide());
    }

    /** Reports whose period (first to last expense) overlaps the dates chosen (HROne "Start date" / "End date"). */
    public static function periodFilter(Closure $hrSide): Filter
    {
        return Filter::make('period')
            ->label('Period')
            ->columns(2)
            ->schema([
                DatePicker::make('from')->label('Expenses from')->native(false),
                DatePicker::make('until')->label('Expenses until')->native(false),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->toDateString() : null;
                $until = filled($data['until'] ?? null) ? Carbon::parse($data['until'])->toDateString() : null;
                $m = $query->getModel();

                return $query
                    ->when($from !== null, fn (Builder $q) => $q->whereDate($m->qualifyColumn('period_to'), '>=', $from))
                    ->when($until !== null, fn (Builder $q) => $q->whereDate($m->qualifyColumn('period_from'), '<=', $until));
            })
            ->indicateUsing(function (array $data): array {
                $parts = array_filter([
                    filled($data['from'] ?? null) ? 'from '.Carbon::parse($data['from'])->format('j M Y') : null,
                    filled($data['until'] ?? null) ? 'until '.Carbon::parse($data['until'])->format('j M Y') : null,
                ]);

                return $parts === [] ? [] : [Indicator::make('Expenses '.implode(' ', $parts))];
            })
            ->visible(fn () => $hrSide());
    }

    /** @return Collection<int, CustomField> */
    private static function customFields(): Collection
    {
        return app(CustomFields::class)->definitionsFor(ExpenseReceipt::class);
    }

    private static function customValue(CustomField $field, mixed $state): ?string
    {
        $value = match ($field->type) {
            'boolean' => $state === null ? null : ($state ? 'Yes' : 'No'),
            'dropdown' => $field->optionMap()[$state] ?? $state,
            'multiselect' => is_array($state) ? implode(', ', array_map(fn ($v) => $field->optionMap()[$v] ?? $v, $state)) : $state,
            default => $state,
        };

        return $value === null || $value === '' ? null : (is_scalar($value) ? (string) $value : (string) json_encode($value));
    }
}
