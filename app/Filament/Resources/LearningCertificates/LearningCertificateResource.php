<?php

namespace App\Filament\Resources\LearningCertificates;

use App\Domain\Learning\Models\LearningCertificate;
use App\Filament\Resources\LearningCertificates\Pages\ListLearningCertificates;
use App\Filament\Support\LearningActions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Certificates issued on completion; expiry drives re-training (§37). */
class LearningCertificateResource extends Resource
{
    protected static ?string $model = LearningCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Certificates';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'course']);
        if (auth()->user()->can('learning.view') || auth()->user()->can('learning.assign')) {
            return $query;
        }

        return $query->where('employee_id', LearningActions::me()?->id ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('course.title')->label('Course'),
                TextColumn::make('issued_on')->date()->sortable(),
                TextColumn::make('expires_on')->date()->placeholder('Never')->sortable(),
                TextColumn::make('score')->suffix('%')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'valid' => 'success', 'expiring' => 'warning', default => 'danger'
                }),
            ])
            ->defaultSort('issued_on', 'desc')
            ->filters([SelectFilter::make('status')->options(['valid' => 'Valid', 'expiring' => 'Expiring', 'expired' => 'Expired'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLearningCertificates::route('/')];
    }
}
