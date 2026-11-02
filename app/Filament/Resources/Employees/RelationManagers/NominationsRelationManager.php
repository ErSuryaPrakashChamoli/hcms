<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\People\Actions\ChangeNominationsAction;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonNomination;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\ProfileChangeActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * HROne parity MS-4 (matrix 22 §22.2.3 #10, #12–14): who receives each benefit (PF, gratuity, insurance …) and in what
 * share. A benefit's nominations are set together ("Set nominees"), so ChangeNominationsAction checks that the shares
 * total 100% and that a minor nominee has a guardian before anything is saved. Family section of the CA-6 matrix.
 */
class NominationsRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'nominations';

    protected static string $profileSection = 'family';

    protected static ?string $title = 'Nominations';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('benefit')->badge()->formatStateUsing(fn (?string $state) => app(OptionLists::class)->label('nomination_benefits', $state)),
                TextColumn::make('familyMember.name')->label('Nominee')
                    ->description(fn (PersonNomination $record) => $record->familyMember ? app(OptionLists::class)->label('family_relations', $record->familyMember->relation) : null),
                TextColumn::make('share')->suffix('%'),
                TextColumn::make('guardian_name')->label('Guardian')->placeholder('—')
                    ->description(fn (PersonNomination $record) => $record->guardian_relation),
                TextColumn::make('address')->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remarks')->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('familyMember'))
            ->description(fn () => $this->incompleteNote())
            ->headerActions([$this->setAction('setNominations', 'Set nominees')])
            ->recordActions([$this->setAction('changeBenefit', 'Change', fromRecord: true)]);
    }

    /** Benefits whose shares do not total 100% (for example after a nominee's record changed), so HR can correct them. */
    private function incompleteNote(): ?string
    {
        $labels = app(OptionLists::class);
        $off = collect(ChangeNominationsAction::totals($this->getOwnerRecord()))->filter(fn (float $total) => abs($total - 100) > 0.001)
            ->map(fn (float $total, string $benefit) => $labels->label('nomination_benefits', $benefit).' ('.rtrim(rtrim(number_format($total, 2), '0'), '.').'%)');

        return $off->isEmpty() ? null : 'Shares do not total 100% for: '.$off->implode(', ').'.';
    }

    private function setAction(string $name, string $label, bool $fromRecord = false): Action
    {
        return Action::make($name)->label($label)->icon($fromRecord ? 'heroicon-m-pencil-square' : 'heroicon-m-user-plus')
            ->visible(fn () => auth()->user()?->hasPermission(ChangeNominationsAction::PERMISSION) ?? false)
            ->modalHeading('Nominees for a benefit')
            ->modalDescription('Choose who receives the benefit and in what share. The shares must total 100%. A nominee under '.((int) config('peopleos.people.nominee_majority_age', 18)).' needs a guardian.')
            ->fillForm(fn (?PersonNomination $record) => $fromRecord && $record ? ['benefit' => $record->benefit, 'rows' => $this->rowsFor($record->benefit)] : ['rows' => []])
            ->schema([
                Select::make('benefit')->label('Benefit')->required()->live()
                    ->options(fn () => app(OptionLists::class)->options('nomination_benefits'))
                    ->disabled($fromRecord)->dehydrated()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('rows', filled($state) ? $this->rowsFor($state) : [])),
                Repeater::make('rows')->label('Nominees')->columns(2)->addActionLabel('Add a nominee')->defaultItems(0)
                    ->helperText('Leave no rows to remove the nominations for this benefit.')
                    ->schema([
                        Select::make('family_member_id')->label('Family member')->required()->live()
                            ->options(fn () => $this->members()->mapWithKeys(fn (PersonFamilyMember $m) => [$m->id => $m->name.' ('.app(OptionLists::class)->label('family_relations', $m->relation).')'])->all()),
                        TextInput::make('share')->label('Share')->numeric()->required()->minValue(0.01)->maxValue(100)->suffix('%'),
                        TextInput::make('guardian_name')->label('Guardian’s name')->maxLength(255)
                            ->visible(fn (Get $get) => $this->isMinor($get('family_member_id')))->required(fn (Get $get) => $this->isMinor($get('family_member_id'))),
                        TextInput::make('guardian_relation')->label('Guardian’s relation to the nominee')->maxLength(64)
                            ->visible(fn (Get $get) => $this->isMinor($get('family_member_id')))->required(fn (Get $get) => $this->isMinor($get('family_member_id'))),
                        TextInput::make('address')->label('Nominee’s address')->maxLength(500)->columnSpanFull(),
                        TextInput::make('remarks')->maxLength(255)->columnSpanFull(),
                    ]),
                AuditReasonField::make(),
            ])
            ->action(function (array $data): void {
                $reason = AuditReasonField::extract($data);
                ProfileChangeActions::run(fn () => app(ChangeNominationsAction::class)->replace($this->getOwnerRecord(), (string) $data['benefit'], array_values((array) ($data['rows'] ?? [])), auth()->user(), $reason));
            });
    }

    /** @return list<array<string, mixed>> the saved nominations of a benefit, as form rows */
    private function rowsFor(string $benefit): array
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();

        return PersonNomination::query()->where('person_id', $employee->person_id)->where('benefit', $benefit)->orderBy('id')->get()
            ->map(fn (PersonNomination $n) => ['family_member_id' => $n->family_member_id, 'share' => (float) $n->share, 'address' => $n->address, 'remarks' => $n->remarks,
                'guardian_name' => $n->guardian_name, 'guardian_relation' => $n->guardian_relation])->values()->all();
    }

    /** @return Collection<int, PersonFamilyMember> */
    private function members(): Collection
    {
        return PersonFamilyMember::query()->where('person_id', $this->getOwnerRecord()->person_id)->orderBy('name')->get();
    }

    private function isMinor(mixed $memberId): bool
    {
        return filled($memberId) && (bool) $this->members()->firstWhere('id', (int) $memberId)?->isMinor();
    }
}
