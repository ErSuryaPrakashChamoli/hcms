<?php

namespace App\Filament\Support;

use App\Domain\Career\Models\CareerTrack;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Domain\Workforce\Models\Position;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/** Phase 10 Filament helpers for workforce screens. Every write goes through a workforce domain service. */
final class WorkforceActions
{
    public static function run(callable $callback, string|callable $success): void
    {
        LearningActions::run($callback, $success);
    }

    /** The position definition fields (create, change, draft edit). @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    public static function definitionFields(bool $withCompany = true): array
    {
        return array_values(array_filter([
            TextInput::make('title')->maxLength(255)->helperText('Defaults to the designation name.'),
            Select::make('designation_id')->label('Designation (job / role)')->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Select::make('job_family_id')->label('Job family')->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Select::make('career_track_id')->label('Career track')->options(fn () => CareerTrack::query()->orderBy('name')->pluck('name', 'id')->all()),
            $withCompany ? Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->helperText('Or choose an organisation unit below.') : null,
            Select::make('organisation_node_id')->label('Organisation unit')->options(fn () => TalentActions::nodeOptions())->searchable(),
            Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Select::make('employment_type_id')->label('Employment type')->options(fn () => EmploymentType::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('worker_type')->options(config('peopleos.workforce.worker_types')),
            Select::make('grade_id')->label('Grade')->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('cost_centre_id')->label('Cost centre')->options(fn () => CostCentre::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Select::make('parent_position_id')->label('Parent position')->options(fn () => self::positionOptions())->searchable(),
            Select::make('occupancy_mode')->options(config('peopleos.workforce.occupancy_modes')),
            TextInput::make('headcount')->label('Seats')->numeric()->minValue(1),
            TextInput::make('fte')->label('FTE per seat')->numeric()->minValue(0.01)->step(0.05),
            TextInput::make('fte_capacity')->label('FTE capacity')->numeric()->minValue(0.01)->step(0.05)->helperText('Defaults to seats × FTE per seat.'),
            TextInput::make('standard_hours')->label('Standard hours / week')->numeric(),
        ]));
    }

    public static function effectiveFrom(): DatePicker
    {
        return DatePicker::make('effective_from')->label('Effective from')->native(false)->default(now());
    }

    /** Positions the user may see, by code. */
    public static function positionOptions(?array $statuses = null): array
    {
        return Position::query()->when($statuses, fn ($q, $s) => $q->whereIn('status', $s))->orderBy('code')->limit(1000)->get(['id', 'code', 'title'])
            ->mapWithKeys(fn (Position $p) => [$p->id => "{$p->code} · {$p->title}"])->all();
    }

    /** Filled values only (blank form fields mean "unchanged"). */
    public static function filled(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }
}
