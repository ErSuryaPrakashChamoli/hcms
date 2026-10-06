<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Enums\EnforcementClass;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Services\EntitlementDiagnostics;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Entitlements\Support\PlanValues;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.4: Markedge's commercial plan catalogue for platform operators only: plans, their versions and states, what
 * each version includes (against the code-owned capability catalogue), how many tenants are on each version, and
 * the platform-chain audit of every change. Every change goes through PlanCatalog (operator-only, reasoned,
 * audited). Shadow mode: no plan changes what any tenant can use. Tenants are assigned on the Entitlements page.
 */
class PlatformPlansPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $title = 'Commercial plans (shadow mode)';

    protected static ?string $slug = 'platform-plans';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.platform-plans';

    #[Url]
    public ?int $plan = null;

    /** The selected plan, read once per request (Livewire does not keep non-public properties between requests). */
    private ?Plan $selected = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, Plan> */
    public function plans(): Collection
    {
        return Plan::query()->with('versions')->orderBy('code')->get();
    }

    public function selectedPlan(): ?Plan
    {
        if ($this->plan === null) {
            return null;
        }
        if ($this->selected?->id !== $this->plan) {
            $this->selected = Plan::query()->with(['versions.entitlements', 'versions.publisher'])->find($this->plan);
        }

        return $this->selected;
    }

    /** @return array<int, array{current: int, upcoming: int}> tenants per plan version */
    public function usage(): array
    {
        return app(EntitlementDiagnostics::class)->tenantsByPlanVersion();
    }

    /**
     * Rows: every commercial capability; columns: the plan's versions (newest first); cells: the plan's value.
     *
     * @return list<array{capability: Capability, values: array<int, string>}>
     */
    public function matrix(Plan $plan): array
    {
        $versions = $plan->versions->sortByDesc('version')->mapWithKeys(fn (PlanVersion $v) => [$v->id => self::versionContent($v)]);

        return collect(Capability::commercialCases())->map(fn (Capability $c) => [
            'capability' => $c,
            'values' => $versions->map(fn (array $content) => PlanValues::label($c, $content))->all(),
        ])->all();
    }

    /** @return list<string> SaaS.5: why the plan's draft could not be published as it stands (an inconsistent package) */
    public function draftProblems(Plan $plan): array
    {
        $draft = $plan->versions->firstWhere('status', VersionStatus::Draft);

        return $draft ? PlanCatalog::problems(self::versionContent($draft)) : [];
    }

    /** @return array<string, bool|int|null> a version's content: capability key => value */
    private static function versionContent(PlanVersion $version): array
    {
        return $version->entitlements->mapWithKeys(fn (PlanEntitlement $e) => [$e->capability->value => $e->value()])->all();
    }

    public function history(Plan $plan): Collection
    {
        return app(EntitlementDiagnostics::class)->auditTrail($plan);
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $selected = fn () => $this->selectedPlan() !== null;
        $draft = fn (): ?PlanVersion => $this->selectedPlan()?->versions->firstWhere('status', VersionStatus::Draft);

        return [
            Action::make('createPlan')->label('New plan')->icon(Heroicon::OutlinedPlus)
                ->modalDescription('A plan starts with an empty draft. Nothing reaches a tenant until a version is published and assigned.')
                ->schema([TextInput::make('code')->required()->maxLength(64)->regex(Plan::CODE_PATTERN)->helperText('Permanent: lower-case letters, digits, "-" or "_".'),
                    TextInput::make('name')->required()->maxLength(120), Textarea::make('description')->maxLength(500), $reason()])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    $this->plan = app(PlanCatalog::class)->create($data['code'], $data['name'], $data['description'] ?? null, $data['reason'], auth()->user())->id;
                }, 'Plan created with a draft v1')),
            Action::make('editPlan')->label('Edit details')->icon(Heroicon::OutlinedPencilSquare)->color('gray')->visible($selected)
                ->fillForm(fn () => ['name' => $this->selectedPlan()?->name, 'description' => $this->selectedPlan()?->description])
                ->schema([TextInput::make('name')->required()->maxLength(120), Textarea::make('description')->maxLength(500), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(PlanCatalog::class)->update($this->selectedPlan(), $data['name'], $data['description'] ?? null, $data['reason'], auth()->user()), 'Plan details saved')),
            Action::make('newDraft')->label('New draft version')->icon(Heroicon::OutlinedDocumentDuplicate)->visible(fn () => $selected() && $draft() === null)
                ->modalDescription('Copies the latest version. Published versions never change; tenants keep the version they are on.')
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(PlanCatalog::class)->draft($this->selectedPlan(), $data['reason'], auth()->user()), 'Draft created')),
            Action::make('editDraft')->label('Edit draft capabilities')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->visible(fn () => $draft() !== null)
                ->modalWidth('5xl')
                ->modalDescription('Protected capabilities (payroll, onboarding, exit, the active-employee limit) can be included or left out, never switched off.')
                ->fillForm(fn () => self::formState(app(PlanCatalog::class)->values($draft())))
                ->schema(fn () => [...self::capabilityFields(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(PlanCatalog::class)->define($draft(), self::valuesFrom($data), $data['reason'], auth()->user()), 'Draft saved')),
            Action::make('publish')->label('Publish draft')->icon(Heroicon::OutlinedCheckBadge)->color('success')->visible(fn () => $draft() !== null)
                ->modalDescription('Freezes this version and puts it on sale from the date. The current version stops being sold the day before; tenants on it keep it.')
                ->schema([DatePicker::make('from')->label('On sale from')->native(false)->required()->default(now()->toDateString()),
                    TextInput::make('change_note')->label('What changed')->maxLength(255), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(PlanCatalog::class)->publish($draft(), substr((string) $data['from'], 0, 10), $data['reason'], auth()->user(), $data['change_note'] ?? null), 'Version published')),
            Action::make('retire')->label('Retire version')->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                ->visible(fn () => $this->selectedPlan()?->versions->contains('status', VersionStatus::Published) ?? false)
                ->modalDescription('No new tenant can be assigned to a retired version. Tenants already on it keep it.')
                ->schema([Select::make('version')->required()->options(fn () => $this->selectedPlan()?->versions->where('status', VersionStatus::Published)
                    ->mapWithKeys(fn (PlanVersion $v) => [$v->id => "v{$v->version} ({$v->state()->value})"])->all() ?? []), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(PlanCatalog::class)->retire($this->selectedPlan()->versions->firstWhere('id', (int) $data['version']), $data['reason'], auth()->user()), 'Version retired')),
        ];
    }

    /** Form field names cannot contain dots (Filament nests them). */
    private static function field(Capability $capability): string
    {
        return 'cap_'.str_replace('.', '__', $capability->value);
    }

    /** @return list<Grid> one field (or two, for a limit) per commercial capability */
    private static function capabilityFields(): array
    {
        $groups = collect(Capability::commercialCases())->groupBy(fn (Capability $c) => $c->type()->value);
        $fields = [];
        foreach (['module' => 'Modules', 'feature' => 'Features', 'limit' => 'Limits'] as $type => $heading) {
            $components = [];
            foreach ($groups[$type] ?? [] as $capability) {
                $name = self::field($capability);
                $protected = $capability->enforcement() === EnforcementClass::Protected;
                $label = "{$capability->value} · {$capability->label()}".($protected ? ' (protected)' : '');
                if ($capability->type() === CapabilityType::Limit) {
                    $hint = ($capability->measured() ? 'Usage is measured.' : 'Usage is not measured yet (a finite value answers UNKNOWN).')
                        .($capability->followsModule() ? " Not included unless the {$capability->module()->value} module is included." : '');
                    $components[] = Select::make($name)->label($label)->options(['absent' => 'Not set (no agreed limit)', 'unlimited' => 'Unlimited', 'limited' => 'Limited to…'])
                        ->default('absent')->selectablePlaceholder(false)->live()->helperText($hint);
                    $components[] = TextInput::make("{$name}_value")->label("{$capability->value}: {$capability->unit()}")->integer()->minValue($capability->minimumLimit())
                        ->helperText($capability->unit() === 'bytes' ? '1 GiB = 1,073,741,824 bytes' : null)
                        ->required(fn (Get $get) => $get($name) === 'limited')->visible(fn (Get $get) => $get($name) === 'limited');
                } else {
                    $options = ['absent' => 'Not in plan', 'included' => 'Included'] + ($protected ? [] : ['excluded' => 'Excluded']);
                    $components[] = Select::make($name)->label($label)->options($options)->default('absent')->selectablePlaceholder(false);
                }
            }
            $fields[] = Section::make($heading)->schema([Grid::make(2)->schema($components)])->compact();
        }

        return $fields;
    }

    /**
     * @param  array<string, bool|int|null>  $values
     * @return array<string, mixed>
     */
    private static function formState(array $values): array
    {
        $state = [];
        foreach (Capability::commercialCases() as $capability) {
            $name = self::field($capability);
            $present = array_key_exists($capability->value, $values);
            $value = $values[$capability->value] ?? null;
            if ($capability->type() === CapabilityType::Limit) {
                $state[$name] = ! $present ? 'absent' : ($value === null ? 'unlimited' : 'limited');
                $state["{$name}_value"] = is_int($value) ? $value : null;
            } else {
                $state[$name] = ! $present ? 'absent' : ($value ? 'included' : 'excluded');
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool|int|null>
     */
    private static function valuesFrom(array $data): array
    {
        $values = [];
        foreach (Capability::commercialCases() as $capability) {
            $choice = $data[self::field($capability)] ?? 'absent';
            if ($choice === 'absent') {
                continue;
            }
            $values[$capability->value] = match ($choice) {
                'unlimited' => null,
                'limited' => (int) ($data[self::field($capability).'_value'] ?? 0),
                default => $choice === 'included',
            };
        }

        return $values;
    }

    private function attempt(\Closure $work, string $done): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $work();
            Notification::make()->success()->title($done)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        } finally {
            $this->selected = null; // show the result of the change, not the plan as it was read before it
        }
    }
}
