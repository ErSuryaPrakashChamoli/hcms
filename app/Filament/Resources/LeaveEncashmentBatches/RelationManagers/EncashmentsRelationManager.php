<?php

namespace App\Filament\Resources\LeaveEncashmentBatches\RelationManagers;

use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Support\PayMonth;
use App\Filament\Support\EmployeeFilters;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** HROne parity PO-8: the encashments a batch created (only those in the viewer's access scope). */
class EncashmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'encashments';

    protected static ?string $title = 'Encashments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'reviewer']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (LeaveEncashment $record) => $record->employee?->employee_code),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('pay_month')->label('Pay month')->state(fn (LeaveEncashment $record) => PayMonth::label($record->pay_month)),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => LeaveEncashment::STATUSES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'approved', 'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Approved by')->placeholder('—'),
                TextColumn::make('cancel_reason')->label('Unprocess reason')->placeholder('—')->wrap()->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(LeaveEncashment::STATUSES)])
            ->defaultSort('id');
    }
}
