<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Pages\ReportLibraryPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListReports extends PeopleListRecords
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('library')->label('Report library')->icon(Heroicon::OutlinedRectangleStack)->color('gray')
                ->url(ReportLibraryPage::getUrl())->visible(fn () => ReportLibraryPage::canAccess()),
            CreateAction::make()->label('New report'),
        ];
    }
}
