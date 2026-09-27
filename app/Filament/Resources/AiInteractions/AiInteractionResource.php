<?php

namespace App\Filament\Resources\AiInteractions;

use App\Domain\Ai\Models\AiInteraction;
use App\Filament\Resources\AiInteractions\Pages\ListAiInteractions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** AI governance log (§95): who asked what, which assistant, what facts it used, provider, feedback. */
class AiInteractionResource extends Resource
{
    protected static ?string $model = AiInteraction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'AI interactions';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('User')->placeholder('—')->searchable(),
                TextColumn::make('assistant')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.ai.assistants.{$state}.label", $state)),
                TextColumn::make('intent')->placeholder('—')->toggleable(),
                TextColumn::make('question')->limit(60)->searchable()->wrap(),
                TextColumn::make('provider')->badge()->color(fn (string $state) => $state === 'deterministic' ? 'gray' : 'info'),
                IconColumn::make('is_inference')->label('Inference')->boolean(),
                TextColumn::make('feedback')->badge()->placeholder('—')->color(fn (?string $state) => $state === 'up' ? 'success' : 'danger'),
                TextColumn::make('latency_ms')->label('ms')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('assistant')->options(collect(config('peopleos.ai.assistants'))->map(fn ($a) => $a['label'])->all()),
                SelectFilter::make('feedback')->options(['up' => 'Helpful', 'down' => 'Not helpful']),
            ])
            ->recordActions([
                Action::make('detail')->label('Read')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        Section::make()->schema([
                            TextEntry::make('question'),
                            TextEntry::make('answer')->prose(),
                            TextEntry::make('sources')->state(fn (AiInteraction $record) => collect($record->sources ?? [])->map(fn ($s) => $s['label'].(isset($s['detail']) ? " — {$s['detail']}" : ''))->all())->listWithLineBreaks()->placeholder('—'),
                            TextEntry::make('actions')->state(fn (AiInteraction $record) => collect($record->actions ?? [])->map(fn ($a) => $a['label'].' → '.$a['url'])->all())->listWithLineBreaks()->placeholder('—'),
                            TextEntry::make('feedback_note')->placeholder('—'),
                        ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAiInteractions::route('/')];
    }
}
