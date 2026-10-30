<?php

namespace App\Domain\Recognition\Services;

use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Payroll\Inputs\PendingPayrollInputs;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Events\RecognitionEvent;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Models\RewardPointBudget;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-9d (Engage → Reward points): the points budget, the cycle credits of the reward points policy,
 * awards between colleagues, badge points, redemption through payroll, lapses, expiry and adjustments.
 *
 * - **Ledger:** every movement is a pair of rows in reward_point_entries (or one, for a deposit, a redemption and expiry),
 *   so deposits = budget + points to give + points received + redeemed + expired at all times. Balances are sums.
 * - **Budget:** cycle credits, badge points and positive adjustments are paid from the budget HR deposits; a posting the
 *   budget cannot cover is refused (a credit is skipped and reported by the credit run). Every budget posting locks the
 *   organisation's budget row first, then the employees' rows in id order.
 * - **Cycles:** each employee of a reward_points policy is credited once per cycle (monthly, quarterly, half yearly,
 *   yearly; calendar periods on the tenant's clock), idempotently (dedupe keys). Without carry forward, what an employee
 *   did not give by the end of a cycle lapses back to the budget before the next credit.
 * - **Money:** the worth of a point (engage.points.value_per_point, in engage.points.currency or the base currency) is
 *   a one-time setting, locked once the ledger has a row (SetupCatalogue `locked_after` → ledgerStarted()). Points are
 *   redeemed through payroll only when the organisation allows it (engage.points.redeem_modes) and the points are valued
 *   in the base currency; RewardPointsInput pays them as a taxable earning and records the run on the row.
 */
final class RewardPoints
{
    public const CYCLES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half yearly', 'yearly' => 'Yearly'];

    public const MESSAGE_MAX = 500;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantContext $tenants,
        private readonly PolicyResolver $policies,
        private readonly AccessScopes $scopes,
        private readonly Notifier $notifier,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('engage.points.enabled', false);
    }

    /** SetupCatalogue `locked_after`: the points value and currency are fixed once the ledger has a row. */
    public function ledgerStarted(): bool
    {
        return $this->tenants->has() && RewardPointEntry::query()->withoutGlobalScope(AccessScope::class)->exists();
    }

    /** The money worth of one point (null = points carry no money value). */
    public function valuePerPoint(): ?float
    {
        $value = $this->settings->get('engage.points.value_per_point');

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    public function baseCurrency(): string
    {
        return strtoupper((string) $this->settings->get('tenant.base_currency', 'INR')) ?: 'INR';
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->settings->get('engage.points.currency') ?: $this->baseCurrency()));
    }

    public function payrollRedemption(): bool
    {
        return in_array('payroll', (array) $this->settings->get('engage.points.redeem_modes', []), true);
    }

    /** Why points cannot be redeemed through payroll now (null = they can). */
    public function redemptionRefusal(): ?string
    {
        return match (true) {
            ! $this->enabled() => 'Reward points are switched off.',
            ! $this->payrollRedemption() => 'Points are not redeemed in this organisation.',
            $this->valuePerPoint() === null => 'Points carry no money value (Setup → Engage → points value).',
            $this->currency() !== $this->baseCurrency() => 'Points are valued in '.$this->currency().'; payroll pays in '.$this->baseCurrency().'.',
            default => null,
        };
    }

    /** Now on the tenant's own clock. */
    public function now(): CarbonInterface
    {
        return Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'));
    }

    public function budgetAvailable(): int
    {
        return (int) $this->rows()->where('wallet', 'budget')->sum('points');
    }

    public function givingBalance(Employee $employee): int
    {
        return (int) $this->rows()->where('employee_id', $employee->id)->where('wallet', 'giving')->sum('points');
    }

    public function earnedBalance(Employee $employee): int
    {
        return (int) $this->rows()->where('employee_id', $employee->id)->where('wallet', 'earned')->sum('points');
    }

    /** The reward_points policy version that applies to $employee on $on (null = none). */
    public function policyFor(Employee $employee, ?CarbonInterface $on = null): ?PolicyVersion
    {
        return $this->policies->resolve('reward_points', $employee, ($on ?? $this->now())->toDateString());
    }

    /** "2026-10", "2026-Q4", "2026-H2", "2026" — the cycle $on falls in. */
    public static function cycleKey(string $cycle, CarbonInterface $on): string
    {
        return match ($cycle) {
            'quarterly' => $on->year.'-Q'.$on->quarter,
            'half_yearly' => $on->year.'-H'.($on->month <= 6 ? 1 : 2),
            'yearly' => (string) $on->year,
            default => $on->format('Y-m'),
        };
    }

    /** The first day of the cycle $on falls in. */
    public static function cycleStart(string $cycle, CarbonInterface $on): CarbonInterface
    {
        $day = $on->copy()->startOfDay();

        return match ($cycle) {
            'quarterly' => $day->startOfQuarter(),
            'half_yearly' => $day->month <= 6 ? $day->startOfYear() : $day->setDate($day->year, 7, 1),
            'yearly' => $day->startOfYear(),
            default => $day->startOfMonth(),
        };
    }

    /** Points $employee is credited per cycle under $policy: the first eligibility row they match, else points_per_cycle. */
    public function pointsPerCycle(PolicyVersion $policy, Employee $employee, CarbonInterface $on): int
    {
        if (in_array($policy->setting('eligibility_rules'), [true, 1, '1', 'yes'], true)) {
            foreach ((array) $policy->setting('eligibility', []) as $row) {
                $value = match ($row['criterion'] ?? null) {
                    'tenure_months' => $employee->joining_date ? (int) $employee->joining_date->diffInMonths($on, true) : 0,
                    'direct_reports' => $employee->directReports()->currentlyEffective()->count(),
                    default => null,
                };
                $from = is_numeric($row['from'] ?? null) ? (float) $row['from'] : 0.0;
                $to = is_numeric($row['to'] ?? null) ? (float) $row['to'] : null;
                if ($value !== null && $value >= $from && ($to === null || $value <= $to)) {
                    return max(0, (int) ($row['points'] ?? 0));
                }
            }
        }

        return max(0, (int) $policy->setting('points_per_cycle', 0));
    }

    /** HR deposits points into the organisation's budget. */
    public function deposit(int $points, User $actor, ?string $note = null): RewardPointEntry
    {
        $this->assertManager($actor);
        if ($points <= 0) {
            throw new RuntimeException('Deposit a positive number of points.');
        }

        return $this->withBudget(fn () => $this->write(null, 'budget', 'deposit', $points, ['note' => $this->clean($note), 'created_by' => $actor->getKey()]));
    }

    /**
     * The credit run for one employee on $on: once per cycle of their reward_points policy — first the unused points of
     * earlier cycles lapse (without carry forward), then the cycle's points are paid from the budget, and points received
     * longer ago than the policy's expiry expire (oldest first). Returns what happened ('credited', 'no_policy',
     * 'already', 'no_points', 'budget').
     *
     * @return array{status: string, points: int, lapsed: int, expired: int}
     */
    public function credit(Employee $employee, ?CarbonInterface $on = null): array
    {
        $on ??= $this->now();
        $result = ['status' => 'no_policy', 'points' => 0, 'lapsed' => 0, 'expired' => 0];
        $policy = $this->policyFor($employee, $on);
        if (! $this->enabled() || $policy === null || ! in_array($employee->lifecycle_state, Birthdays::STATES, true)) {
            return $result;
        }
        $cycle = (string) ($policy->setting('credit_cycle') ?: 'monthly');
        $key = self::cycleKey($cycle, $on);
        $result['expired'] = $this->expire($employee, $policy, $on);
        if ($this->rows()->where('dedupe_key', "credit:{$employee->id}:{$key}")->exists()) {
            return ['status' => 'already'] + $result;
        }
        $points = $this->pointsPerCycle($policy, $employee, $on);

        return $this->withBudget(function () use ($employee, $policy, $key, $points, $result) {
            $this->lockEmployees([$employee->id]);
            if ($this->rows()->where('dedupe_key', "credit:{$employee->id}:{$key}")->sharedLock()->exists()) {
                return ['status' => 'already'] + $result;
            }
            if (! in_array($policy->setting('carry_forward'), [true, 1, '1', 'yes'], true)) {
                $left = (int) $this->rows()->where('employee_id', $employee->id)->where('wallet', 'giving')->sharedLock()->sum('points');
                if ($left > 0) {
                    $this->write($employee->id, 'giving', 'lapse', -$left, ['cycle_key' => $key, 'dedupe_key' => "lapse:{$employee->id}:{$key}"]);
                    $this->write(null, 'budget', 'lapse_return', $left, ['cycle_key' => $key, 'dedupe_key' => "lapse-return:{$employee->id}:{$key}", 'counterpart_employee_id' => $employee->id]);
                    $result['lapsed'] = $left;
                }
            }
            if ($points <= 0) {
                return ['status' => 'no_points'] + $result;
            }
            if ($this->budget(locked: true) < $points) {
                return ['status' => 'budget'] + $result;
            }
            $meta = ['policy_id' => $policy->policy_id, 'policy_version' => $policy->version ?? null];
            $this->write(null, 'budget', 'credit_funding', -$points, ['cycle_key' => $key, 'dedupe_key' => "credit-funding:{$employee->id}:{$key}", 'counterpart_employee_id' => $employee->id, 'meta' => $meta]);
            $this->write($employee->id, 'giving', 'credit', $points, ['cycle_key' => $key, 'dedupe_key' => "credit:{$employee->id}:{$key}", 'meta' => $meta]);

            return ['status' => 'credited', 'points' => $points] + $result;
        });
    }

    /**
     * $giver gives $points of their points to $receiver, with a message: within the giver's policy reward limit and the
     * receiver's limit per cycle, never to oneself, only to colleagues working here now.
     */
    public function give(User $giver, Employee $receiver, int $points, ?string $message = null): RewardPointEntry
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Reward points are switched off.');
        }
        if (! $giver->hasPermission('recognition.give')) {
            throw new RuntimeException('You cannot give reward points.');
        }
        $me = app(RoleLens::class)->employee($giver);
        if ($me === null) {
            throw new RuntimeException('Reward points are given by employees.');
        }
        $receiver->loadMissing(['person', 'user']);
        if ($me->id === $receiver->id) {
            throw new RuntimeException('You cannot give yourself points.');
        }
        if (! in_array($receiver->lifecycle_state, Birthdays::STATES, true)) {
            throw new RuntimeException('Points go to colleagues who work here now.');
        }
        if ($points <= 0) {
            throw new RuntimeException('Give a positive number of points.');
        }
        $message = $this->clean($message);
        $on = $this->now();
        $policy = $this->policyFor($me, $on);
        $max = (int) ($policy?->setting('max_per_award') ?? 0);
        if ($max > 0 && $points > $max) {
            throw new RuntimeException("One award may carry at most {$max} points.");
        }
        $receiverPolicy = $this->policyFor($receiver, $on);
        $limit = (int) ($receiverPolicy?->setting('max_received_per_cycle') ?? 0);
        $since = $receiverPolicy === null ? null : self::cycleStart((string) ($receiverPolicy->setting('credit_cycle') ?: 'monthly'), $on)->setTimezone(config('app.timezone'));

        $entry = DB::transaction(function () use ($me, $receiver, $points, $message, $giver, $limit, $since) {
            $this->lockEmployees([$me->id, $receiver->id]);
            $balance = (int) $this->rows()->where('employee_id', $me->id)->where('wallet', 'giving')->sharedLock()->sum('points');
            if ($balance < $points) {
                throw new RuntimeException("You have {$balance} points to give.");
            }
            if ($limit > 0) {
                $received = (int) $this->rows()->where('employee_id', $receiver->id)->where('kind', 'award_received')->where('created_at', '>=', $since)->sharedLock()->sum('points');
                if ($received + $points > $limit) {
                    throw new RuntimeException($receiver->person?->display_name." may receive {$limit} points from colleagues this cycle (".max(0, $limit - $received).' left).');
                }
            }
            $this->write($me->id, 'giving', 'award_given', -$points, ['counterpart_employee_id' => $receiver->id, 'note' => $message, 'created_by' => $giver->getKey()]);

            return $this->write($receiver->id, 'earned', 'award_received', $points, ['counterpart_employee_id' => $me->id, 'note' => $message, 'created_by' => $giver->getKey()]);
        });

        $this->tell($receiver, $giver->name.' gave you '.$points.' reward points', $message ?? 'Well done!', $entry);
        RecognitionEvent::dispatch('recognition.points_given', $entry, $receiver, ['points' => $points, 'giver_employee_id' => $me->id]);

        return $entry;
    }

    /** recognition.given / recognition.withdrawn: a badge's reward points are paid from the budget, and returned on withdrawal. */
    public function onRecognition(RecognitionEvent $event): void
    {
        if (! $event->subject instanceof Recognition || ! in_array($event->name, ['recognition.given', 'recognition.withdrawn'], true)) {
            return;
        }
        try {
            $event->name === 'recognition.given' ? $this->awardBadge($event->subject) : $this->reverseBadge($event->subject);
        } catch (Throwable $e) {
            // As with certificates, points never take a badge back (nor stop its withdrawal).
            report($e);
        }
    }

    /** A badge's reward points for its receiver, once per recognition (null = none: off, no points, or the budget is short). */
    public function awardBadge(Recognition $recognition): ?RewardPointEntry
    {
        $recognition->loadMissing(['badge', 'employee.person', 'employee.user']);
        $points = (int) ($recognition->badge?->reward_points ?? 0);
        if (! $this->enabled() || $points <= 0 || $recognition->isWithdrawn()) {
            return null;
        }
        $entry = $this->withBudget(function () use ($recognition, $points) {
            $this->lockEmployees([$recognition->employee_id]);
            if ($this->rows()->where('dedupe_key', "badge:{$recognition->id}")->sharedLock()->exists() || $this->budget(locked: true) < $points) {
                return null;
            }
            $source = ['source_type' => $recognition->getMorphClass(), 'source_id' => $recognition->id];
            $this->write(null, 'budget', 'badge_funding', -$points, $source + ['dedupe_key' => "badge-funding:{$recognition->id}", 'counterpart_employee_id' => $recognition->employee_id]);

            return $this->write($recognition->employee_id, 'earned', 'badge_award', $points, $source + ['dedupe_key' => "badge:{$recognition->id}", 'counterpart_employee_id' => $recognition->giver_employee_id,
                'meta' => ['badge' => $recognition->badge->name]]);
        });
        if ($entry !== null) {
            $this->tell($recognition->employee, $points.' reward points came with the badge '.$recognition->badge->name, 'They are in your reward points.', $entry);
        }

        return $entry;
    }

    /** A withdrawn badge's points go back to the budget (as far as the receiver still has them). */
    public function reverseBadge(Recognition $recognition): ?RewardPointEntry
    {
        $award = $this->rows()->where('dedupe_key', "badge:{$recognition->id}")->first();
        if ($award === null) {
            return null;
        }

        return $this->withBudget(function () use ($recognition, $award) {
            $this->lockEmployees([$award->employee_id]);
            if ($this->rows()->where('dedupe_key', "badge-reversal:{$recognition->id}")->sharedLock()->exists()) {
                return null;
            }
            $back = min((int) $award->points, max(0, (int) $this->rows()->where('employee_id', $award->employee_id)->where('wallet', 'earned')->sharedLock()->sum('points')));
            if ($back <= 0) {
                return null;
            }
            $source = ['source_type' => $recognition->getMorphClass(), 'source_id' => $recognition->id];
            $this->write(null, 'budget', 'badge_funding', $back, $source + ['dedupe_key' => "badge-funding-reversal:{$recognition->id}", 'counterpart_employee_id' => $award->employee_id]);

            return $this->write($award->employee_id, 'earned', 'badge_award', -$back, $source + ['dedupe_key' => "badge-reversal:{$recognition->id}", 'note' => 'Badge withdrawn']);
        });
    }

    /**
     * $employee's points are redeemed through payroll ($actor: the employee themselves when engage.points.self_redeem is
     * on, or HR with recognition.points for someone in their scope). The amount is fixed now at the points value; the next
     * payroll run pays it (RewardPointsInput).
     */
    public function redeem(Employee $employee, int $points, User $actor, ?string $note = null): RewardPointEntry
    {
        if (($refusal = $this->redemptionRefusal()) !== null) {
            throw new RuntimeException($refusal);
        }
        $own = (int) $employee->user_id === (int) $actor->getKey();
        if ($own ? ! (bool) $this->settings->get('engage.points.self_redeem', false) : ! $this->manages($actor, $employee)) {
            throw new RuntimeException($own ? 'Ask HR to redeem your points.' : 'You cannot redeem points for this employee.');
        }
        if ($points <= 0) {
            throw new RuntimeException('Redeem a positive number of points.');
        }
        $value = (float) $this->valuePerPoint();

        return DB::transaction(function () use ($employee, $points, $actor, $note, $value) {
            $this->lockEmployees([$employee->id]);
            $balance = (int) $this->rows()->where('employee_id', $employee->id)->where('wallet', 'earned')->sharedLock()->sum('points');
            if ($balance < $points) {
                throw new RuntimeException("Only {$balance} points can be redeemed.");
            }

            return $this->write($employee->id, 'earned', 'redeem', -$points, ['amount' => round($points * $value, 2), 'currency' => $this->baseCurrency(),
                'note' => $this->clean($note), 'created_by' => $actor->getKey(), 'meta' => ['mode' => 'payroll', 'value_per_point' => $value]]);
        });
    }

    /** A redemption not yet paid is cancelled (its points come back) — never once a payroll run carries it. */
    public function cancelRedemption(RewardPointEntry $redemption, User $actor, string $reason): RewardPointEntry
    {
        if (! $actor->hasPermission('recognition.points')) {
            throw new RuntimeException('Only HR can cancel a redemption.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for cancelling the redemption.');
        }

        return DB::transaction(function () use ($redemption, $actor, $reason) {
            $this->lockEmployees([(int) $redemption->employee_id]);
            $row = $this->rows()->whereKey($redemption->getKey())->lockForUpdate()->firstOrFail();
            if ($row->kind !== 'redeem' || $row->payroll_run_id !== null) {
                throw new RuntimeException('Only a redemption not yet paid can be cancelled.');
            }
            if ($this->rows()->where('dedupe_key', "redeem-reversal:{$row->id}")->exists()) {
                throw new RuntimeException('This redemption was already cancelled.');
            }
            if (($run = app(PendingPayrollInputs::class)->pending('reward_points', (int) $row->employee_id)[$row->id] ?? null) !== null) {
                throw new RuntimeException("The payroll being prepared ({$run}) pays this redemption; recalculate it after cancelling there.");
            }

            return $this->write($row->employee_id, 'earned', 'redeem_reversal', -$row->points, ['source_type' => $row->getMorphClass(), 'source_id' => $row->id,
                'dedupe_key' => "redeem-reversal:{$row->id}", 'note' => $this->clean($reason), 'created_by' => $actor->getKey()]);
        });
    }

    /** HR adds points to (or takes them from) an employee's points received; the budget pays or takes them back. */
    public function adjust(Employee $employee, int $points, User $actor, string $reason): RewardPointEntry
    {
        if (! $this->manages($actor, $employee) || (int) $employee->user_id === (int) $actor->getKey()) {
            throw new RuntimeException('You cannot adjust this employee\'s points.');
        }
        if ($points === 0 || trim($reason) === '') {
            throw new RuntimeException('Give the points to add or take away, and the reason.');
        }

        return $this->withBudget(function () use ($employee, $points, $actor, $reason) {
            $this->lockEmployees([$employee->id]);
            if ($points > 0 && $this->budget(locked: true) < $points) {
                throw new RuntimeException('The points budget has '.$this->budget(locked: true).' points.');
            }
            if ($points < 0 && (int) $this->rows()->where('employee_id', $employee->id)->where('wallet', 'earned')->sharedLock()->sum('points') < -$points) {
                throw new RuntimeException('The employee does not have that many points.');
            }
            $this->write(null, 'budget', 'adjust', -$points, ['counterpart_employee_id' => $employee->id, 'created_by' => $actor->getKey()]);

            return $this->write($employee->id, 'earned', 'adjust', $points, ['note' => $this->clean($reason), 'created_by' => $actor->getKey()]);
        });
    }

    /** Whether $actor manages reward points for $employee (recognition.points, within their access scope). */
    public function manages(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission('recognition.points') && $this->scopes->allowsEmployeeId($actor, (int) $employee->id);
    }

    /**
     * Unpaid redemptions due by $until (not reversed): what RewardPointsInput pays.
     *
     * @return Builder<RewardPointEntry>
     */
    public function unpaidRedemptions(int $employeeId, CarbonInterface $until): Builder
    {
        return $this->rows()->where('employee_id', $employeeId)->where('kind', 'redeem')->whereNull('payroll_run_id')
            ->where('created_at', '<=', $until)
            ->whereNotIn('id', RewardPointEntry::query()->withoutGlobalScope(AccessScope::class)->where('kind', 'redeem_reversal')->whereNotNull('source_id')->select('source_id'));
    }

    /** Points received longer ago than the policy's expiry, not yet used, expire (oldest first; once a day). */
    private function expire(Employee $employee, PolicyVersion $policy, CarbonInterface $on): int
    {
        $months = (int) ($policy->setting('expiry_months') ?? 0);
        if ($months <= 0 || $this->rows()->where('dedupe_key', "expire:{$employee->id}:{$on->toDateString()}")->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($employee, $months, $on) {
            $this->lockEmployees([$employee->id]);
            $earned = $this->rows()->where('employee_id', $employee->id)->where('wallet', 'earned')->sharedLock();
            $cutoff = $on->copy()->subMonthsNoOverflow($months)->setTimezone(config('app.timezone'));
            $old = (int) (clone $earned)->where('points', '>', 0)->where('created_at', '<', $cutoff)->sum('points');
            $used = -(int) (clone $earned)->where('points', '<', 0)->sum('points');
            $expired = min($old - $used, (int) (clone $earned)->sum('points'));
            if ($expired <= 0) {
                return 0;
            }
            $this->write($employee->id, 'earned', 'expire', -$expired, ['dedupe_key' => "expire:{$employee->id}:{$on->toDateString()}", 'meta' => ['months' => $months]]);

            return $expired;
        });
    }

    /** @return Builder<RewardPointEntry> every ledger row of the tenant (balances are never limited by the viewer's scope) */
    private function rows(): Builder
    {
        return RewardPointEntry::query()->withoutGlobalScope(AccessScope::class);
    }

    private function budget(bool $locked = false): int
    {
        return (int) $this->rows()->where('wallet', 'budget')->when($locked, fn (Builder $q) => $q->sharedLock())->sum('points');
    }

    /**
     * Runs $work in a transaction holding the organisation's budget row. The row is created (outside the transaction) on
     * first use: a plain read first, so an existing row never takes the insert's foreign key lock on the tenant.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withBudget(callable $work): mixed
    {
        if (! RewardPointBudget::query()->exists()) {
            RewardPointBudget::query()->insertOrIgnore(['tenant_id' => $this->tenants->id(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::transaction(function () use ($work) {
            RewardPointBudget::query()->lockForUpdate()->firstOrFail();

            return $work();
        });
    }

    /** @param  list<int|null>  $ids  employee rows locked in id order (balances of one person are serialised on it) */
    private function lockEmployees(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(?int $employeeId, string $wallet, string $kind, int $points, array $attributes = []): RewardPointEntry
    {
        return RewardPointEntry::query()->create(['employee_id' => $employeeId, 'wallet' => $wallet, 'kind' => $kind, 'points' => $points] + $attributes);
    }

    private function clean(?string $text): ?string
    {
        $text = trim(strip_tags((string) $text));
        if (mb_strlen($text) > self::MESSAGE_MAX) {
            throw new RuntimeException('Keep the message to '.self::MESSAGE_MAX.' characters.');
        }

        return $text === '' ? null : $text;
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->hasPermission('recognition.points')) {
            throw new RuntimeException('Only HR manages the points budget.');
        }
    }

    private function tell(?Employee $employee, string $subject, string $body, RewardPointEntry $entry): void
    {
        $user = $employee?->user;
        if ($user !== null && $user->isActive()) {
            $this->notifier->send([$user], ['in_app'], $subject, $body, 'recognition.points_received', $entry);
        }
    }
}
