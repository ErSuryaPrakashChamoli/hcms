<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Workforce\Models\Position;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Transfer / promote and Change manager: the one place an employee's position and reporting lines change (owner,
 * 2026-11-27: one place for each entry). Both are guided, effective-dated changes (context → change → Before → After →
 * confirm) written by AssignPositionAction / ChangeManagerAction. The Employee 360 offers them in its Action menu and on
 * Profile → Employment; the Edit page offers the same two in its Employment step (it has no position fields of its own).
 */
final class PlacementActions
{
    public static function transfer(): Action
    {
        return Action::make('assignPosition')
            ->label('Transfer / promote')
            ->icon(Heroicon::OutlinedArrowTrendingUp)
            ->authorize(fn (Employee $record) => auth()->user()->can('assignPosition', $record))
            ->modalHeading('Transfer or promote')
            ->modalDescription('A guided change: what is true today, what changes, then the Before → After on its effective date. Audited.')
            ->modalSubmitActionLabel('Confirm change')
            ->fillForm(fn (Employee $record) => ['effective_from' => now()->toDateString(), 'change_type' => 'transfer'])
            // UX.15: context → change → review → confirm (same fields, validation and AssignPositionAction).
            ->steps([
                Step::make('Context')->description('Today, and the kind of change')->schema([
                    self::context(),
                    Grid::make(3)->schema([
                        Select::make('change_type')->options(config('peopleos.people.position_change_types'))->required(),
                        DatePicker::make('effective_from')->label('Effective from')->required()->live(),
                    ]),
                ]),
                Step::make('Change')->description('Only what is different')->schema([
                    Grid::make(3)->schema(array_map(fn ($field) => $field->live(), CreateEmployee::positionFields())),
                    // Master-relationships R1: remove optional fields from the effective date; fields left blank stay as they are.
                    Select::make('clear')->label('Remove from the effective date')->multiple()->live()
                        ->options(fn (Employee $record) => collect(EmployeePosition::DIMENSIONS)->except('company_id')
                            ->filter(fn (array $d, string $column) => filled($record->currentPosition?->{$column}))->map(fn (array $d) => $d[1])->all())
                        ->helperText('Fields to leave empty from the effective date. Fields left blank above stay as they are.'),
                    // Phase 10: occupy a position (seat) — validated for status and capacity — or vacate it.
                    Grid::make(3)->schema([
                        Select::make('position_id')->label('Position (seat)')->searchable()
                            ->options(fn () => Position::query()->where('status', 'open')->orderBy('code')->limit(500)->get(['id', 'code', 'title'])->mapWithKeys(fn ($p) => [$p->id => "{$p->code} · {$p->title}"])->all())
                            ->visible(fn () => auth()->user()->can('workforce.view') || auth()->user()->can('workforce.manage')),
                        TextInput::make('fte')->label('FTE')->numeric()->minValue(0.01)->maxValue(1.5)->step(0.05),
                        Toggle::make('vacate_position')->label('Vacate current position')->live(),
                    ]),
                ]),
                Step::make('Review')->description('Before → After, then confirm')->schema([
                    BeforeAfterPreview::position(),
                    AuditReasonField::make()->required(),
                ]),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    $position = app(AssignPositionAction::class)->handle($record, $data, $data['change_type'], $data['effective_from'], $reason);
                    Notification::make()->success()->title('Position assigned')->send();
                    self::requirementWarnings($position->requirementWarnings);
                } catch (InvalidArgumentException|ApplicabilityRefusedException $e) {
                    // A master that does not apply to the company is refused with its reason, never an error page.
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    public static function changeManager(): Action
    {
        return Action::make('changeManager')
            ->label('Change manager')
            ->icon(Heroicon::OutlinedUserCircle)
            ->authorize(fn (Employee $record) => auth()->user()->can('assignPosition', $record))
            ->modalHeading('Change manager')
            ->modalDescription('A guided change: today’s reporting lines, the new one, then the Before → After on its effective date. Audited.')
            ->modalSubmitActionLabel('Confirm reporting change')
            ->fillForm(fn () => ['effective_from' => now()->toDateString(), 'type' => 'line'])
            // UX.15: context → change → review → confirm (same fields, validation and ChangeManagerAction).
            ->steps([
                Step::make('Context')->description('Today, and which relationship')->schema([
                    self::context(true),
                    Select::make('type')->label('Relationship')->options(config('peopleos.people.reporting_types'))->required(),
                ]),
                Step::make('Change')->description('Who, and from when')->schema([
                    Select::make('manager_id')->label('Manager')->options(fn (Employee $record) => CreateEmployee::managerOptions($record->id))->searchable()->required()->live(),
                    DatePicker::make('effective_from')->label('Effective from')->required()->live(),
                ]),
                Step::make('Review')->description('Before → After, then confirm')->schema([
                    BeforeAfterPreview::manager(),
                    AuditReasonField::make(),
                ]),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    app(ChangeManagerAction::class)->change($record, Employee::query()->findOrFail($data['manager_id']), auth()->user(), $data['type'], $data['effective_from'], $reason);
                    Notification::make()->success()->title('Reporting line updated')->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    /**
     * Master relationships R2: what the employment data requirements found missing or inconsistent (Warn): the change
     * went through and the audit event keeps the list.
     *
     * @param  list<string>  $warnings
     */
    public static function requirementWarnings(array $warnings): void
    {
        if ($warnings !== []) {
            Notification::make()->warning()->title('Saved. Some employment data needs attention')->body(implode("\n", $warnings))->persistent()->send();
        }
    }

    /** UX.15: step 1 of a guided change — what is recorded today, read-only. */
    private static function context(bool $reporting = false): SchemaView
    {
        return SchemaView::make('filament.forms.change-context')->columnSpanFull()
            ->viewData(function (?Employee $record) use ($reporting) {
                $record?->loadMissing(['currentPosition.designation', 'currentPosition.department', 'currentPosition.location', 'currentPosition.grade', 'currentManager.manager.person']);
                $p = $record?->currentPosition;
                $facts = $reporting
                    ? ['Reports to' => $record?->currentManager?->manager?->person?->display_name ?? 'No line manager', 'Designation' => $p?->designation?->name, 'Department' => $p?->department?->name]
                    : ['Designation' => $p?->designation?->name, 'Department' => $p?->department?->name, 'Location' => $p?->location?->name, 'Grade' => $p?->grade?->name, 'In role since' => $p?->effective_from?->format('j M Y')];

                return ['record' => $record, 'facts' => $facts, 'hint' => $reporting ? 'Choose the relationship that changes: line, functional, dotted line, project, mentor, buddy or HR partner.' : null];
            });
    }
}
