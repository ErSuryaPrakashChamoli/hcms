<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Learning\Models\LearningCertificate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Certifications: issued and external credentials with version, expiry and verification. */
class CertificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'learningCertificates';

    protected static ?string $title = 'Certifications';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new LearningCertificate(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['course', 'courseVersion']))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('course.title')->label('Learning'),
                TextColumn::make('courseVersion.version')->label('Version')->prefix('v')->placeholder('—'),
                TextColumn::make('issuer')->placeholder('—'),
                TextColumn::make('issued_on')->date(),
                TextColumn::make('expires_on')->date()->placeholder('No expiry'),
                TextColumn::make('verification_status')->label('Verified')->badge()->color(fn (?string $state) => $state === 'verified' ? 'success' : 'warning'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'valid' => 'success', 'expiring' => 'warning', 'revoked' => 'gray', default => 'danger'
                }),
            ])
            ->defaultSort('issued_on', 'desc');
    }
}
