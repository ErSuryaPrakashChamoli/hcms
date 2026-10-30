<?php

namespace App\Filament\Resources\OneOnOnes\Pages;

use App\Domain\Performance\Services\OneOnOnes;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PerformanceRegisters;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use RuntimeException;

class ManageOneOnOnes extends PeopleManageRecords
{
    protected static string $resource = OneOnOneResource::class;

    protected function getHeaderActions(): array
    {
        // HP-8b: scheduling follows performance.one_on_one.scope (OneOnOnes::schedule).
        return [CreateAction::make()->label('Schedule')->using(function (array $data, CreateAction $action) {
            try {
                return app(OneOnOnes::class)->schedule($data, auth()->user());
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not scheduled')->body($e->getMessage())->persistent()->send();
                $action->halt();
            }
        }),
            // HROne parity GO-1: the performance panel's actions menu for performance HR (hidden for everyone else).
            PerformanceRegisters::actionsMenu(),
        ];
    }
}
