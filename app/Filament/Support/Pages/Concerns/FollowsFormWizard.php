<?php

namespace App\Filament\Support\Pages\Concerns;

use App\Filament\Support\FormWizard;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A record page's form is followed as a wizard (FormWizard): one section per step, then Review, with Previous and
 * Next and the page's own Create / Save on the last step. The page saves exactly as before; only the layout steps.
 * A page that builds its own wizard (Filament's HasWizard, e.g. the hire wizard) keeps it.
 */
trait FollowsFormWizard
{
    /** Whether this page's form became a wizard; worked out when the form is built. */
    private ?bool $formWizard = null;

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);
        $mode = $this instanceof CreateRecord ? FormWizard::CREATE : FormWizard::EDIT;
        $steps = $this->usesFormWizard()
            ? FormWizard::steps($schema, $mode, Str::ucfirst((string) static::getResource()::getModelLabel()))
            : null;
        // Decided before the page's actions are made: they submit differently without a form wrapper.
        $this->formWizard = $steps !== null;
        if ($steps === null) {
            return $schema;
        }

        return $schema->columns(null)->components([
            FormWizard::wizard($steps, $mode, 'page')
                ->cancelAction($this->getCancelFormAction())
                ->submitAction($this->getSubmitFormAction())
                ->alpineSubmitHandler("\$wire.{$this->getSubmitFormLivewireMethodName()}()"),
        ]);
    }

    public function isFormWizard(): bool
    {
        if ($this->formWizard === null) {
            $this->getSchema('form');
        }

        return (bool) $this->formWizard;
    }

    public function hasFormWrapper(): bool
    {
        return ! $this->isFormWizard() && parent::hasFormWrapper();
    }

    public function getFormContentComponent(): Component
    {
        // The wizard's last step carries the page's submit and cancel buttons.
        return $this->isFormWizard() ? EmbeddedSchema::make('form') : parent::getFormContentComponent();
    }

    protected function usesFormWizard(): bool
    {
        return ! method_exists($this, 'getWizardComponent');
    }
}
