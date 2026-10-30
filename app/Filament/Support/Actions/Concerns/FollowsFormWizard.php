<?php

namespace App\Filament\Support\Actions\Concerns;

use App\Filament\Support\FormWizard;
use Filament\Actions\CreateAction;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A record drawer whose form has sections is followed as a wizard (FormWizard): one section per step, then Review,
 * with the drawer's own Create / Save on the last step. Short forms without sections stay one screen. An action
 * given its own steps keeps them. The form, its validation and the action's save are unchanged.
 */
trait FollowsFormWizard
{
    /** Whether the drawer's form is a wizard; worked out from the form itself. */
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
            return parent::getSchema($schema);
        }
        $schema = $this->plainSchema($schema);
        $this->formWizard = FormWizard::contentSteps($schema) >= FormWizard::DRAWER_MINIMUM;
        if (! $this->formWizard) {
            return $schema;
        }
        $mode = $this instanceof CreateAction ? FormWizard::CREATE : FormWizard::EDIT;

        return $schema->columns(null)->components([
            FormWizard::wizard(FormWizard::steps($schema, $mode, Str::ucfirst((string) ($this->getModelLabel() ?: 'Details'))), $mode, 'drawer')
                ->cancelAction($this->getModalCancelAction())
                ->submitAction($this->getModalSubmitAction())
                ->alpineSubmitHandler("\$wire.{$this->getLivewireCallMountedActionName()}()")
                ->disabled($this->isSchemaDisabled()),
        ]);
    }

    private function isFormWizard(): bool
    {
        if ($this->buildingPlainForm) {
            return false;
        }
        if ($this->formWizard === null) {
            // Asked before the drawer's form was built in this request: look at the form without keeping it.
            $livewire = $this->getLivewire();
            $this->formWizard = FormWizard::contentSteps($this->plainSchema(Schema::make($livewire instanceof HasSchemas ? $livewire : null))) >= FormWizard::DRAWER_MINIMUM;
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
