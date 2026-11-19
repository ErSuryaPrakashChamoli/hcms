<?php

namespace App\Filament\Support\Pages\Concerns;

use Filament\Support\Facades\FilamentView;

/**
 * A settings page followed as a wizard (FormWizard::steps() on a custom page) has no list to go back to: once saved it
 * opens again from its start, so the wizard closes and the saved values show from the first step, with the notice
 * (owner, 2026-11-19: "once saved or submitted, leave the form"). Record pages go to their list (ReturnsToListing).
 */
trait StartsOverAfterSave
{
    /** @param  array<string, mixed>  $parameters  the page's own query (the open module) */
    protected function startOver(array $parameters = []): void
    {
        $url = static::getUrl($parameters);
        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }
}
