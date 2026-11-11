<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Contracts\PledgePayroll;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Csv\SpreadsheetCell;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * HROne parity GO-18 (Engage → "Initiate fundraise", matrix 27 §27.8.9): fundraiser campaigns and pledges.
 *
 * - HR (engagement.manage) starts a campaign as a draft — name, purpose, beneficiary, window, target and currency, a
 *   supporting document, who may donate (a saved audience or criteria; empty = everyone employed in their scope) — and
 *   opens it: the audience is pinned with the opener's scope (never reaching beyond it). Open → closed (no more pledges)
 *   or cancelled (pledges still to be collected are cancelled with it). Only drafts change.
 * - Employees in the audience (engagement.participate) pledge while it is open and inside its window: an amount they
 *   choose, paid outside payroll, or — owner decision D-GO3, off by default — deducted once from their salary in one
 *   payroll month, only while the organisation allows it (engagement.fundraisers.payroll_deduction), the campaign collects
 *   through payroll and is in the base currency, and only with their consent, recorded on the pledge with the words they
 *   agreed to. The amount is never changed afterwards; the payroll input deducts exactly that amount, once.
 * - A pledge still to be collected can be cancelled by the employee or HR until its payroll month is finalized. If a
 *   payroll run being prepared already shows the deduction, the run must be recalculated before it can be finalized (the
 *   payroll input refuses to settle a cancelled pledge). HR records outside payments (date and reference).
 * - Totals (collected = deducted + paid; still to be collected = pledged) count every pledge; lists of pledges follow
 *   the viewer's access scope.
 */
final class Fundraisers
{
    public const SETTING = 'engagement.fundraisers.payroll_deduction';

    public const NOTE_MAX = 500;

    public const PURPOSE_MAX = 5000;

    public const AMOUNT_MAX = 100000000;

    /** @var array<string, mixed> per-viewer memo, dropped on every write */
    private array $memo = [];

    public function __construct(
        private readonly AudienceQuery $audiences,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
        private readonly TenantContext $tenants,
    ) {}

    // --- Settings and helpers ----------------------------------------------------------------------------------------

    /** D-GO3: may pledges be deducted through payroll at all? (Setup → Engage → Fundraisers; off by default.) */
    public function payrollAllowed(): bool
    {
        return (bool) $this->settings->get(self::SETTING, false);
    }

    public function baseCurrency(): string
    {
        return app(CurrencyRates::class)->baseCurrency();
    }

