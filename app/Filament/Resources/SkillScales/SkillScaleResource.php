<?php

namespace App\Filament\Resources\SkillScales;

use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Models\SkillScaleVersion;
use App\Domain\Skills\Services\SkillScales;
use App\Filament\Resources\SkillScales\Pages\ManageSkillScales;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 8: skill proficiency scales. Levels are published as immutable versions; profiles pin the version used. */
class SkillScaleResource extends Resource
{
    protected static ?string $model = SkillScale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Skill levels';

    protected static ?int $navigationSort = 81;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
        ]);
    }

    /** @return array<int, CreateAction> */
    public static function headerActions(): array
    {
        return [CreateAction::make()->visible(fn () => auth()->user()->can('skills.manage'))];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('currentVersion'))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (SkillScale $record) => $record->code),
                TextColumn::make('currentVersion.version')->label('Version')->prefix('v')->placeholder('No levels yet'),
                TextColumn::make('levels')->label('Levels')->state(fn (SkillScale $record) => collect($record->currentVersion?->levels ?? [])->map(fn ($l) => $l['value'].' '.$l['label'])->implode(' · '))->wrap(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('publish')->label('Publish levels')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                    ->visible(fn () => auth()->user()->can('skills.manage'))
                    ->modalDescription('Publishes a new immutable version. Existing skill records and assessments keep the version they used.')
                    ->fillForm(fn (SkillScale $record) => ['levels' => $record->currentVersion?->levels ?? []])
                    ->schema([Repeater::make('levels')->required()->minItems(2)->columns(4)->schema([
                        TextInput::make('value')->numeric()->required(),
                        TextInput::make('label')->required()->maxLength(64),
                        TextInput::make('description')->maxLength(255),
                        TextInput::make('indicator')->label('Behavioural indicator')->maxLength(255),
                    ])])
                    ->action(fn (SkillScale $record, array $data) => LearningActions::run(fn () => app(SkillScales::class)->publish($record, $data['levels'], auth()->user()), fn (SkillScaleVersion $v) => "Version {$v->version} published")),
                Action::make('versions')->label('Versions')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (SkillScale $record) => $record->versions()->get()->map(fn (SkillScaleVersion $v) => TextEntry::make("v{$v->id}")->label("v{$v->version} · ".$v->published_at?->toDateString())
                        ->state(collect($v->levels)->map(fn ($l) => $l['value'].' '.$l['label'])->implode(' · ')))->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSkillScales::route('/')];
    }
}
