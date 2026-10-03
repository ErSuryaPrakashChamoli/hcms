<?php

namespace App\Filament\Resources\IntegrationSystems\RelationManagers;

use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Services\ExternalReferences;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Phase 14: external ids this system holds for PeopleOS records (PeopleOS ids stay canonical). */
class ReferencesRelationManager extends RelationManager
{
    protected static string $relationship = 'references';

    protected static ?string $title = 'External references';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity_type')->label('PeopleOS record')->badge()->color('gray'),
                TextColumn::make('entity_label')->label('Record')->state(fn (ExternalReference $record) => self::label($record)),
                TextColumn::make('external_entity_type')->label('External type'),
                TextColumn::make('external_entity_id')->label('External id')->searchable()->copyable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'retired' => 'Retired'])])
            ->recordActions([
                Action::make('retire')->label('Retire')->icon('heroicon-m-archive-box')->color('danger')
                    ->visible(fn (ExternalReference $record) => $record->status === 'active' && auth()->user()->can('integration.manage'))
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (ExternalReference $record, array $data) => ServiceDeskActions::run(fn () => app(ExternalReferences::class)->retire($record, $data['reason'], auth()->user()), 'Reference retired')),
            ]);
    }

    private static function label(ExternalReference $reference): string
    {
        $entity = $reference->entityModel();

        return match (true) {
            $entity === null => '#'.$reference->entity_id,
            isset($entity->employee_code) => (string) $entity->employee_code,
            isset($entity->code) => (string) $entity->code,
            default => '#'.$entity->getKey(),
        };
    }
}
