<?php

namespace App\Filament\Resources\CompanyEvents\RelationManagers;

use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Support\EmployeeFilters;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity GO-15 (`app-events-preview` → Preview): everyone invited, how (audience, HR, a colleague's nomination),
 * their answer and when — in the HR person's access scope. "Download excel" gives the attendance sheet.
 */
class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Invitations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CompanyEvents::runs(auth()->user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person', 'employee.currentPosition.department', 'nominator.person']))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Code')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('employee.person.full_name')->label('Employee name'),
                TextColumn::make('employee.currentPosition.department.name')->label('Department')->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('Nomination')->badge()->formatStateUsing(fn (string $state) => CompanyEventInvitation::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'accepted' => 'success', 'declined' => 'danger', 'nominated' => 'warning', default => 'gray'
                    }),
                TextColumn::make('source')->label('How invited')->formatStateUsing(fn (string $state) => CompanyEventInvitation::SOURCES[$state] ?? $state)->toggleable(),
                TextColumn::make('nominator.person.full_name')->label('Nominated by')->placeholder('—')->toggleable(),
                TextColumn::make('responded_at')->label('Answered on')->dateTime()->placeholder('Not yet'),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')->label('Nomination')->options(CompanyEventInvitation::STATUSES),
                SelectFilter::make('source')->label('How invited')->options(CompanyEventInvitation::SOURCES),
                EmployeeFilters::codes('employee'),
            ])
            ->headerActions([
                Action::make('downloadExcel')->label('Download excel')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn () => $this->getOwnerRecord()->status !== 'draft')
                    ->action(function () {
                        /** @var CompanyEvent $event */
                        $event = $this->getOwnerRecord();

                        return CompanyEventResource::download($event);
                    }),
            ]);
    }
}
