<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\InsuredMember;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Services\InsuranceRecords;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\ProfileChangeActions;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity MS-5 (matrix 22 §22.2.5 #1–7, §22.2.3 #3): the employee's health insurance on the Employee 360 — one row
 * per policy (insurer, policy number, sum assured, validity, e-card) with the people it covers. Sensitive like the Bank and
 * Passport & licence tabs: shown only with `viewSensitive` and when the CA-6 matrix shows the health insurance section;
 * opening the tab is audited, member ids are masked (last four), revealing one needs a stated purpose and is audited
 * again. The form is a step wizard (policy, cover and e-card, insured members, review); every write goes through
 * InsuranceRecords (employee.sensitive.update, scope, the person's own family members).
 */
class InsuranceRelationManager extends RelationManager
{
    protected static string $relationship = 'insurancePolicies';

    protected static ?string $title = 'Health insurance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('viewSensitive', $ownerRecord) ?? false)
            && app(ProfileSectionVisibility::class)->allows(auth()->user(), $ownerRecord, 'health_insurance');
    }

    public function mount(): void
    {
        app(SensitiveAccessAuditor::class)->recordView($this->getOwnerRecord(), 'health_insurance');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Policy')->columns(2)->schema([
                TextInput::make('insurer_name')->label('Insurance company')->required()->maxLength(255),
                TextInput::make('insurer_code')->label('Company code')->maxLength(64),
                TextInput::make('policy_number')->label('Policy number')->required()->maxLength(64),
            ]),
            Section::make('Cover and e-card')->columns(2)->schema([
                TextInput::make('sum_assured')->label('Sum assured')->numeric()->minValue(0),
                DatePicker::make('issued_on')->label('Issue date')->native(false)->maxDate(now()),
                DatePicker::make('valid_until')->label('Valid until')->native(false)->afterOrEqual('issued_on'),
                FileUpload::make('ecard_upload')->label('E-card')->disk(InsuranceRecords::disk())->directory(InsuranceRecords::UPLOAD_AREA)->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('ecard_upload_name')
                    ->helperText(fn (?InsurancePolicy $record) => $record?->hasEcard() ? 'On file: '.$record->ecard_name.'. A new file replaces it.' : 'PDF or image of the insurer’s card.'),
            ]),
            Section::make('Insured members')->schema([
                Repeater::make('members')->hiddenLabel()->columns(2)->addActionLabel('Add an insured person')->defaultItems(0)->reorderable(false)
                    ->helperText('The employee or their family members (add family members on the Family tab first). Name and relation come from the profile.')
                    ->schema([
                        Hidden::make('record_id'),
                        Select::make('family_member_id')->label('Insured person')->required()->live()->options(fn () => $this->insurable()),
                        TextInput::make('member_id')->label('Member id')->maxLength(64)->password()->revealable()
                            ->helperText(fn (Get $get) => filled($get('record_id')) ? 'Leave blank to keep the member id on file.' : 'Stored encrypted; lists show the last four characters.'),
                        DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->maxDate(now())->helperText('Blank: as on the profile.'),
                        Select::make('gender')->options((array) config('peopleos.people.genders')),
                        Toggle::make('is_parents_cover')->label('Under the parents’ cover')
                            ->visible(fn (Get $get) => filled($get('family_member_id')) && $get('family_member_id') !== InsuredMember::SELF),
                    ]),
            ]),
            AuditReasonField::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['members' => fn ($members) => $members->withoutGlobalScope(AccessScope::class)->orderBy('id')]))
            ->columns([
                TextColumn::make('insurer_name')->label('Insurance company')->description(fn (InsurancePolicy $record) => $record->insurer_code),
                TextColumn::make('policy_number')->label('Policy number'),
                TextColumn::make('sum_assured')->label('Sum assured')->numeric(2)->placeholder('—'),
                TextColumn::make('issued_on')->label('Issue date')->date()->placeholder('—'),
                TextColumn::make('valid_until')->label('Valid until')->date()->placeholder('—')
                    ->color(fn (InsurancePolicy $record) => $record->valid_until === null ? null : ($record->valid_until->lt(today()) ? 'danger' : ($record->valid_until->lte(today()->addDays(30)) ? 'warning' : null))),
                TextColumn::make('members')->label('Insured')->wrap()
                    ->state(fn (InsurancePolicy $record) => $record->members->map(fn (InsuredMember $m) => self::memberLine($m))->implode('; ') ?: '—'),
                TextColumn::make('ecard_name')->label('E-card')->placeholder('—')->limit(30)
                    ->url(fn (InsurancePolicy $record) => $record->hasEcard() ? ParityFileController::url('insurance-ecard', $record, 0) : null)->openUrlInNewTab(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add a policy')->using(fn (array $data, RelationManager $livewire) => $this->persist(null, $data, $livewire->getOwnerRecord())),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('Reveal')
                    ->icon('heroicon-m-eye')
                    ->visible(fn (InsurancePolicy $record) => $record->members->contains(fn (InsuredMember $m) => $m->maskedMemberId() !== null))
                    ->schema(fn (InsurancePolicy $record) => [
                        Select::make('member')->label('Member')->required()
                            ->options($record->members->filter(fn (InsuredMember $m) => $m->maskedMemberId() !== null)->mapWithKeys(fn (InsuredMember $m) => [$m->id => self::memberLine($m)])->all()),
                        Textarea::make('purpose')->required()->maxLength(500)->helperText('Recorded in the audit trail with your name and time.'),
                    ])
                    ->action(function (InsurancePolicy $record, array $data, RelationManager $livewire) {
                        $member = $record->members->firstWhere('id', (int) $data['member']);
                        if ($member === null) {
                            return;
                        }
                        app(SensitiveAccessAuditor::class)->recordView($livewire->getOwnerRecord(), 'insured_member:'.$member->id, $data['purpose']);

                        Notification::make()->title($member->name.' ('.$member->relationLabel().')')->body('Member id: '.$member->member_id)->persistent()->send();
                    }),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, InsurancePolicy $record) => $data + ['members' => $record->members->map(fn (InsuredMember $m) => [
                        'record_id' => $m->id, 'family_member_id' => $m->family_member_id ?? InsuredMember::SELF, 'member_id' => null,
                        'date_of_birth' => $m->date_of_birth?->toDateString(), 'gender' => $m->gender, 'is_parents_cover' => (bool) $m->is_parents_cover,
                    ])->values()->all()])
                    ->using(fn (InsurancePolicy $record, array $data, RelationManager $livewire) => $this->persist($record, $data, $livewire->getOwnerRecord())),
                DeleteAction::make()->using(fn (InsurancePolicy $record, RelationManager $livewire) => ProfileChangeActions::run(fn () => app(InsuranceRecords::class)->remove($livewire->getOwnerRecord(), $record, auth()->user()) ?? true)),
            ]);
    }

    /** Save the policy and its members, then the e-card, in one go (a refused e-card leaves nothing half saved). */
    private function persist(?InsurancePolicy $record, array $data, Employee $employee): InsurancePolicy
    {
        $reason = AuditReasonField::extract($data);
        $upload = filled($data['ecard_upload'] ?? null) ? (string) (is_array($data['ecard_upload']) ? array_values($data['ecard_upload'])[0] : $data['ecard_upload']) : null;
        $name = $data['ecard_upload_name'] ?? null;
        $name = is_array($name) ? (array_values($name)[0] ?? null) : $name;

        return ProfileChangeActions::run(fn () => DB::transaction(function () use ($record, $data, $employee, $reason, $upload, $name) {
            $records = app(InsuranceRecords::class);
            $policy = $records->save($employee, $record, $data, array_values((array) ($data['members'] ?? [])), auth()->user(), $reason, removeOthers: true);
            if ($upload !== null) {
                $records->attachEcard($employee, $policy, $upload, $name, auth()->user(), $reason);
            }

            return $policy;
        }));
    }

    /** @return array<string, string> "self" (the employee) and the person's family members */
    private function insurable(): array
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();
        $relations = app(OptionLists::class);

        return [InsuredMember::SELF => (Person::query()->find($employee->person_id)?->full_name ?? $employee->employee_code).' (self)']
            + PersonFamilyMember::query()->where('person_id', $employee->person_id)->orderBy('name')->get()
                ->mapWithKeys(fn (PersonFamilyMember $m) => [(string) $m->id => $m->name.' ('.$relations->label('family_relations', $m->relation).')'])->all();
    }

    /** "Kiran Rao (spouse) ••••1234 · parents’ cover" */
    private static function memberLine(InsuredMember $member): string
    {
        return $member->name.' ('.mb_strtolower($member->relationLabel()).')'.($member->maskedMemberId() ? ' '.$member->maskedMemberId() : '').($member->is_parents_cover ? ' · parents’ cover' : '');
    }
}
