<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Reports\ReportResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * Report library (§84): ready-made HR reports grouped by area. "Use" saves a template as the user's own
 * report (optionally for one employee) and opens it, where it can be run, exported, edited or scheduled.
 */
class ReportLibraryPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Report library';

    protected static ?string $title = 'Report library';

    protected static ?string $slug = 'report-library';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.report-library';

    public string $search = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', Report::class) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Ready-made HR reports. Pick one to add it to your reports, then run it, export it to CSV, change its filters or schedule it.';
    }

    /** @return Collection<string, list<array<string, mixed>>> */
    public function categories(): Collection
    {
        return app(ReportLibrary::class)->availableTo(auth()->user(), $this->search);
    }

    public function datasetLabel(string $key): string
    {
        return app(DatasetRegistry::class)->get($key)->label();
    }

    public function useTemplateAction(): Action
    {
        $template = fn (array $arguments): array => app(ReportLibrary::class)->find((string) ($arguments['template'] ?? '')) ?? throw new RuntimeException('Unknown report template.');

        return Action::make('useTemplate')->label('Use')->icon(Heroicon::OutlinedPlus)->size('sm')->link()
            ->modalHeading(fn (array $arguments) => $template($arguments)['name'])
            ->modalDescription(fn (array $arguments) => $template($arguments)['description'])
            ->modalSubmitActionLabel('Create and open')
            ->fillForm(fn (array $arguments) => ['name' => $template($arguments)['name'], 'shared' => false])
            ->schema(fn (array $arguments) => [
                TextInput::make('name')->label('Report name')->required()->maxLength(255),
                Select::make('employee_code')->label('Only for one employee (optional)')->placeholder('All employees in your access')
                    ->helperText('Leave empty for everyone. You can change filters later on the report.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::employeeOptions($search))
                    ->getOptionLabelUsing(fn ($value) => self::employeeOptions((string) $value)[$value] ?? $value)
                    ->visible(isset(app(DatasetRegistry::class)->get($template($arguments)['dataset'])->fields()['employee_code'])),
                Toggle::make('shared')->label('Share with everyone who can see this data')->visible(auth()->user()->hasPermission('analytics.manage')),
            ])
            ->action(function (array $data, array $arguments) {
                try {
                    $report = app(ReportLibrary::class)->create((string) ($arguments['template'] ?? ''), auth()->user(), $data['name'] ?? null, $data['employee_code'] ?? null, (bool) ($data['shared'] ?? false));
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Report created')->send();
                $this->redirect(ReportResource::getUrl('view', ['record' => $report]));
            });
    }

    /** @return array<string, string> employee code => "code · name", searched by code or name within the viewer's access */
    private static function employeeOptions(string $search): array
    {
        $term = '%'.trim($search).'%';

        return Employee::query()->with('person')
            ->where(fn ($q) => $q->where('employee_code', 'like', $term)
                ->orWhereHas('person', fn ($p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('preferred_name', 'like', $term)))
            ->orderBy('employee_code')->limit(50)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->employee_code => $e->employee_code.' · '.$e->person?->full_name])
            ->all();
    }
}
