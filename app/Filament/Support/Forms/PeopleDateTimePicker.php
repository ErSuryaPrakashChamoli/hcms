<?php

namespace App\Filament\Support\Forms;

use Filament\Forms\Components\DateTimePicker;

/** Filament's DateTimePicker with an accessible trigger (see AccessibleDateTimeTrigger); bound in the container by PeopleOsUi. */
class PeopleDateTimePicker extends DateTimePicker
{
    use AccessibleDateTimeTrigger;
}
