<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use App\Filament\Support\LeaveActions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeaveRelationManager extends RelationManager
{
    protected static string $relationship = 'leaveRequests';

    protected static ?string $title = 'Leave';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('leave.view') || ($user->can('leave.apply') && $ownerRecord->user_id === $user->id));
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();
        $period = app(LeaveYear::class)->periodFor(now());
        $summary = collect(app(LeaveEntitlements::class)->for($employee))
            ->keys()
            ->map(fn (string $code) => LeaveType::query()->where('code', $code)->first())
            ->filter()
            ->map(fn (LeaveType $type) => sprintf('%s %.1f', $type->code, app(LeaveBalances::class)->balance($employee, $type, $period)->available()))
            ->implode(' · ');

        return $table
            ->heading('Leave · balances '.$period.($summary ? ": {$summary}" : ' (no leave policy applies)'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['leaveType', 'employee', 'reviewer']))
            ->columns([
                TextColumn::make('leaveType.name')->label('Type')->badge()->color(fn (LeaveRequest $record) => $record->leaveType?->is_paid ? 'info' : 'danger'),
                TextColumn::make('from_date')->date('d M')->sortable(),
                TextColumn::make('to_date')->date('d M Y'),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('review_note')->placeholder('—')->wrap()->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(LeaveRequest::STATUSES)])
            ->defaultSort('from_date', 'desc')
            ->headerActions([
                Action::make('apply')->label('Apply for leave')->icon('heroicon-m-plus')
                    ->visible(fn () => auth()->user()->can('leave.manage') || ($employee->user_id === auth()->id() && auth()->user()->can('leave.apply')))
                    ->schema(LeaveActions::applyForm(fn () => $employee))
                    ->action(fn (array $data) => LeaveActions::apply($employee, $data)),
            ])
            ->recordActions(LeaveActions::forRequests())
            ->emptyStateHeading('No leave requests yet');
    }
}