    /** The folder on the documents disk that holds campaign documents. */
    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/fundraisers';
    }

    /** Today on the tenant's own clock. */
    public function today(): CarbonInterface
    {
        return Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'))->startOfDay();
    }

    public function manages(?User $user): bool
    {
        return $user !== null && $user->hasPermission('engagement.manage');
    }

    public function participates(?User $user): bool
    {
        return $user !== null && $user->hasPermission('engagement.participate');
    }

    /** The words a donor agrees to when a pledge is deducted from their salary. */
    public static function consentText(FundraiserCampaign $campaign, float $amount, string $month): string
    {
        return mb_substr(sprintf('I agree that %s %s is deducted once from my salary in the payroll of %s for the fundraiser "%s". I may cancel it until that payroll is finalized.',
            number_format($amount, 2), $campaign->currency, FundraiserCampaign::monthLabel($month) ?? $month, $campaign->name), 0, 500);
    }

    // --- Campaigns (HR) ------------------------------------------------------------------------------------------------

    /**
     * A new draft campaign.
     *
     * @param  array<string, mixed>  $data  name, purpose, beneficiary, starts_on, ends_on, target_amount, currency, document_path, document_name, audience_id, audience_criteria, payroll_deduction, deduction_month
     */
    public function create(array $data, User $actor): FundraiserCampaign
    {
        Guard::authorise($actor, 'engagement.manage');
        $attributes = $this->checked($data, $actor);
        $this->forget();

        return FundraiserCampaign::query()->create($attributes + ['status' => 'draft', 'created_by' => $actor->getKey()]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(FundraiserCampaign $campaign, array $data, User $actor): FundraiserCampaign
    {
        Guard::authorise($actor, 'engagement.manage');
        if ($campaign->status !== 'draft') {
            throw new EngagementRuleViolation('Only a draft fundraiser can be changed.');
        }
        $current = array_intersect_key($campaign->getAttributes(), array_flip(FundraiserCampaign::CONTENT));
        $current['audience_criteria'] = $campaign->audience_criteria;
        $current['starts_on'] = $campaign->starts_on?->toDateString();
        $current['ends_on'] = $campaign->ends_on?->toDateString();
        $campaign->update($this->checked($data + $current, $actor));
        $this->forget();

        return $campaign->refresh();
    }

    /** Draft → open: the audience is pinned with the opener's scope; it must reach somebody today. */
    public function open(FundraiserCampaign $campaign, User $actor): FundraiserCampaign
    {
        Guard::authorise($actor, 'engagement.manage');

        $opened = DB::transaction(function () use ($campaign, $actor) {
            $current = FundraiserCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw new EngagementRuleViolation('Only a draft fundraiser can be opened.');
            }
            if ($current->ends_on->toDateString() < $this->today()->toDateString()) {
                throw new EngagementRuleViolation('The end date has passed: change it before opening the fundraiser.');
            }
            if ($current->payroll_deduction && ! $this->payrollAllowed()) {
                throw new EngagementRuleViolation('Pledges are not deducted through payroll in this organisation (Setup → Engage → Fundraisers): switch "Collect through payroll" off.');
            }
            if ($current->payroll_deduction && $current->currency !== $this->baseCurrency()) {
                throw new EngagementRuleViolation('Payroll deducts in '.$this->baseCurrency().' only.');
            }
            $criteria = $current->audience_id !== null
                ? (Audience::query()->whereKey($current->audience_id)->where('status', 'active')->value('criteria') ?? throw new EngagementRuleViolation('The chosen audience is inactive.'))
                : ($current->audience_criteria ?? []);
            $criteria = $this->audiences->normalise(is_array($criteria) ? $criteria : (array) json_decode((string) $criteria, true));
            if (! $this->audiences->withinScope($criteria, $actor)) {
                throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
            }
            $reach = $this->audiences->count($criteria, $actor, $this->today());
            if ($reach === 0) {
                throw new EngagementRuleViolation('The audience reaches nobody in your scope today.');
            }
            $current->update(['status' => 'open', 'audience_criteria' => $criteria, 'scope_user_id' => $actor->getKey(), 'opened_at' => now(), 'opened_by' => $actor->getKey()]);
            $this->audit->record(AuditAction::AudienceUsed, 'engagement', $current, [], null, actor: $actor, metadata: ['purpose' => 'fundraiser', 'eligible' => $reach, 'criteria' => array_keys($criteria)]);

            return $current;
        });
        $this->forget();

        return $opened->refresh();
    }

    /** Open → closed: no more pledges; pledges already made are still collected (their payroll month, outside payments). */
    public function close(FundraiserCampaign $campaign, User $actor, ?string $note = null): FundraiserCampaign
    {
        Guard::authorise($actor, 'engagement.manage');
        $closed = DB::transaction(function () use ($campaign, $actor, $note) {
            $current = FundraiserCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status !== 'open') {
                throw new EngagementRuleViolation('Only an open fundraiser can be closed.');
            }
            $current->withAuditReason($this->clean($note, self::NOTE_MAX))->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->getKey()]);

            return $current;
        });
        $this->forget();

        return $closed->refresh();
    }

    /**
     * Draft or open → cancelled, with a reason; its pledges still to be collected are cancelled with it.
     *
     * @return array{campaign: FundraiserCampaign, pledges: int, carried: int}
     */
    public function cancel(FundraiserCampaign $campaign, User $actor, string $reason): array
    {
        Guard::authorise($actor, 'engagement.manage');
        $reason = $this->clean($reason, self::NOTE_MAX) ?? throw new EngagementRuleViolation('Give the reason for cancelling the fundraiser.');
        $result = ['pledges' => 0, 'carried' => 0];
        $this->audit->operation('engagement', 'Fundraiser cancelled', function () use ($campaign, $actor, $reason, &$result) {
            return DB::transaction(function () use ($campaign, $actor, $reason, &$result) {
                $current = FundraiserCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
                if (! in_array($current->status, ['draft', 'open'], true)) {
                    throw new EngagementRuleViolation('Only a draft or open fundraiser can be cancelled.');
                }
                $current->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey(), 'cancel_reason' => $reason]);
                $pledges = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->where('fundraiser_campaign_id', $current->id)->where('status', 'pledged')
                    ->orderBy('id')->lockForUpdate()->get();
                foreach ($pledges as $pledge) {
                    $result['carried'] += $this->carriedBy($pledge) !== null ? 1 : 0;
                    $pledge->withAuditReason('Fundraiser cancelled: '.$reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey(), 'cancel_reason' => 'Fundraiser cancelled: '.$reason]);
                    $result['pledges']++;
                }

                return $result['pledges'];
            });
        }, $reason, 'fundraiser_campaign');
        $this->forget();

        return ['campaign' => $campaign->refresh()] + $result;
    }

    // --- Pledges ---------------------------------------------------------------------------------------------------------

    /**
     * $actor pledges $amount to the campaign: paid outside payroll (`paid_outside`) or deducted once from their salary
     * (`one_time_payroll`, in $month or the campaign's fixed deduction month, only with $consent).
     */
    public function pledge(FundraiserCampaign $campaign, User $actor, float|int|string $amount, string $mode, ?string $month, bool $consent, ?string $note = null): FundraiserPledge
    {
        Guard::authorise($actor, 'engagement.participate');
        $employee = app(CurrentEmployee::class)->of($actor) ?? throw new EngagementRuleViolation('Only employees can pledge.');
        if (! array_key_exists($mode, FundraiserPledge::MODES)) {
            throw new EngagementRuleViolation('Choose how you will pay the pledge.');
        }
        $amount = $this->amount($amount);
        $note = $this->clean($note, self::NOTE_MAX);

        $pledge = DB::transaction(function () use ($campaign, $actor, $employee, $amount, $mode, $month, $consent, $note) {
            // Serialised with closing and cancelling the campaign.
            $current = FundraiserCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if (($refusal = $this->refusal($current, $employee)) !== null) {
                throw new EngagementRuleViolation($refusal);
            }
            $attributes = ['fundraiser_campaign_id' => $current->id, 'employee_id' => $employee->id, 'amount' => $amount, 'currency' => $current->currency, 'mode' => $mode,
                'status' => 'pledged', 'note' => $note, 'created_by' => $actor->getKey()];
            if ($mode === 'one_time_payroll') {
                $month = $this->payrollMonth($current, $employee, $month);
                if (! $consent) {
                    throw new EngagementRuleViolation('A deduction from your salary needs your consent: tick the agreement.');
                }
                $attributes += ['payroll_month' => $month, 'consent_given_at' => now(), 'consent_text' => self::consentText($current, $amount, $month)];
            }

            return FundraiserPledge::query()->create($attributes);
        });
        $this->forget();

        return $pledge;
    }

    /**
     * Why $employee cannot pledge to the campaign now (null = they can). Checks the campaign's status and window, and that
     * the employee works here and is in its audience.
     */
    public function refusal(FundraiserCampaign $campaign, ?Employee $employee): ?string
    {
        $today = $this->today()->toDateString();

        return match (true) {
            $employee === null => 'Only employees can pledge.',
            $campaign->status !== 'open' => 'This fundraiser is not open for pledges.',
            $campaign->starts_on->toDateString() > $today => 'This fundraiser opens for pledges on '.$campaign->starts_on->format('d M Y').'.',
            $campaign->ends_on->toDateString() < $today => 'This fundraiser ended on '.$campaign->ends_on->format('d M Y').'.',
            ! $employee->lifecycle_state->isEmployed() => 'Only people working here can pledge.',
            ! $this->inAudience($campaign, $employee) => 'This fundraiser is not open to you.',
            default => null,
        };
    }

    /**
     * The payroll months $employee may choose for a deduction from the campaign (empty = no deduction through payroll:
     * the organisation does not allow it, the campaign does not collect through payroll, or no month is open).
     *
     * @return array<string, string> "Y-m" => label
     */
    public function payrollMonths(FundraiserCampaign $campaign, ?Employee $employee): array
    {
        if ($employee === null || ! $this->payrollAllowed() || ! $campaign->payroll_deduction || $campaign->currency !== $this->baseCurrency()) {
            return [];
        }
        $months = app(PledgePayroll::class)->months((int) $employee->id);
        if ($campaign->deduction_month !== null) {
            return array_intersect_key($months, [$campaign->deduction_month => true]);
        }

        return $months;
    }

    /** A pledge still to be collected is cancelled (its consent withdrawn) by the employee or HR in scope. */
    public function cancelPledge(FundraiserPledge $pledge, User $actor, ?string $reason = null): FundraiserPledge
    {
        $reason = $this->clean($reason, self::NOTE_MAX);
        $cancelled = DB::transaction(function () use ($pledge, $actor, $reason) {
            $row = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->whereKey($pledge->getKey())->lockForUpdate()->firstOrFail();
            $this->assertMayAct($row, $actor, own: true);
            // Finalizing the payroll month that deducts it turns it to "deducted"; until then the consent can be withdrawn.
            if ($row->status !== 'pledged') {
                throw new EngagementRuleViolation('Only a pledge still to be collected can be cancelled (this one is '.mb_strtolower($row->statusLabel()).').');
            }
            $row->withAuditReason($reason ?? 'Pledge cancelled')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey(), 'cancel_reason' => $reason]);

            return $row;
        });
        $this->forget();

        return $cancelled;
    }

    /** HR records that a pledge was paid outside payroll (the date and a reference). */
    public function recordPayment(FundraiserPledge $pledge, User $actor, CarbonInterface|string $paidOn, ?string $reference = null): FundraiserPledge
    {
        Guard::authorise($actor, 'engagement.manage');
        $paidOn = Carbon::parse($paidOn)->startOfDay();
        if ($paidOn->toDateString() > $this->today()->toDateString()) {
            throw new EngagementRuleViolation('The payment date cannot be in the future.');
        }
        $reference = $this->clean($reference, 100);
        $paid = DB::transaction(function () use ($pledge, $actor, $paidOn, $reference) {
            $row = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->whereKey($pledge->getKey())->lockForUpdate()->firstOrFail();
            $this->assertMayAct($row, $actor, own: false);
            if ($row->status !== 'pledged') {
                throw new EngagementRuleViolation('Only a pledge still to be collected can be recorded as paid.');
            }
            if (($carried = $this->carriedBy($row)) !== null) {
                throw new EngagementRuleViolation("The payroll being prepared ({$carried}) deducts this pledge.");
            }
            $row->withAuditReason('Paid outside payroll')->update(['status' => 'paid', 'paid_on' => $paidOn->toDateString(), 'payment_reference' => $reference, 'recorded_by' => $actor->getKey()]);

            return $row;
        });
        $this->forget();

        return $paid;
    }

    /** Where a payroll run being prepared already deducts the pledge ("the payroll of Oct 2026"), if one does. */
    public function carriedBy(FundraiserPledge $pledge): ?string
    {
        if ($pledge->mode !== 'one_time_payroll') {
            return null;
        }

        return app(PledgePayroll::class)->carried((int) $pledge->employee_id)[$pledge->id] ?? null;
    }

    // --- Reads ---------------------------------------------------------------------------------------------------------

    /**
     * Collected (deducted + paid), still to be collected (pledged), donors and pledges of each campaign, over every
     * pledge (whoever is looking).
     *
     * @param  list<int>  $ids
     * @return array<int, array{target: float, collected: float, pledged: float, donors: int, pledges: int, percent: int}>
     */
    public function totalsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $rows = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->whereIn('fundraiser_campaign_id', $ids)->where('status', '!=', 'cancelled')
            ->selectRaw('fundraiser_campaign_id, status, sum(amount) as total, count(*) as pledges')->groupBy('fundraiser_campaign_id', 'status')->toBase()->get();
        $donors = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->whereIn('fundraiser_campaign_id', $ids)->where('status', '!=', 'cancelled')
            ->selectRaw('fundraiser_campaign_id, count(distinct employee_id) as donors')->groupBy('fundraiser_campaign_id')->toBase()->get()->pluck('donors', 'fundraiser_campaign_id');
        $targets = FundraiserCampaign::query()->whereIn('id', $ids)->pluck('target_amount', 'id');
        $out = [];
        foreach ($ids as $id) {
            $mine = $rows->where('fundraiser_campaign_id', $id);
            $collected = round((float) $mine->whereIn('status', ['deducted', 'paid'])->sum(fn ($r) => (float) $r->total), 2);
            $pledged = round((float) $mine->where('status', 'pledged')->sum(fn ($r) => (float) $r->total), 2);
            $target = (float) ($targets[$id] ?? 0);
            $out[$id] = ['target' => $target, 'collected' => $collected, 'pledged' => $pledged, 'donors' => (int) ($donors[$id] ?? 0),
                'pledges' => (int) $mine->sum(fn ($r) => (int) $r->pledges), 'percent' => $target > 0 ? (int) min(100, floor(($collected + $pledged) * 100 / $target)) : 0];
        }

        return $out;
    }

    /** @return array{target: float, collected: float, pledged: float, donors: int, pledges: int, percent: int} */
    public function totals(FundraiserCampaign $campaign): array
    {
        return $this->memo['totals:'.$campaign->id] ??= $this->totalsFor([(int) $campaign->id])[(int) $campaign->id];
    }

    /** Is the employee one of the campaign's audience (its pinned criteria, within the opener's scope)? */
    public function inAudience(FundraiserCampaign $campaign, Employee $employee): bool
    {
        $key = "audience:{$campaign->id}:{$employee->id}";
        if (! array_key_exists($key, $this->memo)) {
            $scopeUser = $campaign->scope_user_id !== null ? User::query()->find($campaign->scope_user_id) : null;
            $criteria = $campaign->audience_criteria ?? [];
            if ($campaign->status === 'draft' && $campaign->audience_id !== null) {
                $criteria = (array) (Audience::query()->whereKey($campaign->audience_id)->value('criteria') ?? []);
            }
            $this->memo[$key] = AccessScope::withoutScoping(fn () => $this->audiences->query(is_array($criteria) ? $criteria : [], $scopeUser, $this->today())
                ->where('employees.id', $employee->id)->exists());
        }

        return $this->memo[$key];
    }

    /** May $user see the campaign? HR always; employees an open or closed one of their audience, or one they pledged to. */
    public function canSee(User $user, FundraiserCampaign $campaign): bool
    {
        if ($this->manages($user)) {
            return true;
        }

        return $this->participates($user) && in_array((int) $campaign->id, $this->visibleIds($user), true);
    }

    /**
     * The campaigns an employee (not HR) may see: open or closed ones of their audience, and every one they pledged to.
     *
     * @return list<int>
     */
    public function visibleIds(User $user): array
    {
        $key = 'visible:'.$user->getKey();
        if (! array_key_exists($key, $this->memo)) {
            $employee = app(CurrentEmployee::class)->of($user);
            $ids = [];
            if ($employee !== null) {
                $ids = FundraiserPledge::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
                    ->distinct()->pluck('fundraiser_campaign_id')->map(fn ($id) => (int) $id)->all();
                $candidates = FundraiserCampaign::query()->whereIn('status', ['open', 'closed'])->whereNotIn('id', $ids)->orderByDesc('id')->limit(100)->get();
                foreach ($candidates as $campaign) {
                    if ($this->inAudience($campaign, $employee)) {
                        $ids[] = (int) $campaign->id;
                    }
                }
            }
            sort($ids);
            $this->memo[$key] = array_values(array_unique($ids));
        }

        return $this->memo[$key];
    }

    /**
     * The open campaigns $user may pledge to today (newest first), for Home and the Donate buttons.
     *
     * @return Collection<int, FundraiserCampaign>
     */
    public function openFor(User $user, int $limit = 20): Collection
    {
        $employee = app(CurrentEmployee::class)->of($user);
        if ($employee === null || ! $this->participates($user)) {
            return collect();
        }
        $today = $this->today()->toDateString();

        return FundraiserCampaign::query()->where('status', 'open')->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->orderBy('ends_on')->orderByDesc('id')->limit(100)->get()
            ->filter(fn (FundraiserCampaign $c) => $this->inAudience($c, $employee))->take($limit)->values();
    }

    /** Is there anything for an employee to see (an open campaign of theirs, or a pledge they made)? */
    public function hasAnyFor(User $user): bool
    {
        return $this->participates($user) && $this->visibleIds($user) !== [];
    }

    /** The campaign query a viewer's list shows: everything for HR, the visible ones for employees. */
    public function listQuery(Builder $query, User $user): Builder
    {
        return $this->manages($user) ? $query : $query->whereIn('id', $this->visibleIds($user));
    }

    /** A short-lived signed link to the campaign's document (re-checked by the download route). */
    public function documentUrl(FundraiserCampaign $campaign, int $minutes = 30): ?string
    {
        return $campaign->document_path === null ? null : URL::temporarySignedRoute('parity.file', now()->addMinutes($minutes), ['type' => 'fundraiser', 'id' => $campaign->id, 'index' => 0]);
    }

    /** The campaign's pledges the viewer may see (HR: in their access scope; employees: their own), newest first. */
    public function pledgesFor(FundraiserCampaign $campaign, User $user): Builder
    {
        $query = FundraiserPledge::query()->where('fundraiser_campaign_id', $campaign->id);
        if ($this->manages($user)) {
            return $query;
        }
        $employee = app(CurrentEmployee::class)->of($user);

        return $query->where('employee_id', $employee?->id ?? 0);
    }

    /** The pledges of the campaign HR may see, as CSV (spreadsheet formulas neutralised); the export is audited. */
    public function pledgesCsv(FundraiserCampaign $campaign, User $actor): string
    {
        Guard::authorise($actor, 'engagement.manage');
        $rows = $this->pledgesFor($campaign, $actor)->with('employee.person')->orderBy('id')->get();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Fundraiser', 'Employee code', 'Employee', 'Amount', 'Currency', 'How', 'Payroll month', 'Consent given at', 'Status', 'Pledged on', 'Deducted on', 'Paid on', 'Payment reference', 'Note']);
        foreach ($rows as $p) {
            fputcsv($out, array_map(self::cell(...), [$campaign->name, $p->employee?->employee_code, $p->employee?->person?->display_name, number_format((float) $p->amount, 2, '.', ''), $p->currency,
                $p->modeLabel(), FundraiserCampaign::monthLabel($p->payroll_month), $p->consent_given_at?->toDateTimeString(), $p->statusLabel(), $p->created_at?->toDateString(),
                $p->deducted_at?->toDateString(), $p->paid_on?->toDateString(), $p->payment_reference, $p->note]));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record(AuditAction::Export, 'engagement', $campaign, [], null, actor: $actor, metadata: ['export' => 'fundraiser_pledges', 'rows' => $rows->count()]);

        return $csv;
    }

    /** Drop the per-viewer memo (after a write, or between requests in one process). */
    public function forget(): void
    {
        $this->memo = [];
    }

    // --- Internals -----------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checked(array $data, User $actor): array
    {
        $name = $this->clean($data['name'] ?? null, 150) ?? throw new EngagementRuleViolation('Give the fundraiser a name.');
        $purpose = $this->clean($data['purpose'] ?? null, self::PURPOSE_MAX) ?? throw new EngagementRuleViolation('Describe the purpose of the fundraiser.');
        if (blank($data['starts_on'] ?? null) || blank($data['ends_on'] ?? null)) {
            throw new EngagementRuleViolation('Give the start and end dates.');
        }
        $starts = Carbon::parse($data['starts_on'])->toDateString();
        $ends = Carbon::parse($data['ends_on'])->toDateString();
        if ($ends < $starts) {
            throw new EngagementRuleViolation('The end date is before the start date.');
        }
        $target = $this->amount($data['target_amount'] ?? null, 'target');
        $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?: $this->baseCurrency();
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new EngagementRuleViolation('The currency is a three-letter code (e.g. INR).');
        }
        $payroll = filter_var($data['payroll_deduction'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $month = filled($data['deduction_month'] ?? null) ? (string) $data['deduction_month'] : null;
        if ($payroll && ! $this->payrollAllowed()) {
            throw new EngagementRuleViolation('Pledges are not deducted through payroll in this organisation (Setup → Engage → Fundraisers).');
        }
        if ($payroll && $currency !== $this->baseCurrency()) {
            throw new EngagementRuleViolation('Payroll deducts in '.$this->baseCurrency().' only.');
        }
        if ($month !== null && (! $payroll || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1)) {
            throw new EngagementRuleViolation('A salary deduction month is a payroll month of a fundraiser that collects through payroll.');
        }
        $audienceId = filled($data['audience_id'] ?? null) ? (int) $data['audience_id'] : null;
        if ($audienceId !== null && ! Audience::query()->whereKey($audienceId)->where('status', 'active')->exists()) {
            throw new EngagementRuleViolation('The chosen audience is inactive.');
        }
        $criteria = $this->audiences->normalise(is_array($data['audience_criteria'] ?? null) ? array_filter($data['audience_criteria'], fn ($v) => is_array($v) && $v !== []) : []);
        if (! $this->audiences->withinScope($criteria, $actor)) {
            throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
        }

        return [
            'name' => $name, 'purpose' => $purpose, 'beneficiary' => $this->clean($data['beneficiary'] ?? null, 200), 'starts_on' => $starts, 'ends_on' => $ends,
            'target_amount' => $target, 'currency' => $currency, 'document_path' => $this->checkedDocument($data['document_path'] ?? null),
            'document_name' => $this->clean($data['document_name'] ?? null, 255), 'audience_id' => $audienceId, 'audience_criteria' => $criteria === [] ? null : $criteria,
            'payroll_deduction' => $payroll, 'deduction_month' => $payroll ? $month : null,
        ];
    }

    /** The month a payroll pledge is deducted in: the campaign's fixed month or the one chosen, open for the employee. */
    private function payrollMonth(FundraiserCampaign $campaign, Employee $employee, ?string $month): string
    {
        if (! $this->payrollAllowed()) {
            throw new EngagementRuleViolation('Pledges are not deducted through payroll in this organisation: pay outside payroll.');
        }
        if (! $campaign->payroll_deduction || $campaign->currency !== $this->baseCurrency()) {
            throw new EngagementRuleViolation('This fundraiser does not collect through payroll: pay outside payroll.');
        }
        $month = $campaign->deduction_month ?? (filled($month) ? (string) $month : throw new EngagementRuleViolation('Choose the payroll month of the deduction.'));
        if (! array_key_exists($month, app(PledgePayroll::class)->months((int) $employee->id))) {
            throw new EngagementRuleViolation('The payroll of '.(FundraiserCampaign::monthLabel($month) ?? $month).' is not open for you: pay outside payroll.');
        }

        return $month;
    }

    private function assertMayAct(FundraiserPledge $pledge, User $actor, bool $own): void
    {
        $mine = $own && $this->participates($actor) && (int) (app(CurrentEmployee::class)->of($actor)?->id ?? 0) === (int) $pledge->employee_id;
        if ($mine) {
            return;
        }
        if (! $this->manages($actor) || ! $this->scopes->allowsEmployeeId($actor, (int) $pledge->employee_id)) {
            throw new EngagementRuleViolation($own ? 'Only the donor or HR can cancel this pledge.' : 'Only HR records payments for people in their scope.');
        }
    }

    private function amount(mixed $value, string $what = 'pledge'): string
    {
        $text = is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : '');
        if ($text === '' || ! is_numeric($text) || (float) $text <= 0) {
            throw new EngagementRuleViolation($what === 'target' ? 'The target amount is a positive amount.' : 'Pledge a positive amount.');
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 && round((float) $text, 2) !== (float) $text) {
            throw new EngagementRuleViolation('Amounts have at most two decimals.');
        }
        if ((float) $text > self::AMOUNT_MAX) {
            throw new EngagementRuleViolation('The amount is too large.');
        }

        return number_format((float) $text, 2, '.', '');
    }

    private function checkedDocument(mixed $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        $path = (string) (is_array($path) ? (array_values($path)[0] ?? '') : $path);
        if (! str_starts_with($path, self::directory().'/') || str_contains($path, '..') || ! Storage::disk(config('peopleos.documents.disk', 'local'))->exists($path)) {
            throw new EngagementRuleViolation('Attach the document again: it could not be found.');
        }

        return $path;
    }

    private function clean(mixed $text, int $max): ?string
    {
        $text = trim(strip_tags((string) $text));
        if (mb_strlen($text) > $max) {
            throw new EngagementRuleViolation("Keep it to {$max} characters.");
        }

        return $text === '' ? null : $text;
    }

    private static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return SpreadsheetCell::guard($text);
    }
}
