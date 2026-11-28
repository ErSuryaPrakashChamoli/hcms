<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Employment\Actions\ChangeStatutoryApplicabilityAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\People\Exceptions\ProfileChangeRefused;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\DocumentCopies;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\SavesCustomFields;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * The Edit page of an employee: one wizard holding every detail of the record (owner, 2026-11-27: one place for each
 * detail), its steps named and ordered as the Profile areas of the 360 (EmployeeForm), then Review. Person and employment
 * fields save on the records as before, and changed statutory details through ChangeStatutoryIdentityAction /
 * ChangeStatutoryApplicabilityAction, all in the page's one transaction. Nothing is written for a step left as it was.
 * Position and reporting lines change through Transfer / promote and Change manager, opened from the Employment step.
 * The lists inside the steps (addresses, bank accounts, government IDs, family, education, insurance) save each entry
 * as it is made. There are no record tabs under the form: every other list is on the 360, under its view.
 */
class EditEmployee extends PeopleEditRecord
{
    use SavesCustomFields {
        SavesCustomFields::mutateFormDataBeforeFill as fillCustomFields;
    }

    protected static string $resource = EmployeeResource::class;

    /** One transaction for the whole save: a refused position, manager or statutory change leaves nothing half-saved. */
    protected ?bool $hasDatabaseTransactions = true;

    /** The step the page opens on (?step=statutory etc., from the 360 Profile view's Edit buttons). */
    #[Locked]
    public ?string $openStep = null;

    /** Step links made before the steps took the Profile area names (?step=person, ?step=additional-information). */
    private const STEP_ALIASES = ['person' => 'personal', 'additional-information' => 'other-details', 'bank-accounts' => 'bank-payment', 'addresses' => 'contact-addresses', 'position-reporting' => 'employment'];

    public function mount(int|string $record): void
    {
        $step = is_string($step = request()->query('step')) ? Str::slug($step) : null;
        $this->openStep = $step === null ? null : (self::STEP_ALIASES[$step] ?? $step);

        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);
        $wizard = $schema->getComponents(withHidden: true)[0] ?? null;
        if ($wizard instanceof Wizard && filled($this->openStep)) {
            $wizard->startOnStep(function (Wizard $component): int {
                foreach (array_values($component->getChildSchema()->getComponents()) as $i => $step) {
                    if (Str::slug((string) $step->getLabel()) === $this->openStep) {
                        return $i + 1;
                    }
                }

                return 1;
            });
        }

        return $schema;
    }

    protected function recordPageNote(): string
    {
        return parent::recordPageNote().' Entries in the lists inside a step (addresses, government IDs, bank accounts, family, education, insurance) are saved as you make them.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = $this->fillCustomFields($data);
        /** @var Employee $record */
        $record = $this->getRecord();
        $user = auth()->user();

        if ($user?->can('updateSensitive', $record)) {
            // The form carries the statutory numbers to the browser: recorded as "Edit statutory details" records it.
            app(SensitiveAccessAuditor::class)->recordView($record, 'statutory', 'edit');
            $data['statutory'] = $this->statutoryToday($record);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Employee $record */
        $reason = AuditReasonField::extract($data);
        $this->extractCustomFields($data);
        $statutory = $data['statutory'] ?? null;
        unset($data['statutory']);

        $record->withAuditReason($reason)->update($data);
        $record->person->withAuditReason($reason);
        $this->persistCustomFields($record, $reason);

        try {
            if (is_array($statutory)) {
                $this->saveStatutory($record, $statutory, $reason);
            }
        } catch (InvalidArgumentException|ApplicabilityRefusedException|ProfileChangeRefused $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (Halt) {
            // A refusal already shown (ProfileChangeActions): nothing of this save is kept.
            throw (new Halt)->rollBackDatabaseTransaction();
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function statutoryToday(Employee $record): array
    {
        $detail = EmployeeStatutoryDetail::query()->where('employee_id', $record->getKey())->first();

        return $detail?->only([...ChangeStatutoryIdentityAction::FIELDS, ...ChangeStatutoryApplicabilityAction::FIELDS])
            ?? ['pf_applicable' => true, 'pt_applicable' => true, 'esic_applicable' => false];
    }

    /**
     * Only what differs from the record goes to the statutory actions (each change is an audited event). Card copies are
     * filed with the documents first, in the same transaction, as "Edit statutory details" did (DocumentCopies).
     */
    private function saveStatutory(Employee $record, array $statutory, ?string $reason): void
    {
        DocumentCopies::saveWith($record, $statutory, EmployeeForm::STATUTORY_COPIES, $reason, function () use ($record, &$statutory, $reason): void {
            $this->saveStatutoryNumbers($record, $statutory, $reason);
        });
    }

    private function saveStatutoryNumbers(Employee $record, array $statutory, ?string $reason): void
    {
        $today = $this->statutoryToday($record);
        $changed = fn (array $fields) => collect($fields)->filter(fn (string $f) => array_key_exists($f, $statutory) && $this->normalised($statutory[$f]) !== $this->normalised($today[$f] ?? null))->all();

        if (($identity = $changed(ChangeStatutoryIdentityAction::FIELDS)) !== []) {
            app(ChangeStatutoryIdentityAction::class)->handle($record, array_intersect_key($statutory, array_flip($identity)), auth()->user(), $reason);
        }
        if (($applicability = $changed(ChangeStatutoryApplicabilityAction::FIELDS)) !== []) {
            app(ChangeStatutoryApplicabilityAction::class)->handle($record, array_intersect_key($statutory, array_flip($applicability)), auth()->user(), $reason);
        }
    }

    private function normalised(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value,
            $value === null, $value === '' => null,
            in_array($value, ['0', '1', 0, 1], true) && ! is_string($value) => (bool) $value,
            default => (string) $value,
        };
    }
}
