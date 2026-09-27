<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Performance\Models\CareerAspiration;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\CareerPassport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/** Career Passport (§36): one page per employee. HR picks anyone; everyone else sees themselves (or their reports). */
class CareerPassportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Career passport';

    protected static ?string $title = 'Career passport';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.career-passport';

    #[Url]
    public ?int $employee = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('performance.view') || EmployeeOwnedPolicy::employeeOf($user) !== null);
    }

    public function mount(): void
    {
        $this->employee ??= $this->allowed()->keys()->first();
    }

    /** @return Collection<int, string> */
    public function allowed()
    {
        $user = auth()->user();
        $me = EmployeeOwnedPolicy::employeeOf($user);

        $query = Employee::query()->with('person')->employed()->orderBy('employee_code');
        if (! $user->can('performance.view')) {
            $ids = collect([$me?->id])->merge($me && $user->can('performance.team') ? $me->directReports()->currentlyEffective()->pluck('employee_id') : [])->filter();
            $query->whereIn('id', $ids);
        }

        return $query->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"]);
    }

    public function getPassport(): ?array
    {
        if ($this->employee === null || ! $this->allowed()->has($this->employee)) {
            return null;
        }

        return app(CareerPassport::class)->build(Employee::query()->findOrFail($this->employee));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose employee')->icon(Heroicon::OutlinedUser)
                ->visible(fn () => $this->allowed()->count() > 1)
                ->schema([Select::make('employee')->options(fn () => $this->allowed()->all())->default(fn () => $this->employee)->required()->searchable()])
                ->action(fn (array $data) => $this->employee = (int) $data['employee']),
            Action::make('aspiration')->label('Update aspirations')->icon(Heroicon::OutlinedSparkles)->color('primary')
                ->visible(fn () => $this->employee !== null && ($this->employee === EmployeeOwnedPolicy::employeeOf(auth()->user())?->id || auth()->user()->can('performance.manage')))
                ->schema(function () {
                    $current = CareerAspiration::query()->where('employee_id', $this->employee)->first();

                    return [
                        Select::make('career_path_id')->label('Career path')->placeholder('—')->options(fn () => CareerPath::query()->where('status', 'active')->pluck('name', 'id')->all())->default($current?->career_path_id),
                        Select::make('target_designation_id')->label('Target role')->placeholder('—')->searchable()->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all())->default($current?->target_designation_id),
                        Textarea::make('aspirations')->rows(3)->default($current?->aspirations),
                        TagsInput::make('interests')->default($current?->interests ?? []),
                        Toggle::make('open_to_relocation')->default($current?->open_to_relocation ?? false),
                        Toggle::make('open_to_role_change')->default($current?->open_to_role_change ?? true),
                    ];
                })
                ->action(function (array $data) {
                    CareerAspiration::query()->updateOrCreate(['employee_id' => $this->employee], $data);
                    Notification::make()->success()->title('Aspirations saved')->send();
                }),
        ];
    }
}
