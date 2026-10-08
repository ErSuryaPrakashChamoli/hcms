<?php

namespace App\Filament\Support\Forms;

use Filament\Forms\Components\DatePicker;

/** Filament's DatePicker with an accessible trigger (see AccessibleDateTimeTrigger); bound in the container by PeopleOsUi. */
class PeopleDatePicker extends DatePicker
{
    use AccessibleDateTimeTrigger;
}
