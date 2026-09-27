<?php

namespace App\Filament\Resources\PerformanceCycles\RelationManagers;

use App\Domain\Performance\Models\Appraisal;
use App\Filament\Resources\Appraisals\AppraisalResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AppraisalsRelationManager extends RelationManager
{
    protected static string $relationship = 'appraisals';

    protected static ?string $title = 'Appraisals';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('performance.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'manager.person', 'reviews']))
            ->columns(AppraisalResource::columns())
            ->filters([SelectFilter::make('status')->options(config('peopleos.performance.appraisal_statuses'))])
            ->recordActions([Action::make('open')->label('Open')->url(fn (Appraisal $record) => AppraisalResource::getUrl('view', ['record' => $record]))]);
    }
}
