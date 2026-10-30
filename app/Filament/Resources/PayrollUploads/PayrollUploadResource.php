<?php

namespace App\Filament\Resources\PayrollUploads;

use App\Filament\Resources\PayrollUploads\Pages\ListPayrollUploads;
use App\Filament\Resources\PayrollUploads\Pages\ViewPayrollUpload;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity HP-3 (PY-30): payroll uploads (perquisites, income outside payroll, statutory overrides, PF options) on
 * the same staged pipeline as the time-office uploads; each person sees the upload types they may run.
 */
class PayrollUploadResource extends TimeOfficeImportResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payroll uploads';

    protected static ?string $modelLabel = 'payroll upload';

    protected static ?string $slug = 'payroll-uploads';

    protected static ?int $navigationSort = 93;

    public static function area(): string
    {
        return 'payroll';
    }

    public static function uploadHeading(): string
    {
        return 'Upload a payroll file';
    }

    public static function getPages(): array
    {
        return ['index' => ListPayrollUploads::route('/'), 'view' => ViewPayrollUpload::route('/{record}')];
    }
}
