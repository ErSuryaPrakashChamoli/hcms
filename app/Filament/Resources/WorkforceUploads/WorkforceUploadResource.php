<?php

namespace App\Filament\Resources\WorkforceUploads;

use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Resources\WorkforceUploads\Pages\ListWorkforceUploads;
use App\Filament\Resources\WorkforceUploads\Pages\ViewWorkforceUpload;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity MS-5: workforce one-row-one-change uploads (the health insurance upload) on the same staged pipeline as
 * the time-office and payroll uploads; each person sees the upload types they may run (employee.import and the type's
 * permission), so the screen appears only for them.
 */
class WorkforceUploadResource extends TimeOfficeImportResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Workforce uploads';

    protected static ?string $modelLabel = 'workforce upload';

    protected static ?string $slug = 'workforce-uploads';

    protected static ?int $navigationSort = 32;

    public static function area(): string
    {
        return 'workforce';
    }

    public static function uploadHeading(): string
    {
        return 'Upload a workforce file';
    }

    public static function getPages(): array
    {
        return ['index' => ListWorkforceUploads::route('/'), 'view' => ViewWorkforceUpload::route('/{record}')];
    }
}
