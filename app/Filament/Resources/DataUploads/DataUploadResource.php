<?php

namespace App\Filament\Resources\DataUploads;

use App\Domain\Employment\Imports\RowImportTypes;
use App\Filament\Resources\DataUploads\Pages\ListDataUploads;
use App\Filament\Resources\DataUploads\Pages\ViewDataUpload;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Data import: the uploads of the heads without an upload screen of their own (Core, Performance, Engage, Asset,
 * Project management, Integration) on the same staged pipeline. People start them from Data import and open them from
 * there or from Recent uploads, so the screen is not in the menu.
 */
class DataUploadResource extends TimeOfficeImportResource
{
    /** The Data import heads this screen serves. */
    public const AREAS = ['core', 'performance', 'engage', 'asset', 'projects', 'integration'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Data uploads';

    protected static ?string $modelLabel = 'data upload';

    protected static ?string $slug = 'data-uploads';

    protected static bool $shouldRegisterNavigation = false;

    public static function area(): string
    {
        return 'core';
    }

    public static function types(): array
    {
        $user = auth()->user();

        return array_merge(...array_map(fn (string $area) => RowImportTypes::allowedTypes($user, $area), self::AREAS));
    }

    public static function uploadHeading(): string
    {
        return 'Upload a file';
    }

    public static function getPages(): array
    {
        return ['index' => ListDataUploads::route('/'), 'view' => ViewDataUpload::route('/{record}')];
    }
}
