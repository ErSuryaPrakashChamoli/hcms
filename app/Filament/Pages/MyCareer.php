<?php

namespace App\Filament\Pages;

use App\Domain\Career\Models\CareerAspirationEntry;
use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Career\Services\CareerMovements;
use App\Domain\Career\Services\CareerProfiles;
use App\Domain\Career\Services\RoleGaps;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\Readiness;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Phase 9 employee career page: my career profile and sharing choices, aspirations (history kept),
 * career goals, mobility interest, factual gaps for the roles I target, and my movement history.
 * Skills and development plans stay on My learning. Candidacy appears only with
 * succession.own_candidacy (off by default).
 */
class MyCareer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My career';

    protected static ?string $title = 'My career';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.my-career';

    public static function canAccess(): bool
    {
        return auth()->check() && TalentActions::me() !== null && auth()->user()->can('career.self');
    }

    public function me(): Employee
    {
        return TalentActions::me();
    }

    public function profile(): ?CareerProfile
    {
        return CareerProfile::query()->with('track')->where('employee_id', $this->me()->id)->first();
    }

    public function aspirations(): Collection
    {
        return CareerAspirationEntry::query()->with('targetDesignation')->where('employee_id', $this->me()->id)->orderByRaw("case when status = 'current' then 0 else 1 end")->orderByDesc('effective_from')->limit(30)->get();
    }

    public function goals(): Collection
    {
        return CareerGoal::query()->with(['targetDesignation', 'skill'])->where('employee_id', $this->me()->id)->orderByRaw("case when status = 'active' then 0 when status = 'paused' then 1 else 2 end")->orderByDesc('id')->get();
    }

    public function interests(): Collection
    {
        return MobilityInterest::query()->with('designation')->where('employee_id', $this->me()->id)->orderByDesc('id')->get();
    }

    /** @return list<array<string, mixed>> factual gaps for up to three target roles */
    public function gaps(): array
    {
        $targets = collect($this->profile()?->target_designation_ids ?? [])
            ->merge($this->goals()->where('status', 'active')->pluck('target_designation_id'))->filter()->unique()->take(3);

        return $targets->map(fn ($id) => app(RoleGaps::class)->for($this->me(), (int) $id) + ['designation' => Designation::query()->whereKey($id)->value('name')])
            ->filter(fn ($g) => $g['requirements'] !== null)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function movements(): array
    {
        return app(CareerMovements::class)->for($this->me());
    }

    /** Own candidacy — only when the tenant grants succession.own_candidacy. */
    public function candidacy(): Collection
    {
        if (! auth()->user()->can('succession.own_candidacy')) {
            return collect();
        }

        return Successor::query()->with('plan.position')->where('employee_id', $this->me()->id)->where('status', 'active')->get()
            ->map(fn (Successor $s) => ['position' => $s->plan?->position?->title, 'readiness' => app(Readiness::class)->current($this->me(), $s->plan?->critical_position_id, null)?->readiness_level]);
    }

    protected function getHeaderActions(): array
    {
        $profiles = app(CareerProfiles::class);

        return [
            Action::make('profile')->label('Edit profile & sharing')->icon(Heroicon::OutlinedPencilSquare)
                ->fillForm(fn () => ($p = $this->profile()) ? ['career_track_id' => $p->career_track_id, 'target_designation_ids' => $p->target_designation_ids ?? [], 'preferred_job_family_ids' => $p->preferred_job_family_ids ?? [],
                    'preferred_location_ids' => $p->preferred_location_ids ?? [], 'mobility' => array_keys(array_filter($p->mobility ?? [])), 'development_priorities' => $p->development_priorities,
                    'share_aspirations_with_manager' => $p->share_aspirations_with_manager, 'share_goals_with_manager' => $p->share_goals_with_manager, 'share_mobility_with_manager' => $p->share_mobility_with_manager, 'lock_version' => $p->lock_version] : ['share_goals_with_manager' => true, 'lock_version' => 0])
                ->schema([
                    Select::make('career_track_id')->label('Career track')->options(fn () => CareerTrack::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('target_designation_ids')->label('Roles I am interested in')->multiple()->options(fn () => TalentActions::designationOptions())->searchable(),
                    Select::make('preferred_job_family_ids')->label('Preferred job families')->multiple()->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('preferred_location_ids')->label('Preferred locations')->multiple()->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                    CheckboxList::make('mobility')->options(config('peopleos.career.mobility_options')),
                    Textarea::make('development_priorities')->rows(2),
                    Toggle::make('share_aspirations_with_manager')->label('Share my aspirations with my manager'),
                    Toggle::make('share_goals_with_manager')->label('Share my career goals with my manager'),
                    Toggle::make('share_mobility_with_manager')->label('Share my mobility preferences and interests with my manager'),
                    Hidden::make('lock_version'),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => $profiles->updateProfile($this->me(), [...$data, 'mobility' => array_fill_keys($data['mobility'] ?? [], true)], (int) ($data['lock_version'] ?? 0), auth()->user()), 'Profile saved')),
            Action::make('aspiration')->label('Record aspiration')->icon(Heroicon::OutlinedSparkles)
                ->schema([
                    Select::make('term')->options(config('peopleos.career.aspiration_terms'))->required(),
                    Select::make('target_designation_id')->label('Target role')->options(fn () => TalentActions::designationOptions())->searchable(),
                    Textarea::make('aspiration')->rows(3)->required()->maxLength(2000),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => $profiles->recordAspiration($this->me(), $data['term'], $data, auth()->user()), 'Aspiration recorded — earlier entries are kept as history')),
            Action::make('goal')->label('New career goal')->icon(Heroicon::OutlinedFlag)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255),
                    Select::make('goal_type')->label('Type')->options(config('peopleos.career.goal_types'))->required(),
                    Select::make('target_designation_id')->label('Target role')->options(fn () => TalentActions::designationOptions())->searchable(),
                    DatePicker::make('target_date')->native(false),
                    Textarea::make('description')->rows(2),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => $profiles->createGoal($this->me(), $data['title'], $data['goal_type'], $data, auth()->user()), 'Career goal created')),
            Action::make('goalStatus')->label('Update goal')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $this->goals()->whereIn('status', ['active', 'paused'])->isNotEmpty())
                ->schema([
                    Select::make('goal_id')->label('Goal')->options(fn () => $this->goals()->whereIn('status', ['active', 'paused'])->pluck('title', 'id')->all())->required()->live(),
                    Select::make('to')->label('New status')->options(fn ($get) => collect(CareerGoal::TRANSITIONS[$this->goals()->firstWhere('id', (int) $get('goal_id'))?->status ?? 'active'] ?? [])->mapWithKeys(fn ($s) => [$s => config("peopleos.career.goal_statuses.{$s}", $s)])->all())->required(),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => $profiles->transitionGoal(CareerGoal::query()->where('employee_id', $this->me()->id)->findOrFail($data['goal_id']), $data['to'], $data['note'] ?? null, auth()->user()), 'Goal updated')),
            Action::make('interest')->label('Mobility interest')->icon(Heroicon::OutlinedMapPin)->color('gray')
                ->schema([
                    Select::make('interest_type')->label('Interested in a')->options(config('peopleos.career.mobility_interest_types'))->required()->live(),
                    Select::make('designation_id')->label('Role')->options(fn () => TalentActions::designationOptions())->searchable()->visible(fn ($get) => $get('interest_type') === 'position'),
                    Select::make('job_family_id')->label('Job family')->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn ($get) => $get('interest_type') === 'job_family'),
                    Select::make('department_id')->label('Department')->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn ($get) => $get('interest_type') === 'department'),
                    Select::make('location_id')->label('Location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn ($get) => $get('interest_type') === 'location'),
                    Select::make('career_track_id')->label('Career track')->options(fn () => CareerTrack::query()->orderBy('name')->pluck('name', 'id')->all())->visible(fn ($get) => $get('interest_type') === 'career_track'),
                    Textarea::make('notes')->maxLength(500),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => $profiles->addMobilityInterest($this->me(), $data['interest_type'], $data, auth()->user()), 'Interest recorded')),
            Action::make('withdraw')->label('Withdraw interest')->icon(Heroicon::OutlinedXMark)->color('gray')
                ->visible(fn () => $this->interests()->where('status', 'active')->isNotEmpty())
                ->schema([Select::make('interest_id')->label('Interest')->options(fn () => $this->interests()->where('status', 'active')->mapWithKeys(fn ($i) => [$i->id => config("peopleos.career.mobility_interest_types.{$i->interest_type}").($i->designation ? ': '.$i->designation->name : '')])->all())->required()])
                ->action(fn (array $data) => TalentActions::run(fn () => $profiles->withdrawMobilityInterest(MobilityInterest::query()->where('employee_id', $this->me()->id)->findOrFail($data['interest_id']), auth()->user()), 'Interest withdrawn')),
        ];
    }
}
