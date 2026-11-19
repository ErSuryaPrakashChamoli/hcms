<?php

namespace App\Filament\Support\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Saving a record page, created or changed, goes back to the section's list (owner, 2026-11-19: "once saved or
 * submitted, redirect to the listing page of that section"), not to the record's own edit or view page. The notice
 * keeps the record one click away ("Open"), for records whose setup goes on inside them (a calendar's holidays, a
 * survey's questions, a course's lessons). A section whose list the person cannot open keeps Filament's own redirect.
 */
trait ReturnsToListing
{
    /** The section's list, when the resource has one and this person may open it. */
    protected function listingUrl(): ?string
    {
        $resource = static::getResource();
        if (! $resource::hasPage('index') || ! $resource::canAccess()) {
            return null;
        }
        $page = $resource::getPages()['index']->getPage();
        if (! $page::canAccess()) {
            return null;
        }

        return $this->getResourceUrl('index', $this->getRedirectUrlParameters());
    }

    /** "Open" on the saved notice: the record's view page, else its edit page, when this person may open it. */
    protected function withOpenAction(?Notification $notification, ?Model $record): ?Notification
    {
        if ($notification === null || $record === null || $this->listingUrl() === null) {
            return $notification;
        }
        $resource = static::getResource();
        $url = match (true) {
            $resource::hasPage('view') && $resource::canView($record) => $resource::getUrl('view', ['record' => $record]),
            $resource::hasPage('edit') && $resource::canEdit($record) => $resource::getUrl('edit', ['record' => $record]),
            default => null,
        };

        return $url === null ? $notification : $notification->actions([
            Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->link()->url($url),
        ]);
    }
}
