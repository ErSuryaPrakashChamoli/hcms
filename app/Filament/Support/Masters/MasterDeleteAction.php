<?php

namespace App\Filament\Support\Masters;

use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Support\Masters\MasterInUseException;
use App\Support\Masters\MasterUsage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Master-relationships R1: Delete on a master screen. An unused record is deleted as before. A record in use
 * opens a modal that says what uses it (counts only) and offers "Deactivate instead": the record leaves the
 * pickers and every record and the history that use it keep showing it. Deactivation is an edit, so it follows
 * the same rules as one: the user needs edit permission, and a governed configuration record is proposed for
 * approval rather than changed. The model guard (GuardsMasterDeletion) still refuses a delete that became
 * unsafe after the modal opened.
 */
class MasterDeleteAction extends DeleteAction
{
    /** @var array<string, list<array{table: string, column: string, count: int, label: string}>> */
    private array $usage = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->modalHeading(fn (Model $record): string => $this->uses($record) === []
            ? __('filament-actions::delete.single.modal.heading', ['label' => $this->getRecordTitle()])
            : 'In use: '.$this->getRecordTitle());
        $this->modalDescription(fn (Model $record): string => $this->uses($record) === []
            ? __('filament-actions::modal.confirmation')
            : MasterUsage::explain($record, $this->uses($record)));
        $this->modalSubmitActionLabel(fn (Model $record): string => $this->uses($record) === []
            ? __('filament-actions::delete.single.modal.actions.delete.label')
            : 'Deactivate instead');
        $this->modalSubmitAction(fn (Action $action, Model $record) => match (true) {
            $this->uses($record) === [] => $action,
            $this->mayDeactivate($record) => $action->color('warning'),
            default => false,
        });

        $this->action(function (Model $record, $livewire): void {
            $this->usage = [];
            if ($this->uses($record) !== []) {
                $this->deactivate($record, $livewire);

                return;
            }
            try {
                $result = $this->process(static fn (Model $record): ?bool => $record->delete());
            } catch (MasterInUseException $e) {
                Notification::make()->danger()->title('Not deleted')->body($e->getMessage())->persistent()->send();
                $this->halt();
            }
            if (! $result) {
                $this->failure();

                return;
            }
            $this->success();
        });
    }

    /** @return list<array{table: string, column: string, count: int, label: string}> */
    private function uses(Model $record): array
    {
        return $this->usage[(string) $record->getKey()] ??= app(MasterUsage::class)->of($record);
    }

    private function mayDeactivate(Model $record): bool
    {
        return MasterUsage::canDeactivate($record) && MasterUsage::isActive($record) && (auth()->user()?->can('update', $record) ?? false);
    }

    private function deactivate(Model $record, mixed $livewire): void
    {
        if (! $this->mayDeactivate($record)) {
            Notification::make()->danger()->title('Not deleted')->body(MasterUsage::explain($record, $this->uses($record)))->persistent()->send();
            $this->halt();
        }
        $reason = 'Deactivated instead of deleted: it is in use.';
        $changes = app(ConfigurationChanges::class);
        if ($changes->isGoverned($record) && $changes->requiresApproval($record)) {
            $change = $changes->propose($record, ['status' => 'inactive'], $reason);
            Notification::make()->warning()->title('Deactivation submitted for approval')
                ->body("Change #{$change->id} is waiting in the Configuration Change Centre. The record stays active until it is approved.")->persistent()->send();

            return;
        }
        (method_exists($record, 'withAuditReason') ? $record->withAuditReason($reason) : $record)->update(['status' => 'inactive']);
        if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
            $livewire->refreshFormData(['status']);
        }
        Notification::make()->success()->title('Deactivated')->body('It no longer appears in pickers. Records and history that use it still show it.')->send();
    }
}
