<?php

namespace App\Filament\Resources\PerformanceTemplates;

use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceTemplate;
use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\PerformanceTemplates;
use App\Filament\Resources\PerformanceTemplates\Pages\ManagePerformanceTemplates;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 7: versioned review templates. A published version is immutable; cycles pin one at launch. */
class PerformanceTemplateResource extends Resource
{
    protected static ?string $model = PerformanceTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
        ]);
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [CreateAction::make()->visible(fn () => auth()->user()->can('performance.manage'))];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestVersion')->withCount('versions'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('versions_count')->label('Versions'),
                TextColumn::make('latestVersion.version')->label('Latest')->prefix('v')->placeholder('—'),
                TextColumn::make('latestVersion.published_at')->label('Published')->dateTime()->placeholder('—'),
                TextColumn::make('latestVersion.checksum')->label('Checksum')->limit(12)->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('publish')->label('Publish version')->icon(Heroicon::OutlinedArrowUpTray)->color('primary')
                    ->visible(fn () => auth()->user()->can('performance.manage'))
                    ->modalDescription('Publishing snapshots the rating scale and competencies as they are now. A published version never changes; publish a new one instead.')
                    ->schema([
                        Select::make('rating_scale_id')->label('Rating scale')->required()->options(fn () => RatingScale::query()->where('status', 'active')->pluck('name', 'id')->all())->default(fn () => RatingScale::default()?->id),
                        CheckboxList::make('competency_ids')->label('Competencies')->options(fn () => Competency::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->columns(2),
                        CheckboxList::make('stages')->label('Workflow stages (in this order)')->required()->options(config('peopleos.performance.stages'))->default(array_keys(config('peopleos.performance.stages')))->columns(3),
                        CheckboxList::make('sections')->required()->options(array_combine(PerformanceTemplateVersion::SECTIONS, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), PerformanceTemplateVersion::SECTIONS)))->default(PerformanceTemplateVersion::SECTIONS)->columns(3),
                        TextInput::make('weights.goals')->label('Goals weight')->numeric()->minValue(0)->required()->default(config('peopleos.performance.default_weights.goals')),
                        TextInput::make('weights.competencies')->label('Competencies weight')->numeric()->minValue(0)->required()->default(config('peopleos.performance.default_weights.competencies')),
                        TextInput::make('required_goal_weight')->label('Required goal weight total')->numeric()->minValue(0.01)->helperText('Leave empty for no required total.'),
                    ])
                    ->action(fn (PerformanceTemplate $record, array $data) => PerformanceActions::run(fn () => app(PerformanceTemplates::class)->publish($record, [
                        'rating_scale_id' => (int) $data['rating_scale_id'],
                        'competency_ids' => array_map('intval', $data['competency_ids'] ?? []),
                        // Keep the configured stage order regardless of click order.
                        'workflow' => collect(array_keys(config('peopleos.performance.stages')))->filter(fn ($k) => in_array($k, $data['stages'] ?? [], true))->map(fn ($k) => ['key' => $k])->values()->all(),
                        'sections' => array_values($data['sections'] ?? []),
                        'weights' => ['goals' => (float) $data['weights']['goals'], 'competencies' => (float) $data['weights']['competencies']],
                        'goal_rules' => ['required_total_weight' => filled($data['required_goal_weight'] ?? null) ? (float) $data['required_goal_weight'] : null],
                    ], auth()->user()), fn (PerformanceTemplateVersion $v) => "Version {$v->version} published")),
                Action::make('versions')->label('Versions')->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->modalSubmitAction(false)
                    ->schema(fn (PerformanceTemplate $record) => $record->versions()->get()->map(fn (PerformanceTemplateVersion $v) => TextEntry::make("v{$v->id}")->label("v{$v->version} · {$v->published_at?->toDateTimeString()}")
                        ->state(sprintf('Scale: %s · stages: %s · competencies: %d · weights: %s · checksum %s', $v->rating_scale_snapshot['name'] ?? '—', implode(' → ', array_column($v->workflow, 'key')), count($v->competency_snapshot), json_encode($v->weights), substr($v->checksum, 0, 16))))->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceTemplates::route('/')];
    }
}
