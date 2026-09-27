<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/** Raise, work and close grievance cases. */
final class GrievanceActions
{
    public static function raise(): Action
    {
        return Action::make('raise')->label('Raise a grievance')->icon(Heroicon::OutlinedShieldExclamation)->color('danger')
            ->visible(fn () => auth()->user()->can('grievance.raise') && ServiceDeskActions::me() !== null)
            ->modalDescription('Grievances are handled confidentially by the people responsible for the category. Anonymous cases cannot be followed up with you.')
            ->schema([
                Select::make('grievance_category_id')->label('Category')->required()->live()->options(fn () => GrievanceCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Toggle::make('is_anonymous')->label('Raise anonymously')->visible(fn (Get $get) => (bool) GrievanceCategory::query()->whereKey($get('grievance_category_id'))->value('allow_anonymous')),
                TextInput::make('subject')->required()->maxLength(255),
                Textarea::make('details')->required()->rows(6)->maxLength(8000)->helperText('What happened, when, where, who was involved, any witnesses.'),
                Select::make('severity')->options(config('peopleos.grievance.severities'))->default('medium')->required(),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(function () use ($data) {
                $case = app(Grievances::class)->raise(GrievanceCategory::query()->findOrFail($data['grievance_category_id']), ServiceDeskActions::me(), $data['subject'], $data['details'], $data['severity'], (bool) ($data['is_anonymous'] ?? false), auth()->user());

                return 'Case '.$case->number.' raised';
            }, fn ($m) => $m));
    }

    /** @return array<int, Action> */
    public static function forCase(): array
    {
        $handler = fn (Grievance $record) => auth()->user()->can('update', $record);
        $employee = fn (Grievance $record) => ! $record->is_anonymous && $record->employee_id && Employee::query()->where('user_id', auth()->id())->where('id', $record->employee_id)->exists();
        $disk = config('peopleos.documents.disk', 'local');

        return [
            Action::make('note')->label(fn (Grievance $record) => $employee($record) && ! $handler($record) ? 'Add information' : 'Add to case file')->icon(Heroicon::OutlinedDocumentPlus)->color('primary')
                ->visible(fn (Grievance $record) => $record->status !== 'closed' && ($handler($record) || $employee($record)))
                ->schema(fn (Grievance $record) => array_filter([
                    $handler($record) ? Select::make('type')->options(config('peopleos.grievance.note_types'))->default('note')->required() : null,
                    Textarea::make('body')->required()->rows(5)->maxLength(8000),
                    FileUpload::make('attachment')->label('Evidence')->disk($disk)->directory('grievances')->maxSize(config('peopleos.documents.max_kb')),
                    $handler($record) && ! $record->is_anonymous ? Toggle::make('visible_to_employee')->label('Visible to the employee') : null,
                ]))
                ->action(fn (Grievance $record, array $data) => ServiceDeskActions::run(fn () => app(Grievances::class)->addNote($record, auth()->user(), $handler($record) ? ($data['type'] ?? 'note') : 'employee', $data['body'], (bool) ($data['visible_to_employee'] ?? false), $data['attachment'] ?? null, isset($data['attachment']) ? basename($data['attachment']) : null), 'Added to the case file')),
            Action::make('status')->label('Update status')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn (Grievance $record) => $record->isOpen() && $handler($record))
                ->schema([Select::make('status')->options(collect(config('peopleos.grievance.statuses'))->only(['under_review', 'investigating', 'action_taken'])->all())->required(), Textarea::make('note')->maxLength(500)])
                ->action(fn (Grievance $record, array $data) => ServiceDeskActions::run(fn () => app(Grievances::class)->setStatus($record, $data['status'], auth()->user(), $data['note'] ?? null), 'Status updated')),
            Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn (Grievance $record) => $record->isOpen() && (auth()->user()->can('grievance.manage') || $record->assignee_id === auth()->id()))
                ->schema([Select::make('assignee_id')->label('Handler')->required()->searchable()->options(fn () => User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('grievance.view') || $u->hasPermission('grievance.manage'))->pluck('name', 'id')->all())])
                ->action(fn (Grievance $record, array $data) => ServiceDeskActions::run(fn () => app(Grievances::class)->assign($record, User::query()->findOrFail($data['assignee_id']), auth()->user()), 'Assigned')),
            Action::make('grant')->label('Grant access')->icon(Heroicon::OutlinedKey)->color('warning')
                ->visible(fn (Grievance $record) => $record->isOpen() && (auth()->user()->can('grievance.manage') || $record->assignee_id === auth()->id()))
                ->schema([Select::make('user_id')->label('User')->required()->searchable()->options(fn () => User::forCurrentTenant()->pluck('name', 'id')->all()), Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (Grievance $record, array $data) => ServiceDeskActions::run(fn () => app(Grievances::class)->grantAccess($record, User::query()->findOrFail($data['user_id']), $data['reason'], auth()->user()), 'Access granted')),
            Action::make('resolve')->label('Resolve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Grievance $record) => $record->isOpen() && $handler($record))
                ->schema([Textarea::make('resolution')->required()->rows(5)->maxLength(8000)])
                ->action(fn (Grievance $record, array $data) => ServiceDeskActions::run(fn () => app(Grievances::class)->resolve($record, $data['resolution'], auth()->user()), 'Resolved')),
            Action::make('close')->label('Close case')->icon(Heroicon::OutlinedLockClosed)->color('gray')
                ->visible(fn (Grievance $record) => in_array($record->status, ['resolved', 'withdrawn'], true) && $handler($record))
                ->requiresConfirmation()
                ->action(fn (Grievance $record) => ServiceDeskActions::run(fn () => app(Grievances::class)->close($record, auth()->user()), 'Case closed')),
            Action::make('withdraw')->label('Withdraw')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (Grievance $record) => $record->isOpen() && $employee($record))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Grievance $record, array $data) => ServiceDeskActions::run(fn () => app(Grievances::class)->withdraw($record, auth()->user(), $data['reason']), 'Case withdrawn')),
        ];
    }
}
