<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\PriceChangeNotice;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Support\Commercial\OperatorChange;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS.7 completion (B-15): existing subscribers keep their pinned price until the next period (monthly) or renewal
 * (annual) after a written notice of at least 30 days. A notice records when it was sent (the letter or e-mail
 * reference), the new price version and the start of the new terms; it changes nothing by itself. The re-pin
 * (BillingTerms::set) refuses an increase without one and marks it applied. pending() is the renewal re-pin
 * worklist: notices whose new terms are due and not pinned yet.
 */
final class PriceNotices
{
    public const NOTICE_DAYS = 30;

    public function __construct(private readonly BillingAudit $audit, private readonly BillingCatalog $catalog, private readonly TenantContext $tenants) {}

    public function record(TenantSubscription $subscription, PlanPriceVersion $to, string $noticeDate, string $effectiveFrom, string $reason, User $actor,
        ?string $reference = null): PriceChangeNotice
    {
        OperatorChange::assert($actor, $reason, 'price notices');
        [$sent, $from] = [$this->day($noticeDate), $this->day($effectiveFrom)];
        $today = now()->toDateString();
        if ($sent > $today) {
            throw new RuntimeException('Record a notice once it has been sent: the notice date is today or earlier.');
        }
        if ($from < $today || Carbon::parse($sent)->addDays(self::NOTICE_DAYS)->toDateString() > $from) {
            throw new RuntimeException('A price increase takes effect at least '.self::NOTICE_DAYS." days after the notice (from {$sent}: ".Carbon::parse($sent)->addDays(self::NOTICE_DAYS)->toDateString().' or later).');
        }
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $subscription, $to, $sent, $from, $reason, $actor, $reference) {
            $dayBefore = Carbon::parse($from)->subDay()->toDateString();
            $current = SubscriptionBillingTerm::query()->where(['subscription_id' => $subscription->id, 'status' => SubscriptionBillingTerm::ACTIVE])
                ->whereDate('effective_from', '<=', $dayBefore)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dayBefore))->first()
                ?? throw new RuntimeException('The subscription has no billing terms before that date: a first price needs no notice.');
            $to = PlanPriceVersion::query()->findOrFail($to->id);
            $price = PlanPrice::query()->findOrFail($to->plan_price_id);
            if ($to->status !== VersionStatus::Published || $this->catalog->versionOnSale($price, $from)?->id !== $to->id) {
                throw new RuntimeException("Price version {$to->version} is not the version on sale on {$from}.");
            }
            if ($to->plan_price_id !== $current->plan_price_id) {
                throw new RuntimeException('A notice announces a new version of the price the subscriber pays; a plan or interval change is agreed with the customer instead.');
            }
            $old = PlanPriceVersion::query()->findOrFail($current->plan_price_version_id);
            if ($to->unit_amount_minor <= $old->unit_amount_minor && $to->minimum_quantity <= $old->minimum_quantity) {
                throw new RuntimeException('This is not a price increase: a lower or equal price applies from the next period without notice.');
            }
            $boundary = $current->interval === BillingInterval::Year
                ? BillingTerms::isRenewal($current->effective_from->toDateString(), $from)
                : Carbon::parse($from)->day === 1;
            if (! $boundary) {
                throw new RuntimeException($current->interval === BillingInterval::Year
                    ? 'An annual subscriber keeps the price until renewal: the new price starts on a renewal date.'
                    : 'The new price starts with a billing period: the 1st of a month.');
            }
            try {
                return DB::transaction(function () use ($tenant, $subscription, $old, $to, $sent, $from, $reason, $actor, $reference) {
                    $notice = PriceChangeNotice::query()->create(['subscription_id' => $subscription->id, 'from_price_version_id' => $old->id, 'to_price_version_id' => $to->id,
                        'notice_date' => $sent, 'effective_from' => $from, 'reference' => $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 100),
                        'reason' => $reason, 'status' => PriceChangeNotice::PENDING, 'created_by' => $actor->id]);
                    $this->audit->both(AuditAction::PriceNoticeRecorded, 'billing', $tenant, $notice, "price notice of subscription #{$subscription->id}",
                        [['field' => 'price', 'before' => "{$old->currency->value} {$old->amount()->toDecimal()} (v{$old->version}, minimum {$old->minimum_quantity})",
                            'after' => "{$to->currency->value} {$to->amount()->toDecimal()} (v{$to->version}, minimum {$to->minimum_quantity}) from {$from}"]],
                        $reason, $actor, ['subscription_id' => $subscription->id, 'notice_date' => $sent, 'reference' => $notice->reference], $sent);

                    return $notice;
                });
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('A notice of this price version is already recorded for the subscriber.');
            }
        });
    }

    /**
     * The re-pin worklist: pending notices of a tenant (or all, for the platform page) with when they fall due.
     *
     * @return Collection<int, PriceChangeNotice>
     */
    public function pending(Tenant $tenant): Collection
    {
        return $this->tenants->runAs($tenant, fn () => PriceChangeNotice::query()->with('toVersion', 'fromVersion')->where('status', PriceChangeNotice::PENDING)
            ->orderBy('effective_from')->get());
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
