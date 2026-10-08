<?php

namespace App\Filament\Resources\CareerTracks;

use App\Domain\Career\Models\CareerTrack;
use App\Filament\Resources\CareerTracks\Pages\ManageCareerTracks;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 9 career architecture: configurable career tracks (individual contributor, people manager, specialist, leadership…). */
class CareerTrackResource extends Resource
{
    protected static ?string $model = CareerTrack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Career architecture';

    protected static ?string $modelLabel = 'career track';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('track_type')->label('Track type')->options(config('peopleos.career.track_types'))->required(),
            Select::make('status')->options(['active' => 'Active', 'retired' => 'Retired'])->default('active')->required(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('track_type')->label('Type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.career.track_types.{$state}", $state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No career tracks')->emptyStateDescription('Tracks group career paths: individual contributor, people manager, technical or functional specialist, leadership.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCareerTracks::route('/')];
    }
}
