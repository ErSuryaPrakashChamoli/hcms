<?php

namespace App\Filament\Pages;

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Enums\ExportStatus;
use App\Domain\Offboarding\Enums\LegalHoldStatus;
use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Offboarding\Models\LegalHold;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Offboarding\Services\OffboardingDirectory;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.9: tenant data exit for platform operators: per tenant its lifecycle, exports, legal holds, retention evaluation,
 * deletion plans with their approvals, execution steps and certificates. Every action runs in its guarded service
 * (operator, reason, maker-checker for deletion and hold release, audit on both chains); this page only offers what the
 * state allows and shows what the services refuse.
 */
class PlatformOffboardingPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Offboarding';

    protected static ?string $title = 'Tenant offboarding, export and deletion';

    protected static ?string $slug = 'platform-offboarding';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.platform-offboarding';

    #[Url]
    public ?int $tenant = null;

    /** Shown once after an authorised reveal (audited); cleared by the next action. */
    public ?string $revealed = null;

    private ?array $planCache = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function directory(): OffboardingDirectory
    {
        return app(OffboardingDirectory::class);
    }

    public function selectedTenant(): ?Tenant
    {
        return $this->tenant === null ? null : Tenant::query()->find($this->tenant);
    }

    public function openCase(): ?TenantOffboarding
    {
        $tenant = $this->selectedTenant();

        return $tenant === null ? null : app(TenantOffboardings::class)->openCase($tenant);
    }

    public function lastCase(): ?TenantOffboarding
    {
        return $this->tenant === null ? null : $this->directory()->lastCase($this->tenant);
    }

    /** The deletion plan as it stands today (evaluated once per request). */
    public function plan(): ?array
    {
        $tenant = $this->selectedTenant();
        if ($tenant === null || $tenant->status === TenantStatus::Deleted && $this->openCase() === null && $this->lastCase() === null) {
            return null;
        }

        return $this->planCache ??= app(DeletionPlanner::class)->plan($tenant, $this->openCase() ?? $this->lastCase());
    }

    /** @return list<array{code: string, detail: string}> */
    public function closureBlockers(): array
    {
        $tenant = $this->selectedTenant();

        return $tenant === null ? [] : app(TenantOffboardings::class)->closureBlockers($tenant);
    }

    public function deletionEnabled(): bool
    {
        return TenantDeletions::enabled();
    }

    protected function getHeaderActions(): array
    {
        $case = fn () => $this->openCase();
        $tenant = fn () => $this->selectedTenant();

        return [
            Action::make('requestOffboarding')->label('Start offboarding')->icon(Heroicon::OutlinedFlag)
                ->visible(fn () => $tenant() !== null && $case() === null && in_array($tenant()->status, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::Suspended], true))
                ->schema([$this->reason(), TextInput::make('customer_reference')->label('Customer reference (contract, notice)')->maxLength(120),
                    DatePicker::make('service_end_date')->label('End of service')->native(false)])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantOffboardings::class)->request($tenant(), $data['reason'], $data['customer_reference'] ?? null, $data['service_end_date'] ?? null, auth()->user()), 'Offboarding started')),
            Action::make('cancelOffboarding')->label('Cancel offboarding')->color('gray')->icon(Heroicon::OutlinedXMark)
                ->visible(fn () => $case()?->status === OffboardingStatus::Requested)
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantOffboardings::class)->cancel($case(), auth()->user(), $data['reason']), 'Offboarding cancelled')),
            Action::make('closeTenant')->label('Close tenant')->color('danger')->icon(Heroicon::OutlinedLockClosed)
                ->visible(fn () => $case()?->status === OffboardingStatus::Requested)
                ->requiresConfirmation()
                ->modalDescription('Ends the service: nobody signs in, API keys stop, queued work waits, the warehouse feed stops, and retention starts. The tenant can be reopened until a deletion is approved or starts. The subscription must already have ended.')
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantOffboardings::class)->close($case(), auth()->user(), $data['reason']), 'Tenant closed')),
            Action::make('reopenTenant')->label('Reopen tenant')->color('gray')->icon(Heroicon::OutlinedLockOpen)
                ->visible(fn () => $case()?->status === OffboardingStatus::Closed && $case()?->deletion_started_at === null)
                ->requiresConfirmation()
                ->modalDescription('Restores the status the tenant had before it closed and cancels this offboarding. Refused once a deletion is planned, approved or started.')
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantOffboardings::class)->reopen($case(), auth()->user(), $data['reason']), 'Tenant reopened')),
            Action::make('requestExport')->label('Request export')->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->visible(fn () => $tenant() !== null && $tenant()->status !== TenantStatus::Deleted)
                ->modalDescription('Generates an encrypted package of the tenant\'s data in the background (never secrets). One export generates at a time per tenant.')
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantExports::class)->request($tenant(), auth()->user(), $data['reason'], $case()), 'Export requested')),
            Action::make('waiveExport')->label('Waive export')->color('gray')
                ->visible(fn () => $case() !== null && $case()->export_waived_at === null)
                ->modalDescription('Record that the customer declined the export, or received their data another way. A deletion then does not wait for a delivered export.')
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantOffboardings::class)->waiveExport($case(), auth()->user(), $data['reason']), 'Export waived')),
            Action::make('placeHold')->label('Place legal hold')->color('warning')->icon(Heroicon::OutlinedShieldExclamation)
                ->visible(fn () => $tenant() !== null && $tenant()->status !== TenantStatus::Deleted)
                ->modalDescription('Protects at once: nothing it covers is deleted or purged until a second operator approves its release.')
                ->schema([
                    CheckboxList::make('categories')->label('Categories (none ticked = the whole tenant)')->columns(2)
                        ->options(collect(DataInventory::CATEGORIES)->map(fn (array $c) => $c['label'])->all()),
                    TextInput::make('matter_reference')->label('Matter reference')->maxLength(120),
                    DatePicker::make('starts_on')->label('Obligation since')->native(false)->default(now()),
                    $this->reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(LegalHolds::class)->place($tenant(), $data['categories'] ?? [], $data['reason'], $data['matter_reference'] ?? null, $data['starts_on'] ?? null, auth()->user()), 'Legal hold placed')),
            Action::make('planDeletion')->label('Plan deletion')->color('danger')->icon(Heroicon::OutlinedTrash)
                ->visible(fn () => $case()?->status === OffboardingStatus::Closed && $this->directory()->inFlightDeletion($this->tenant) === null)
                ->modalDescription(fn () => $this->deletionEnabled()
                    ? 'Records today\'s plan (shown on this page) for a second operator to approve. Nothing is deleted now; after approval and the cooling-off, a worker deletes only what is still eligible.'
                    : 'Tenant deletion is switched off in this environment (PEOPLEOS_TENANT_DELETION_ENABLED): plans can be reviewed but not recorded.')
                ->schema([$this->reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantDeletions::class)->request($case(), auth()->user(), $data['reason']), 'Deletion planned: a second operator approves it')),
        ];
    }

    // Per-item actions, mounted from the page with the item id as an argument.

    public function exportDownloadAction(): Action
    {
        return Action::make('exportDownload')->label('Download')->action(function (array $arguments) {
            $export = $this->directory()->export((int) ($arguments['export'] ?? 0));
            try {
                return redirect()->away(app(TenantExports::class)->downloadUrl($export, auth()->user()));
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        });
    }

    public function exportPassphraseAction(): Action
    {
        return Action::make('exportPassphrase')->label('Show passphrase')->requiresConfirmation()
            ->modalDescription('The passphrase opens the encrypted package. Showing it is recorded in the audit trail.')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                $this->revealed = app(TenantExports::class)->revealPassphrase($this->directory()->export((int) ($arguments['export'] ?? 0)), auth()->user());
            }, 'Passphrase shown below'));
    }

    public function exportDeliveredAction(): Action
    {
        return Action::make('exportDelivered')->label('Record delivered')->schema([$this->reason('How it reached the customer')])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantExports::class)->markDelivered($this->directory()->export((int) ($arguments['export'] ?? 0)), auth()->user(), $data['reason']), 'Recorded as delivered'));
    }

    public function exportRetryAction(): Action
    {
        return Action::make('exportRetry')->label('Retry')->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantExports::class)->retry($this->directory()->export((int) ($arguments['export'] ?? 0)), auth()->user(), $data['reason']), 'Export queued again'));
    }

    public function holdReleaseRequestAction(): Action
    {
        return Action::make('holdReleaseRequest')->label('Request release')->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(LegalHolds::class)->requestRelease($this->directory()->hold((int) ($arguments['hold'] ?? 0)), auth()->user(), $data['reason']), 'Release requested: another operator approves it'));
    }

    public function holdReleaseApproveAction(): Action
    {
        return Action::make('holdReleaseApprove')->label('Approve release')->color('danger')->requiresConfirmation()->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(LegalHolds::class)->approveRelease($this->directory()->hold((int) ($arguments['hold'] ?? 0)), auth()->user(), $data['reason']), 'Legal hold released'));
    }

    public function holdReleaseRejectAction(): Action
    {
        return Action::make('holdReleaseReject')->label('Keep the hold')->color('gray')->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(LegalHolds::class)->rejectRelease($this->directory()->hold((int) ($arguments['hold'] ?? 0)), auth()->user(), $data['reason']), 'Release declined: the hold stays'));
    }

    public function deletionApproveAction(): Action
    {
        return Action::make('deletionApprove')->label('Approve deletion')->color('danger')->requiresConfirmation()
            ->modalDescription('You approve exactly the plan shown (its fingerprint). It runs after the cooling-off, and only for what is still eligible then.')
            ->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantDeletions::class)->approve($this->directory()->deletion((int) ($arguments['deletion'] ?? 0)), auth()->user(), $data['reason']), 'Deletion approved and scheduled'));
    }

    public function deletionRejectAction(): Action
    {
        return Action::make('deletionReject')->label('Reject')->color('gray')->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantDeletions::class)->reject($this->directory()->deletion((int) ($arguments['deletion'] ?? 0)), auth()->user(), $data['reason']), 'Deletion rejected'));
    }

    public function deletionCancelAction(): Action
    {
        return Action::make('deletionCancel')->label('Cancel')->color('gray')->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantDeletions::class)->cancel($this->directory()->deletion((int) ($arguments['deletion'] ?? 0)), auth()->user(), $data['reason']), 'Deletion cancelled'));
    }

    public function deletionRetryAction(): Action
    {
        return Action::make('deletionRetry')->label('Retry (resume)')->color('warning')->requiresConfirmation()->schema([$this->reason()])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantDeletions::class)->retry($this->directory()->deletion((int) ($arguments['deletion'] ?? 0)), auth()->user(), $data['reason']), 'Deletion resumed'));
    }

    public function externalRecordAction(): Action
    {
        return Action::make('externalRecord')->label('Record')->schema([
            Select::make('status')->options([TenantDeletionStep::EXTERNAL_REQUESTED => 'Deletion requested from the external party', TenantDeletionStep::EXTERNAL_CONFIRMED => 'Deletion confirmed by the external party',
                TenantDeletionStep::EXTERNAL_UNSUPPORTED => 'Not supported by the external party'])->required(),
            $this->reason('Reference and note'),
        ])->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(TenantDeletions::class)->recordExternal($this->directory()->step((int) ($arguments['step'] ?? 0)), $data['status'], auth()->user(), $data['reason']), 'Recorded'));
    }

    public function certificateDownloadAction(): Action
    {
        return Action::make('certificateDownload')->label('Certificate')->action(function (array $arguments) {
            $deletion = $this->directory()->deletion((int) ($arguments['deletion'] ?? 0));
            abort_unless($deletion?->certificate !== null, 404);
            $body = json_encode(['certificate' => $deletion->certificate, 'sha256' => $deletion->certificate_sha256, 'signature' => 'hmac-sha256:'.$deletion->certificate_signature],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return response()->streamDownload(fn () => print ($body), "deletion-certificate-{$deletion->reference}.json", ['Content-Type' => 'application/json']);
        });
    }

    public function canApprove(TenantDeletion $deletion): bool
    {
        return $deletion->status === DeletionStatus::Planned && (int) $deletion->requested_by !== (int) auth()->id();
    }

    public function canApproveRelease(LegalHold $hold): bool
    {
        return $hold->status === LegalHoldStatus::ReleaseRequested && (int) $hold->release_requested_by !== (int) auth()->id();
    }

    public function exportReady(TenantExport $export): bool
    {
        return $export->downloadable();
    }

    public function exportFailed(TenantExport $export): bool
    {
        return $export->status === ExportStatus::Failed;
    }

    private function reason(string $label = 'Reason'): Textarea
    {
        return Textarea::make('reason')->label($label)->required()->minLength(5)->maxLength(1000);
    }

    private function attempt(Closure $work, string $success): void
    {
        if ($success !== 'Passphrase shown below') {
            $this->revealed = null;
        }
        try {
            $work();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
        $this->planCache = null;
    }
}
