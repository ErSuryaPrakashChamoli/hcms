<?php

namespace App\Filament\Resources\CompanyEvents\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Support\WorkforceBulkWizards;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * HROne parity GO-15 (`app-events-preview`): the event with its RSVPs (Preview) and its sessions, where attendance is
 * marked per session (Attendance, "Select date", "Download excel"); Edit, Publish, Add invitees, Mark completed and
 * Unpublish (cancel with a reason).
 */
class ViewCompanyEvent extends PeopleViewRecord
{
    protected static string $resource = CompanyEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (CompanyEvent $record) => in_array($record->status, ['draft', 'published'], true)),
            CompanyEventResource::publishAction(),
            $this->addInviteesAction(),
            CompanyEventResource::attendanceCsvAction(),
            CompanyEventResource::completeAction(),
            CompanyEventResource::cancelAction(),
        ];
    }

    /** "Add bulk employees" to a published event: chosen people or pasted employee codes (in the HR person's scope). */
    private function addInviteesAction(): Action
    {
        return Action::make('addInvitees')->label('Add invitees')->icon(Heroicon::OutlinedUserPlus)->color('gray')
            ->visible(fn (CompanyEvent $record) => $record->status === 'published' && CompanyEvents::runs(auth()->user()))
            ->modalDescription('They are invited and told now. People invited already are left as they are.')
            ->schema([
                WorkforceBulkWizards::employeeSelect('employee_ids', 'Employees', true)->multiple(),
                Textarea::make('codes')->label('Or paste employee codes')->rows(3),
            ])
            ->action(fn (CompanyEvent $record, array $data) => ServiceDeskActions::run(function () use ($record, $data) {
                $codes = EmployeeFilters::parseCodes($data['codes'] ?? null);
                $people = Employee::query()->where(fn ($q) => $q->whereIn('id', array_map('intval', (array) ($data['employee_ids'] ?? [])))->orWhereIn('employee_code', $codes ?: ['']))->get();
                $unknown = array_diff($codes, $people->pluck('employee_code')->map(fn ($c) => (string) $c)->all());
                if ($unknown !== []) {
                    throw new RuntimeException('Unknown employee codes (or outside your scope): '.implode(', ', array_slice($unknown, 0, 10)).'.');
                }

                return app(CompanyEvents::class)->invite($record, $people, auth()->user());
            }, fn (int $n) => $n === 1 ? '1 person invited' : "{$n} people invited"));
    }
}
