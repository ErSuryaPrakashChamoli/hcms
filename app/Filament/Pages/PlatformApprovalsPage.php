<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Services\FinancialApprovals;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Services\ApprovalDesk;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.7 completion (B-13): the maker-checker queue for platform operators. Each request shows what it will do, the
 * state before and after, who asked and why. Another operator approves (the operation runs at once, in the same
 * transaction) or rejects; the operator who asked may withdraw. A maker never sees approve on their own request,
 * and the service refuses it anyway.
 */
class PlatformApprovalsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?string $title = 'Financial approvals';

    protected static ?string $slug = 'platform-approvals';

    protected static ?int $navigationSort = 28;

    protected string $view = 'filament.pages.platform-approvals';

    #[Url]
    public ?string $approval = null;

    #[Url]
    public ?string $status = 'pending';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @var array<int, string> operator names by id, loaded once per request */
    private array $names = [];

    /** @return Collection<int, FinancialApproval> */
    public function approvals(): Collection
    {
        $approvals = app(FinancialApprovals::class)->list(ApprovalStatus::tryFrom((string) $this->status));
        $this->loadNames($approvals->pluck('maker_id')->merge($approvals->pluck('checker_id'))->filter()->all());

        return $approvals;
    }

    public function selected(): ?FinancialApproval
    {
        return $this->approval ? FinancialApproval::query()->where('reference', $this->approval)->first() : null;
    }

    public function userName(?int $id): string
    {
        if ($id === null) {
            return '—';
        }
        $this->loadNames([$id]);

        return $this->names[$id] ?? "user #{$id}";
    }

    /** @param  list<int>  $ids */
    private function loadNames(array $ids): void
    {
        $missing = array_values(array_diff(array_unique($ids), array_keys($this->names)));
        if ($missing !== []) {
            $this->names += User::query()->whereKey($missing)->pluck('name', 'id')->all();
        }
    }

    public function isMine(FinancialApproval $approval): bool
    {
        return (int) $approval->maker_id === (int) auth()->id();
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $pending = fn () => ($a = $this->selected()) !== null && $a->status === ApprovalStatus::Pending;

        return [
            Action::make('approve')->label('Approve and carry out')->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(fn () => $pending() && ! $this->isMine($this->selected()))
                ->modalDescription('You are the second operator. The operation runs now, in the same transaction; if it can no longer be done, nothing changes and the request stays pending.')
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(ApprovalDesk::class)->approve($this->selected(), $data['reason'], auth()->user()), 'Approved and carried out')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn () => $pending() && ! $this->isMine($this->selected()))
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(ApprovalDesk::class)->reject($this->selected(), $data['reason'], auth()->user()), 'Rejected')),
            Action::make('withdraw')->label('Withdraw my request')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn () => $pending() && $this->isMine($this->selected()))
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(ApprovalDesk::class)->withdraw($this->selected(), $data['reason'], auth()->user()), 'Withdrawn')),
        ];
    }

    private function attempt(\Closure $work, string $done): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $work();
            Notification::make()->success()->title($done)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
