<?php

namespace App\Filament\Support\Forms;

use Filament\Forms\Components\TimePicker;

/** Filament's TimePicker with an accessible trigger (see AccessibleDateTimeTrigger); bound in the container by PeopleOsUi. */
class PeopleTimePicker extends TimePicker
{
    use AccessibleDateTimeTrigger;
}
