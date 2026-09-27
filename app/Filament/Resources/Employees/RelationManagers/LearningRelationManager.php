<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Learning\Models\LearningEnrolment;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Learning. */
class LearningRelationManager extends RelationManager
{
    protected static string $relationship = 'learningEnrolments';

    protected static ?string $title = 'Learning';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new LearningEnrolment(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('course'))
            ->description('Certificates: '.$employee->learningCertificates()->where('status', '!=', 'expired')->count().' valid')
            ->columns([
                TextColumn::make('course.title')->label('Course'),
                TextColumn::make('course.category')->label('Category')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => LearningEnrolmentResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.learning.enrolment_statuses.{$state}", $state)),
                TextColumn::make('progress')->suffix('%'),
                TextColumn::make('due_on')->date()->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
                TextColumn::make('expires_on')->date()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (LearningEnrolment $record) => LearningEnrolmentResource::getUrl('view', ['record' => $record]))]);
    }
}
