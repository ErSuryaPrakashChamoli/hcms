<?php

namespace App\Filament\Support\Actions;

use App\Filament\Support\Actions\Concerns\FollowsFormWizard;
use Filament\Actions\CreateAction;

/** Filament's CreateAction whose drawer form is followed as a wizard when it has sections; bound in the container by PeopleOsUi. */
class PeopleCreateAction extends CreateAction
{
    use FollowsFormWizard;
}
