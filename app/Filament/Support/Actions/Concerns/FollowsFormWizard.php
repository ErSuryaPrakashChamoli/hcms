<?php

namespace App\Filament\Support\Actions\Concerns;

use App\Filament\Support\FormWizard;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Forms in drawers and modals are followed as a wizard (FormWizard): one section per step (long runs of fields split
 * into steps), then Review, with the action's own button on the last step. A drawer or modal is one once it has
 * sections or asks for FormWizard::MODAL_MINIMUM_FIELDS fields or more (FormWizard::wants()); a short note, status
 * change or quick edit stays one screen, and a read-only modal stays as it is.
 * An action given its own steps keeps them, in the same wizard style. The form, its validation and the action's save
 * are unchanged.
 */
trait FollowsFormWizard
{
    /** Whether the form is a wizard; worked out from the form itself. */
    private ?bool $formWizard = null;

    /** Set while the plain form is built, so Filament does not treat its components as steps. */
    private bool $buildingPlainForm = false;

    public function isWizard(): bool
    {
        return parent::isWizard() || $this->isFormWizard();
    }

    public function getSchema(Schema $schema): ?Schema
    {
        if (parent::isWizard()) {
            // The action's own steps, in the wizard style every other form has.
            $built = parent::getSchema($schema);
            foreach ($built?->getComponents(withHidden: true) ?? [] as $component) {
                if ($component instanceof Wizard) {
                    $component->extraAttributes(['class' => 'pos-wizard pos-wizard-drawer'], merge: true);
                }
            }

            return $built;
        }
        $schema = $this->plainSchema($schema);
        $this->formWizard = $this->wantsFormWizard($schema);
        if (! $this->formWizard) {
            return $schema;
        }
        $mode = match (true) {
            $this instanceof CreateAction => FormWizard::CREATE,
            $this instanceof EditAction => FormWizard::EDIT,
            default => FormWizard::ACTION,
        };

        return $schema->columns(null)->components([
            FormWizard::wizard(FormWizard::steps($schema, $mode, $this->wizardSubject()), $mode, 'drawer')
                ->cancelAction($this->getModalCancelAction())
                ->submitAction($this->getModalSubmitAction())
                ->alpineSubmitHandler("\$wire.{$this->getLivewireCallMountedActionName()}()")
                ->disabled($this->isSchemaDisabled()),
        ]);
    }

    /** A form with sections, or with enough to fill in, is a wizard; a short note, status change or quick edit stays one screen. */
    private function wantsFormWizard(?Schema $schema): bool
    {
        if ($this instanceof CreateAction || $this instanceof EditAction) {
            return FormWizard::wants($schema);
        }
        // Not read-only modals, nor the small modals of a form's own controls (a select's "create option", a rich editor's block).
        if ($schema === null || $this->isSchemaDisabled() || $this->getSchemaComponent() !== null) {
            return false;
        }

        return FormWizard::fieldCount($schema) >= 2 && FormWizard::wants($schema);
    }

    /** What the first step is named after: the record for drawers; "Details" in other modals (their heading names the action already). */
    private function wizardSubject(): string
    {
        if ($this instanceof CreateAction || $this instanceof EditAction) {
            return Str::ucfirst((string) ($this->getModelLabel() ?: 'Details'));
        }

        return 'Details';
    }

    private function isFormWizard(): bool
    {
        if ($this->buildingPlainForm) {
            return false;
        }
        if ($this->formWizard === null) {
            // Asked before the form was built in this request: look at the form without keeping it.
            $livewire = $this->getLivewire();
            $this->formWizard = $this->wantsFormWizard($this->plainSchema(Schema::make($livewire instanceof HasSchemas ? $livewire : null)));
        }

        return $this->formWizard;
    }

    private function plainSchema(Schema $schema): ?Schema
    {
        $this->buildingPlainForm = true;
        try {
            return parent::getSchema($schema);
        } finally {
            $this->buildingPlainForm = false;
        }
    }
}
