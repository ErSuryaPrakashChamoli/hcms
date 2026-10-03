<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Identity\Services\AccessScopes;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14 Employee 360: payslips (Payroll owns them) as references only — number and date, never
 * amounts. payroll.view within organisation scope, or the employee; a reporting line alone gives nothing.
 */
class PayslipsRelationManager extends RelationManager
{
    protected static string $relationship = 'payslips';

    protected static ?string $title = 'Payslips';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && (($user->hasPermission('payroll.view') && app(AccessScopes::class)->allows($user, $ownerRecord)) || (int) $ownerRecord->user_id === (int) $user->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Payslip'),
                TextColumn::make('generated_at')->label('Generated')->dateTime()->placeholder('—'),
                TextColumn::make('viewed_at')->label('Viewed by employee')->dateTime()->placeholder('Not yet'),
            ])
            ->defaultSort('generated_at', 'desc');
    }
}
