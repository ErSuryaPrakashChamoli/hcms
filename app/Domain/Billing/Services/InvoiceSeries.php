<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Identity\Models\User;
use App\Support\Commercial\OperatorChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS.7: invoice numbering. A series belongs to one Markedge entity and document type, with a prefix and an
 * explicit date window chosen by finance (e.g. a financial year; the format is decision B-8). Open series never
 * overlap. A statutory maximum number length comes from the configured, verified statutory parameter of the entity's
 * country (none is enforced where none is configured). Numbers are allocated inside the issuing transaction under the series row lock (never max + 1), so
 * they are unique, consecutive and gap-free: a rolled-back issue gives its number back. A jurisdiction's maximum
 * length (India: 16 characters, pending tax review) is enforced at creation and at allocation.
 *
 * SaaS.7 completion (B-12): credit notes have their own series (document type credit_note) of the same entity. A
 * prefix is unique across all of an entity's document types, so a credit-note number never equals an invoice number.
 */
final class InvoiceSeries
{
    public function __construct(private readonly BillingAudit $audit) {}

    public const DOCUMENT_TYPES = ['invoice', 'credit_note'];

    public function create(string $supplierEntity, string $prefix, string $startsOn, string $endsOn, int $padding, string $reason, User $actor,
        string $documentType = 'invoice'): InvoiceNumberSeries
    {
        OperatorChange::assert($actor, $reason, 'invoice numbering');
        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw new RuntimeException('A series numbers invoices or credit notes.');
        }
        $supplierEntity = strtoupper(trim($supplierEntity));
        $prefix = strtoupper(trim($prefix));
        if (preg_match('/^[A-Z0-9\/-]{1,16}$/', $prefix) !== 1) {
            throw new RuntimeException('The prefix is 1 to 16 capital letters, digits, slash or dash.');
        }
        if ($padding < 1 || $padding > 12) {
            throw new RuntimeException('The sequence is padded to 1 to 12 digits.');
        }
        [$from, $to] = [$this->day($startsOn), $this->day($endsOn)];
        if ($to < $from) {
            throw new RuntimeException('The series ends after it starts.');
        }
        $supplier = app(SupplierProfiles::class)->latestApproved($supplierEntity)
            ?? throw new RuntimeException("{$supplierEntity} has no approved supplier profile yet: numbering rules follow its jurisdiction.");
        // A statutory value (India: CGST Rules rule 46(b), 16 characters), configured and verified as data, not a constant.
        $maxLength = app(CommercialConfiguration::class)->value(ConfigurationKey::InvoiceNumberMaxLength, $supplier->country, $from);
        if ($maxLength !== null && strlen($prefix) + $padding > $maxLength) {
            throw new RuntimeException("An invoice number of {$supplier->country} has at most {$maxLength} characters; this prefix and padding would exceed it.");
        }

        return DB::transaction(function () use ($supplierEntity, $prefix, $from, $to, $padding, $maxLength, $reason, $actor, $documentType) {
            $all = InvoiceNumberSeries::query()->where('supplier_entity', $supplierEntity)->lockForUpdate()->get();
            if ($all->contains(fn (InvoiceNumberSeries $s) => $s->prefix === $prefix)) {
                throw new RuntimeException("The prefix {$prefix} is already used by {$supplierEntity}: numbers must stay unique.");
            }
            $existing = $all->where('document_type', $documentType);
            // Only open series compete for a day: a closed series numbers nothing more, so a new one may take over its window.
            $overlap = $existing->first(fn (InvoiceNumberSeries $s) => $s->status === 'open' && $s->starts_on->toDateString() <= $to && $s->ends_on->toDateString() >= $from);
            if ($overlap !== null) {
                throw new RuntimeException("The window overlaps the series {$overlap->label()}.");
            }
            $series = InvoiceNumberSeries::query()->create(['supplier_entity' => $supplierEntity, 'document_type' => $documentType, 'prefix' => $prefix,
                'starts_on' => $from, 'ends_on' => $to, 'next_sequence' => 1, 'padding' => $padding, 'max_length' => $maxLength, 'status' => 'open',
                'reason' => $reason, 'created_by' => $actor->id]);
            $this->audit->platform(AuditAction::InvoiceSeriesCreated, 'billing', $series, "Invoice series {$series->label()}",
                [['field' => 'series', 'before' => 'none', 'after' => $series->label()]], $reason, $actor,
                ['series_id' => $series->id, 'supplier_entity' => $supplierEntity, 'document_type' => $documentType, 'first_number' => $series->format(1)], $from);

            return $series;
        });
    }

    public function close(InvoiceNumberSeries $series, string $reason, User $actor): InvoiceNumberSeries
    {
        OperatorChange::assert($actor, $reason, 'invoice numbering');

        return DB::transaction(function () use ($series, $reason, $actor) {
            $locked = InvoiceNumberSeries::query()->lockForUpdate()->findOrFail($series->id);
            if ($locked->status === 'closed') {
                return $locked;
            }
            $locked->forceFill(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()])->save();
            $this->audit->platform(AuditAction::InvoiceSeriesClosed, 'billing', $locked, "Invoice series {$locked->label()}",
                [['field' => 'status', 'before' => 'open', 'after' => 'closed']], $reason, $actor, ['series_id' => $locked->id, 'last_number' => $locked->next_sequence > 1 ? $locked->format($locked->next_sequence - 1) : null]);

            return $locked;
        });
    }

    /**
     * Allocates the next number of the open series covering $day. Must run inside the issuing transaction.
     *
     * @return array{0: InvoiceNumberSeries, 1: int, 2: string}
     */
    public function allocate(string $supplierEntity, string $day, string $documentType = 'invoice'): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Invoice numbers are allocated only inside the issuing transaction.');
        }
        $series = InvoiceNumberSeries::query()->where(['supplier_entity' => $supplierEntity, 'document_type' => $documentType, 'status' => 'open'])
            ->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->lockForUpdate()->first()
            ?? throw new RuntimeException('No open '.str_replace('_', ' ', $documentType)." number series of {$supplierEntity} covers {$day}.");
        $sequence = $series->next_sequence;
        $number = $series->format($sequence);
        if ($series->max_length !== null && strlen($number) > $series->max_length) {
            throw new RuntimeException("The series {$series->prefix} is exhausted: the next number would exceed {$series->max_length} characters.");
        }
        $series->forceFill(['next_sequence' => $sequence + 1])->save();

        return [$series, $sequence, $number];
    }

    private function day(string $day): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
    }
}
