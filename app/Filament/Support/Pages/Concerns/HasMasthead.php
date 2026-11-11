<?php

namespace App\Filament\Support\Pages\Concerns;

use Illuminate\Contracts\View\View;

/**
 * A page whose view opens with x-pos.masthead: the masthead carries the heading, the brief and the page's header
 * actions (`<x-filament::actions :actions="$this->getCachedHeaderActions()" />`), so Filament's default header is not
 * rendered. getHeading() and getSubheading() still feed the browser title and the masthead.
 */
trait HasMasthead
{
    public function getHeader(): ?View
    {
        return view('filament.shell.empty');
    }
}
