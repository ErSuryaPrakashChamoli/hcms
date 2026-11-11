<?php

namespace App\Filament\Support\Actions;

use App\Filament\Support\Actions\Concerns\FollowsFormWizard;
use Filament\Actions\Action;

/** Filament's Action whose modal form is followed as a wizard once it asks for enough to fill in; bound in the container by PeopleOsUi (Streamline S6). */
class PeopleAction extends Action
{
    use FollowsFormWizard;
}
