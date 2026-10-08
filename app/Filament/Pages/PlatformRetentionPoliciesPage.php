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
                ->modalDescription('A new version applies from its date once another operator approves it. Enter a legal minimum only with its legal basis and reference; the company policy can never be below it.')
                ->schema([
                    Select::make('category')->options(collect(DataInventory::CATEGORIES)->map(fn (array $c) => $c['label'])->all())->required()->searchable(),
                    TextInput::make('jurisdiction')->label('Jurisdiction (two-letter country code, or * for all others)')->required()->default('IN')->maxLength(2),
                    TextInput::make('legal_minimum_months')->label('Legal minimum (months; leave empty if not established)')->numeric()->minValue(0)->maxValue(1200),
                    Textarea::make('legal_basis')->label('Legal basis (required with a minimum)')->rows(2),
                    TextInput::make('legal_reference')->label('Legal reference (act, section, URL)')->maxLength(500),
                    TextInput::make('policy_months')->label('Company policy (months after the tenant closes)')->numeric()->minValue(0)->maxValue(1200)->required(),
                    Textarea::make('policy_basis')->label('Policy basis (contract, decision reference)')->rows(2),
                    DatePicker::make('effective_from')->label('Effective from')->native(false)->required()->default(now()),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(RetentionPolicies::class)->propose($data['category'], (string) $data['jurisdiction'],
                    filled($data['legal_minimum_months'] ?? null) ? (int) $data['legal_minimum_months'] : null, $data['legal_basis'] ?? null, $data['legal_reference'] ?? null,
                    (int) $data['policy_months'], $data['policy_basis'] ?? null, (string) $data['effective_from'], $data['reason'], auth()->user()), 'Proposed: another operator approves it')),
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
