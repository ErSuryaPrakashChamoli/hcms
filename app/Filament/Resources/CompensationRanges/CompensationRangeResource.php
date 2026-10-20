<?php

namespace App\Filament\Resources\CompensationRanges;

use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationRanges;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Filament\Resources\CompensationRanges\Pages\ManageCompensationRanges;
use App\Filament\Support\CompensationActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 11 §8: pay ranges per grade (minimum / midpoint / maximum), optionally narrowed to a structure,
 * company, job family or designation. Drafts are edited, submitted and approved by a second person;
 * approved ranges never change (a later version closes the earlier one).
 */
class CompensationRangeResource extends Resource
{
    protected static ?string $model = CompensationRange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Pay ranges';

    protected static ?int $navigationSort = 30;

    /** @return list<Field> */
    public static function fields(): array
    {
        $midpointRequired = config('peopleos.compensation.range_model', 'min_mid_max') === 'min_mid_max';

        return [
            Select::make('grade_id')->label('Grade')->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
            Select::make('salary_structure_id')->label('Structure (optional)')->options(fn () => SalaryStructure::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('company_id')->label('Company (optional)')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('job_family_id')->label('Job family (optional)')->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('designation_id')->label('Designation (optional)')->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Select::make('currency')->options(fn () => array_combine(config('peopleos.compensation.currencies'), config('peopleos.compensation.currencies')))->default(config('peopleos.settings.tenant.base_currency', 'INR'))->required(),
            Select::make('frequency')->options(['annual' => 'Annual', 'monthly' => 'Monthly'])->default('annual')->required(),
            TextInput::make('minimum')->numeric()->minValue(0)->required(),
            TextInput::make('midpoint')->numeric()->minValue(0)->required($midpointRequired)->helperText($midpointRequired ? null : 'Optional under the min / max range model.'),
            TextInput::make('maximum')->numeric()->minValue(0)->required(),
            DatePicker::make('effective_from')->native(false)->required(),
            DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            Textarea::make('notes')->rows(2),
        ];
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New range')->icon(Heroicon::OutlinedPlus)->visible(fn () => auth()->user()->can('compensation.configure'))
                ->schema(self::fields())
                ->action(fn (array $data) => CompensationActions::run(fn () => app(CompensationRanges::class)->create($data, auth()->user()), 'Range saved as a draft')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['grade', 'company', 'designation', 'jobFamily', 'structure']))
            ->columns([
                TextColumn::make('grade.name')->label('Grade')->sortable(),
                TextColumn::make('scope')->label('Applies to')->state(fn (CompensationRange $record) => collect([$record->structure?->code, $record->company?->name, $record->jobFamily?->name, $record->designation?->name])->filter()->implode(' · ') ?: 'Whole grade'),
                TextColumn::make('version')->label('v'),
                TextColumn::make('minimum')->numeric(2),
                TextColumn::make('midpoint')->numeric(2)->placeholder('—'),
                TextColumn::make('maximum')->numeric(2),
                TextColumn::make('currency')->description(fn (CompensationRange $record) => $record->frequency),
                TextColumn::make('effective_from')->label('From')->date(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('Open'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationRange::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'pending_approval' => 'warning', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('grade_id')->label('Grade')->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(CompensationRange::STATUSES),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([ActionGroup::make([
                Action::make('edit')->label('Edit draft')->icon('heroicon-m-pencil-square')
                    ->visible(fn (CompensationRange $record) => $record->status === 'draft' && auth()->user()->can('compensation.configure'))
                    ->fillForm(fn (CompensationRange $record) => $record->only(['grade_id', 'salary_structure_id', 'company_id', 'job_family_id', 'designation_id', 'currency', 'frequency', 'minimum', 'midpoint', 'maximum', 'effective_from', 'effective_to', 'notes']))
                    ->schema(self::fields())
                    ->action(fn (CompensationRange $record, array $data) => CompensationActions::run(fn () => app(CompensationRanges::class)->update($record, $data, auth()->user()), 'Draft saved')),
                Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                    ->visible(fn (CompensationRange $record) => $record->status === 'draft' && auth()->user()->can('compensation.configure'))
                    ->action(fn (CompensationRange $record) => CompensationActions::run(fn () => app(CompensationRanges::class)->submit($record, auth()->user()), 'Submitted for approval')),
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                    ->visible(fn (CompensationRange $record) => $record->status === 'pending_approval' && auth()->user()->can('compensation.approve') && (int) $record->prepared_by !== (int) auth()->id())
                    ->action(fn (CompensationRange $record) => CompensationActions::run(fn () => app(CompensationRanges::class)->approve($record, auth()->user()), 'Range approved')),
                Action::make('return')->label('Return to draft')->icon('heroicon-m-arrow-uturn-left')
                    ->visible(fn (CompensationRange $record) => $record->status === 'pending_approval' && auth()->user()->can('compensation.approve'))
                    ->schema([Textarea::make('note')->required()])
                    ->action(fn (CompensationRange $record, array $data) => CompensationActions::run(fn () => app(CompensationRanges::class)->returnToDraft($record, auth()->user(), $data['note']), 'Returned to draft')),
                Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('danger')
                    ->visible(fn (CompensationRange $record) => in_array($record->status, ['draft', 'pending_approval', 'superseded'], true) && auth()->user()->can('compensation.configure'))
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (CompensationRange $record, array $data) => CompensationActions::run(fn () => app(CompensationRanges::class)->archive($record, auth()->user(), $data['reason']), 'Range archived')),
            ])])
            ->emptyStateHeading('No pay ranges');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompensationRanges::route('/')];
    }
}
