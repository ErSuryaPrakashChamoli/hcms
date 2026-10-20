<?php

namespace App\Filament\Resources\BgvCases\Pages;

use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Services\Bgv;
use App\Filament\Resources\BgvCases\BgvCaseResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ViewBgvCase extends PeopleViewRecord
{
    protected static string $resource = BgvCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')->label('Close case')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->authorize(fn () => auth()->user()->can('bgv.manage'))
                ->visible(fn (BgvCase $record) => $record->isOpen())
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(function (BgvCase $record, array $data) {
                    app(Bgv::class)->close($record, $data['reason']);
                    Notification::make()->success()->title('Case closed')->send();
                    $this->refreshFormData([]);
                }),
        ];
    }
}
