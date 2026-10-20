<?php

namespace App\Filament\Pages;

use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Services\RetentionPolicies;
use App\Domain\Offboarding\Support\DataInventory;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.9 (D-8 as configuration): retention policies per data category and jurisdiction, versioned and effective-dated,
 * proposed by one operator and approved by another. PeopleOS ships none: a category without an approved policy is never
 * deletable. A legal minimum is entered with its basis and reference, or shown as LEGAL_MINIMUM_NOT_CONFIGURED.
 */
class PlatformRetentionPoliciesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Retention policies';

    protected static ?string $title = 'Retention policies';

    protected static ?string $slug = 'platform-retention-policies';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.platform-retention-policies';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, RetentionPolicy> every version, newest first */
    public function versions(): Collection
    {
        return RetentionPolicy::query()->orderBy('category')->orderBy('jurisdiction')->orderByDesc('version')->get();
    }

    /** @return array<string, array{label: string, owner: string, action: string, current: list<RetentionPolicy>}> */
    public function matrix(): array
    {
        $versions = $this->versions();
        $today = today()->toDateString();
        $out = [];
        foreach (DataInventory::CATEGORIES as $key => $c) {
            $current = [];
            foreach ($versions->where('category', $key)->pluck('jurisdiction')->unique() as $jurisdiction) {
                $p = app(RetentionPolicies::class)->inForce($key, $jurisdiction === RetentionPolicy::ALL_JURISDICTIONS ? '__' : $jurisdiction, $today);
                if ($p !== null && $p->jurisdiction === $jurisdiction) {
                    $current[] = $p;
                }
            }
            $out[$key] = ['label' => $c['label'], 'owner' => $c['owner'], 'action' => $c['action'], 'current' => $current];
        }

        return $out;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('propose')->label('Propose policy version')->icon(Heroicon::OutlinedPlus)
                ->modalDescription('A new version applies from its date once another operator approves it. A: a legal minimum is entered only with its basis and reference, and counts as verified only with its authoritative source, reviewer and verification date (otherwise it stays pending verification and the category is not deleted). B: the company policy can never be below a legal minimum. C: a customer\'s contractual requirement is recorded per tenant on the Offboarding page.')
                ->schema([
                    Select::make('category')->options(collect(DataInventory::CATEGORIES)->map(fn (array $c) => $c['label'])->all())->required()->searchable(),
                    TextInput::make('jurisdiction')->label('Jurisdiction (two-letter country code, or * for all others)')->required()->default('IN')->maxLength(2),
                    TextInput::make('legal_minimum_months')->label('A. Legal minimum (months; leave empty if not established: LEGAL_MINIMUM_NOT_CONFIGURED)')->numeric()->minValue(0)->maxValue(1200),
                    Textarea::make('legal_basis')->label('Legal basis (required with a minimum)')->rows(2),
                    TextInput::make('legal_reference')->label('Legal reference (act, section)')->maxLength(500),
                    TextInput::make('legal_source')->label('Authoritative source (official text or URL; for verification)')->maxLength(500),
                    TextInput::make('legal_verified_by')->label('Verified by (reviewer and role, e.g. counsel)')->maxLength(200),
                    DatePicker::make('legal_verified_on')->label('Verified on')->native(false)->maxDate(now()),
                    TextInput::make('policy_months')->label('B. Company policy (months)')->numeric()->minValue(0)->maxValue(1200)->required(),
                    Select::make('start_event')->label('Counted from')->options(RetentionPolicy::START_EVENTS)->default(RetentionPolicy::START_TENANT_CLOSURE)->required()->live(),
                    TextInput::make('financial_year_end')->label('Financial year end (MM-DD)')->default('03-31')->maxLength(5)
                        ->visible(fn ($get) => $get('start_event') === RetentionPolicy::START_FINANCIAL_YEAR_END),
                    Textarea::make('policy_basis')->label('Policy basis (decision reference)')->rows(2),
                    DatePicker::make('effective_from')->label('Effective from')->native(false)->required()->default(now()),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(RetentionPolicies::class)->propose($data['category'], (string) $data['jurisdiction'],
                    filled($data['legal_minimum_months'] ?? null) ? (int) $data['legal_minimum_months'] : null, $data['legal_basis'] ?? null, $data['legal_reference'] ?? null,
                    (int) $data['policy_months'], $data['policy_basis'] ?? null, (string) $data['effective_from'], $data['reason'], auth()->user(),
                    legalSource: filled($data['legal_source'] ?? null) ? $data['legal_source'] : null, legalVerifiedBy: filled($data['legal_verified_by'] ?? null) ? $data['legal_verified_by'] : null,
                    legalVerifiedOn: filled($data['legal_verified_on'] ?? null) ? (string) $data['legal_verified_on'] : null,
                    startEvent: (string) ($data['start_event'] ?? RetentionPolicy::START_TENANT_CLOSURE), financialYearEnd: $data['financial_year_end'] ?? null), 'Proposed: another operator approves it')),
        ];
    }

    public function approveAction(): Action
    {
        return Action::make('approve')->label('Approve')->color('success')->requiresConfirmation()
            ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(RetentionPolicies::class)->approve(RetentionPolicy::query()->findOrFail((int) $arguments['policy']), auth()->user(), $data['reason']), 'Approved'));
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')->label('Reject')->color('gray')
            ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(RetentionPolicies::class)->reject(RetentionPolicy::query()->findOrFail((int) $arguments['policy']), auth()->user(), $data['reason']), 'Rejected'));
    }

    public function withdrawAction(): Action
    {
        return Action::make('withdraw')->label('Withdraw')->color('gray')
            ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(RetentionPolicies::class)->withdraw(RetentionPolicy::query()->findOrFail((int) $arguments['policy']), auth()->user(), $data['reason']), 'Withdrawn'));
    }

    /** A's state as shown: not configured, pending verification (never a floor), or verified with its reviewer and date. */
    public function legalLabel(RetentionPolicy $policy): string
    {
        return match ($policy->legal_minimum_status) {
            RetentionPolicy::LEGAL_VERIFIED => "{$policy->legal_minimum_months} months, verified by {$policy->legal_verified_by} on {$policy->legal_verified_on?->toDateString()} ({$policy->legal_reference})",
            RetentionPolicy::LEGAL_PENDING_VERIFICATION => RetentionPolicy::LEGAL_MINIMUM_PENDING_VERIFICATION." ({$policy->legal_minimum_months} months entered, {$policy->legal_reference})",
            default => RetentionPolicy::LEGAL_MINIMUM_NOT_CONFIGURED,
        };
    }

    public function startLabel(RetentionPolicy $policy): string
    {
        return $policy->start_event === RetentionPolicy::START_FINANCIAL_YEAR_END ? "from the financial year end ({$policy->financial_year_end}) after closure" : 'from the closure';
    }

    public function isMine(RetentionPolicy $policy): bool
    {
        return (int) $policy->proposed_by === (int) auth()->id();
    }

    public function pending(RetentionPolicy $policy): bool
    {
        return $policy->status === RetentionPolicyStatus::Pending;
    }

    private function attempt(Closure $work, string $success): void
    {
        try {
            $work();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
