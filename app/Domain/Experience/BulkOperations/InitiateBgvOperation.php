<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Bgv\Services\Bgv;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity WO-1 (§23.2.2 #11, §23.3.2 #5 bulk "Initiate BGV"): one background check per employee through
 * Bgv::initiate. Consent is per person: each employee's own consent document (of the chosen document type, not rejected
 * or archived) is attached; an employee without one is refused, never initiated on a blanket declaration.
 */
final class InitiateBgvOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.initiate_bgv';
    }

    public function label(): string
    {
        return 'Initiate background check';
    }

    public function description(): string
    {
        return 'Opens one verification case per employee with the chosen checks. Each employee\'s own consent document must be on file; employees without one, or with a case already open, are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'bgv.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-shield-check';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return [
            Select::make('provider')->options(collect(config('peopleos.bgv.providers'))->map(fn ($p) => $p['label'])->all())->default('manual')->required(),
            CheckboxList::make('checks')->options(config('peopleos.bgv.check_types'))->default(config('peopleos.bgv.default_checks'))->columns(2)->required(),
            Select::make('consent_document_type_id')->label('Consent document type')->required()
                ->options(fn () => DocumentType::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Each employee\'s own document of this type is attached as their consent.'),
            Textarea::make('notes')->maxLength(1000),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $provider = (string) ($data['provider'] ?? 'manual');
        if (! array_key_exists($provider, config('peopleos.bgv.providers'))) {
            throw new RuntimeException('Choose a verification provider.');
        }
        $checks = array_values(array_intersect(array_keys(config('peopleos.bgv.check_types')), (array) ($data['checks'] ?? [])));
        if ($checks === []) {
            throw new RuntimeException('Choose at least one check.');
        }
        $type = (int) ($data['consent_document_type_id'] ?? 0);
        if ($type === 0 || ! DocumentType::query()->whereKey($type)->exists()) {
            throw new RuntimeException('Choose the document type that holds each employee\'s consent.');
        }

        return ['provider' => $provider, 'checks' => $checks, 'consent_document_type_id' => $type, 'notes' => filled($data['notes'] ?? null) ? mb_substr((string) $data['notes'], 0, 1000) : null];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $consent = $this->consentDocument($record, (int) $input['consent_document_type_id']);
        if ($consent === null) {
            $type = DocumentType::query()->whereKey($input['consent_document_type_id'])->value('name') ?? 'consent';
            throw new RuntimeException('No '.$type.' document on file (each employee\'s own consent is needed).');
        }
        $case = app(Bgv::class)->initiate($record, $input['checks'], $input['provider'], false, $consent, $input['notes'] ?? null);

        return "Case #{$case->id} initiated";
    }

    private function consentDocument(Model $employee, int $typeId): ?EmployeeDocument
    {
        return $typeId === 0 ? null : EmployeeDocument::query()->where('employee_id', $employee->getKey())->where('document_type_id', $typeId)
            ->whereNotIn('status', ['rejected', 'archived'])->latest('id')->first();
    }
}
