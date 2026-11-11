<?php

namespace App\Filament\Support\Actions;

use App\Filament\Support\Actions\Concerns\FollowsFormWizard;
use Filament\Actions\BulkAction;

/** Filament's BulkAction whose modal form is followed as a wizard once it asks for enough to fill in; bound in the container by PeopleOsUi (Streamline S6). */
class PeopleBulkAction extends BulkAction
{
    use FollowsFormWizard;
}
