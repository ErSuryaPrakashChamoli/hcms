<?php

namespace App\Filament\Support\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * UX.15 closure: a record page leads with its decisive actions, as the workspaces do (Employee 360: Message ·
 * Request · Action · More), instead of a row of every action as coloured buttons. The first three actions stay
 * as buttons in their declared order; later ones, and destructive ones, move into "More".
 *
 * Presentation only: Filament caches and mounts every action by name from the page's own list before this runs,
 * so each action keeps its visibility rules, authorisation, confirmation and keyboard shortcut. Existing groups
 * keep their place. Rebuilt on every render, so an action that becomes visible after a change still appears.
 */
trait ArrangesRecordActions
{
    private const DECISIVE_ACTIONS = 3;

    /** @return array<Action | ActionGroup> */
    public function getCachedHeaderActions(): array
    {
        $inline = [];
        $more = [];
        $destructive = [];
        $slots = 0;
        foreach (parent::getCachedHeaderActions() as $action) {
            if ($action instanceof ActionGroup || ! $action->isVisible()) {
                $inline[] = $action;
                $slots += $action instanceof ActionGroup ? 1 : 0;
            } elseif ($action->getColor() === 'danger') {
                $destructive[] = $action;
            } elseif ($slots < self::DECISIVE_ACTIONS) {
                $inline[] = $action;
                $slots++;
            } else {
                $more[] = $action;
            }
        }
        if ($more === [] && $destructive === []) {
            return $inline;
        }

        return [...$inline, ActionGroup::make([...$more, ...$destructive])->label('More')->icon('heroicon-m-ellipsis-horizontal')
            ->button()->color('gray')->dropdownPlacement('bottom-end')->livewire($this)];
    }
}
