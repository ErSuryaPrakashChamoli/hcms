<?php

namespace App\Filament\Resources\CompanyEvents\RelationManagers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Models\CompanyEventSession;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Identity\Scopes\AccessScope;
use App\Filament\Resources\CompanyEvents\CompanyEventResource;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity GO-15 (`app-events-preview` → Attendance, "Select date"): the event's sessions with how many attended;
 * "Mark attendance" ticks who attended a started session (everyone else on the sheet is marked absent), "Download" gives
 * that session's sheet.
 */
class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Sessions and attendance';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CompanyEvents::runs(auth()->user());
    }

    public function table(Table $table): Table
    {
        /** @var CompanyEvent $event */
        $event = $this->getOwnerRecord();
        $events = fn () => app(CompanyEvents::class);
        $open = fn (CompanyEventSession $record) => in_array($event->status, ['published', 'completed'], true) && $record->hasStarted();

        return $table
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('starts_at')->label('Date')->formatStateUsing(fn (CompanyEventSession $record) => $record->label($event->timezone)),
                TextColumn::make('duration_minutes')->label('Duration')->formatStateUsing(fn ($state) => CompanyEvents::hhmm((int) $state)),
                TextColumn::make('attended')->label('Attended')->state(fn (CompanyEventSession $record) => $event->status === 'draft' ? '—' : $events()->attendedCount($record)),
            ])
            ->defaultSort('sequence')
            ->recordActions([
                Action::make('markAttendance')->label('Mark attendance')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->visible($open)
                    ->modalDescription('Tick who attended. Everyone else on the sheet is marked absent; marking again changes it.')
                    ->fillForm(fn (CompanyEventSession $record) => ['attended' => CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)
                        ->where('session_id', $record->getKey())->where('attended', true)->pluck('employee_id')->map(fn ($id) => (int) $id)->all()])
                    ->schema([CheckboxList::make('attended')->label('Attended')->options(fn () => CompanyEventResource::inviteeOptions($event))->searchable()->bulkToggleable()->columns(2)])
                    ->action(fn (CompanyEventSession $record, array $data) => ServiceDeskActions::run(function () use ($record, $data, $event) {
                        $ticked = array_map('intval', (array) ($data['attended'] ?? []));
                        $ids = array_keys(CompanyEventResource::inviteeOptions($event));
                        $marked = 0;
                        app(AuditRecorder::class)->operation('engagement', 'Mark event attendance', function () use ($record, $ids, $ticked, &$marked) {
                            foreach (Employee::query()->whereIn('id', $ids)->get() as $employee) {
                                app(CompanyEvents::class)->markAttendance($record, $employee, in_array((int) $employee->getKey(), $ticked, true), auth()->user());
                                $marked++;
                            }

                            return ['succeeded' => $marked, 'ids' => $ids];
                        }, entityType: CompanyEventAttendance::class);

                        return count($ticked);
                    }, fn (int $n) => $n === 1 ? '1 person marked attended' : "{$n} people marked attended")),
                Action::make('downloadSession')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn () => $event->status !== 'draft')
                    ->action(fn (CompanyEventSession $record) => CompanyEventResource::download($event, $record)),
            ]);
    }
}
