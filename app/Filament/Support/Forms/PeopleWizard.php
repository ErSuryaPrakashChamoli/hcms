<?php

namespace App\Filament\Support\Forms;

use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Exceptions\Halt;

/**
 * Filament's wizard for record forms (FormWizard). Steps that can be opened from the step list (editing a record)
 * still check the step being left when Next is pressed: Filament skips that check for skippable wizards.
 */
class PeopleWizard extends Wizard
{
    #[ExposedLivewireMethod]
    public function nextStep(int $currentStepIndex): void
    {
        $steps = array_values($this->getChildSchema()->getComponents());

        /** @var Step $currentStep */
        $currentStep = $steps[$currentStepIndex];

        /** @var ?Step $nextStep */
        $nextStep = $steps[$currentStepIndex + 1] ?? null;
        $this->currentStepIndex($currentStepIndex + 1);

        try {
            $currentStep->callBeforeValidation();
            $currentStep->getChildSchema()->validate();
            $currentStep->callAfterValidation();
            $nextStep?->fillStateWithNull();
        } catch (Halt) {
            return;
        }

        $this->getLivewire()->dispatch('next-wizard-step', key: $this->getKey());
    }
}
