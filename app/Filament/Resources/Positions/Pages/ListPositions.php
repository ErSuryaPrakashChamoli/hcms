<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Services\Positions;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\WorkforceActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

class ListPositions extends PeopleListRecords
{
    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New position')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('create', Position::class))
                ->schema([
                    TextInput::make('code')->required()->maxLength(40)->alphaDash(),
                    WorkforceActions::effectiveFrom()->required(),
                    ...WorkforceActions::definitionFields(),
                    Textarea::make('reason')->rows(2),
                ])
                ->action(fn (array $data) => WorkforceActions::run(fn () => app(Positions::class)->create(WorkforceActions::filled($data), auth()->user()), fn ($p) => "Position {$p->code} created as a draft")),
        ];
    }
}
