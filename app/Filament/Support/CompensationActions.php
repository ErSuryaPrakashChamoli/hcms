<?php

namespace App\Filament\Support;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use RuntimeException;

/** Phase 11 Filament helpers: every compensation write goes through a Compensation domain service. */
final class CompensationActions
{
    public static function run(callable $callback, string|callable $success): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not possible')->body($e->getMessage())->persistent()->send();
        }
    }

    /** The approval-chain actions for one compensation change (visible only to whoever may take them). */
    public static function changeSteps(): ActionGroup
    {
        return ActionGroup::make([
            self::step('submit', 'Submit for review', 'heroicon-m-paper-airplane', fn (CompensationChange $c, User $u) => $c->status === 'draft' && (int) $c->proposed_by === (int) $u->id,
                fn (CompensationChanges $s, CompensationChange $c, User $u) => $s->submit($c, $u)),
            self::step('review', 'Mark reviewed', 'heroicon-m-eye', fn (CompensationChange $c, User $u) => $c->status === 'submitted' && $u->hasPermission('compensation.review') && (int) $c->proposed_by !== (int) $u->id,
                fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->review($c, $u, $d['note'] ?? null), note: false),
            self::step('approve', 'Approve', 'heroicon-m-check', fn (CompensationChange $c, User $u) => $c->status === 'under_review' && $u->hasPermission('compensation.approve') && ! in_array((int) $u->id, array_map('intval', $c->actors()), true),
                fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->approve($c, $u, $d['note'] ?? null), note: false),
            self::step('reject', 'Reject', 'heroicon-m-x-mark', fn (CompensationChange $c, User $u) => in_array($c->status, CompensationChange::PENDING, true) && ($u->hasPermission('compensation.review') || $u->hasPermission('compensation.approve')) && (int) $c->proposed_by !== (int) $u->id,
                fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->reject($c, $u, (string) $d['note']), note: true),
            self::step('return', 'Return to proposer', 'heroicon-m-arrow-uturn-left', fn (CompensationChange $c, User $u) => in_array($c->status, CompensationChange::PENDING, true) && ($u->hasPermission('compensation.review') || $u->hasPermission('compensation.approve')) && (int) $c->proposed_by !== (int) $u->id,
                fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->returnToDraft($c, $u, (string) $d['note']), note: true),
            self::step('schedule', 'Execute (schedule)', 'heroicon-m-calendar', fn (CompensationChange $c, User $u) => $c->status === 'approved' && $u->hasPermission('compensation.execute') && ! in_array((int) $u->id, array_map('intval', $c->actors()), true),
                fn (CompensationChanges $s, CompensationChange $c, User $u) => $s->schedule($c, $u)),
            self::step('cancel', 'Cancel', 'heroicon-m-no-symbol', fn (CompensationChange $c, User $u) => in_array($c->status, ['draft', 'submitted', 'under_review'], true) && (int) $c->proposed_by === (int) $u->id
                || in_array($c->status, ['draft', 'submitted', 'under_review', 'approved', 'scheduled'], true) && $u->hasPermission('compensation.approve') && (int) $c->proposed_by !== (int) $u->id,
                fn (CompensationChanges $s, CompensationChange $c, User $u, array $d) => $s->cancel($c, $u, (string) $d['note']), note: true),
        ]);
    }

    private static function step(string $name, string $label, string $icon, callable $visible, callable $run, ?bool $note = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)->requiresConfirmation()
            ->visible(fn (CompensationChange $record) => $visible($record, auth()->user()))
            ->schema($note === null ? [] : [Textarea::make('note')->label($note ? 'Reason' : 'Note (optional)')->required($note)->maxLength(2000)])
            ->action(fn (CompensationChange $record, array $data) => self::run(fn () => $run(app(CompensationChanges::class), $record, auth()->user(), $data), $label.': done'));
    }
}
