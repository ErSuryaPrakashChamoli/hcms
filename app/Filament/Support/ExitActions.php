<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Policies\ExitCasePolicy;
use App\Domain\Exit\Services\ExitInterviews;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Platform\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/** Initiate, resign, clear, settle, interview, complete, alumni, letters. */
final class ExitActions
{
    public static function initiate(): Action
    {
        return Action::make('initiate')->label('Initiate exit')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
            ->visible(fn () => auth()->user()->can('exit.manage'))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => Employee::query()->with('person')->employed()->where('lifecycle_state', '!=', 'notice_period')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                Select::make('type')->options(config('peopleos.exit.types'))->default('resignation')->required()->live(),
                DatePicker::make('resignation_date')->label('Resignation / decision date')->native(false)->default(now())->required(),
                TextInput::make('notice_days')->numeric()->minValue(0)->default(fn () => app(SettingsRepository::class)->get('exit.notice_days', 30))->visible(fn (Get $get) => ! in_array($get('type'), config('peopleos.exit.immediate_types'), true)),
                DatePicker::make('last_working_day')->native(false)->placeholder('Resignation date + notice'),
                Select::make('knowledge_transfer_to')->label('Knowledge transfer to')->searchable()->placeholder('—')->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                Toggle::make('is_rehire_eligible')->label('Eligible for rehire')->default(true),
                Textarea::make('reason')->rows(3)->maxLength(2000),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(function () use ($data) {
                $case = app(Exits::class)->initiate(Employee::query()->findOrFail($data['employee_id']), $data['type'], $data['reason'] ?? null, $data['resignation_date'], $data['last_working_day'] ?? null, isset($data['notice_days']) ? (int) $data['notice_days'] : null, auth()->user(), ['knowledge_transfer_to' => $data['knowledge_transfer_to'] ?? null, 'is_rehire_eligible' => (bool) ($data['is_rehire_eligible'] ?? true)]);

                return $case->number.' initiated; last working day '.$case->last_working_day->toDateString();
            }, fn ($m) => $m));
    }

    public static function resign(): Action
    {
        return Action::make('resign')->label('Resign')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
            ->visible(fn () => auth()->user()->can('exit.resign') && ($me = ServiceDeskActions::me()) && $me->lifecycle_state->isEmployed() && $me->lifecycle_state->value !== 'notice_period' && ExitCase::query()->where('employee_id', $me->id)->whereIn('status', ExitCase::OPEN)->doesntExist())
            ->requiresConfirmation()->modalDescription(fn () => 'Your notice period is '.app(SettingsRepository::class)->get('exit.notice_days', 30).' days from today. HR and your manager will be informed.')
            ->schema([
                Textarea::make('reason')->label('Reason for leaving')->required()->rows(4)->maxLength(2000),
                DatePicker::make('requested_last_day')->label('Requested last working day')->native(false)->placeholder('End of notice period')->minDate(now()),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->resign(ServiceDeskActions::me(), $data['reason'], $data['requested_last_day'] ?? null, auth()->user()), 'Resignation submitted'));
    }

    /** @return array<int, Action> */
    public static function forCase(): array
    {
        $manage = fn () => auth()->user()->can('exit.manage');
        $own = fn (ExitCase $record) => ExitCasePolicy::isOwn(auth()->user(), $record);

        return [
            Action::make('startClearance')->label('Start clearance')->icon(Heroicon::OutlinedClipboardDocumentCheck)->color('primary')
                ->visible(fn (ExitCase $record) => in_array($record->status, ['initiated', 'notice'], true) && $manage())
                ->requiresConfirmation()
                ->action(fn (ExitCase $record) => ServiceDeskActions::run(fn () => app(Exits::class)->startClearance($record, auth()->user()), 'Clearance started; owners notified')),
            Action::make('interview')->label(fn (ExitCase $record) => $own($record) ? 'My exit interview' : 'Record exit interview')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                ->visible(fn (ExitCase $record) => $record->isOpen() || $record->status === 'completed')
                ->visible(fn (ExitCase $record) => ($record->isOpen() || $record->status === 'completed') && ($own($record) || auth()->user()->can('exit.interview')))
                ->schema(fn (ExitCase $record) => [
                    Select::make('reason_for_leaving')->options(config('peopleos.exit.interview_reasons'))->required()->default($record->interview?->reason_for_leaving),
                    Section::make('How would you rate…')->columns(2)->schema(collect(config('peopleos.exit.interview_dimensions'))->map(fn ($label, $key) => Radio::make("ratings.{$key}")->label($label)->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])->inline()->default($record->interview?->ratings[$key] ?? null))->values()->all()),
                    Toggle::make('would_recommend')->label('Would recommend the company as a place to work')->default($record->interview?->would_recommend),
                    Toggle::make('would_rejoin')->label('Would consider rejoining')->default($record->interview?->would_rejoin),
                    Textarea::make('liked_most')->label('What did you like most?')->rows(2)->default($record->interview?->liked_most),
                    Textarea::make('suggestions')->rows(3)->default($record->interview?->suggestions),
                ])
                ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(fn () => app(ExitInterviews::class)->submit($record, $data, auth()->user(), $own($record)), 'Exit interview saved')),
            Action::make('letter')->label('Generate letter')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->visible(fn (ExitCase $record) => auth()->user()->can('letter.issue'))
                ->schema([
                    Select::make('template_id')->label('Template')->required()->options(fn () => LetterTemplate::query()->where('status', 'active')->whereIn('type', ['relieving', 'experience', 'salary_certificate', 'employment_certificate', 'noc'])->pluck('name', 'id')->all()),
                    KeyValue::make('extra')->label('Extra variables')->keyLabel('Variable')->valueLabel('Value')->helperText('e.g. purpose → visa application'),
                ])
                ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->generate(LetterTemplate::query()->findOrFail($data['template_id']), $record->employee, $data['extra'] ?? [], auth()->user(), $record), fn ($l) => "Letter {$l->number} ".($l->status === 'approved' ? 'ready to issue' : 'sent for approval'))),
            Action::make('withdraw')->label('Withdraw resignation')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                ->visible(fn (ExitCase $record) => $record->type === 'resignation' && in_array($record->status, ['initiated', 'notice', 'clearance'], true) && ($manage() || $own($record)))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->withdraw($record, $data['reason'], auth()->user()), 'Resignation withdrawn; employee is active again')),
            Action::make('complete')->label('Complete exit')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (ExitCase $record) => $record->isOpen() && $manage())
                ->requiresConfirmation()->modalDescription('Marks the employee as exited on the last working day and disables their login. Requires all clearances and an approved settlement.')
                ->schema([Checkbox::make('skip_settlement')->label('Complete without an approved settlement (e.g. death, no dues)')])
                ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->complete($record, auth()->user(), ! ($data['skip_settlement'] ?? false)), 'Exit completed')),
            Action::make('alumni')->label('Create alumni profile')->icon(Heroicon::OutlinedUserGroup)->color('primary')
                ->visible(fn (ExitCase $record) => $record->status === 'completed' && $record->alumni_created_at === null && $manage())
                ->schema([
                    TextInput::make('personal_email')->email()->maxLength(255),
                    TextInput::make('phone')->maxLength(32),
                    Toggle::make('portal_enabled')->label('Enable alumni portal login')->default(true),
                    Toggle::make('consent_to_contact')->label('Consented to be contacted')->default(true),
                ])
                ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->createAlumni($record, auth()->user(), array_filter($data, fn ($v) => $v !== null && $v !== '')), 'Alumni profile created')),
        ];
    }

    /** @return array<int, Action> */
    public static function forClearance(): array
    {
        $can = fn (ExitClearance $record) => $record->status === 'pending' && $record->exitCase->status === 'clearance' && (auth()->user()->can('exit.manage') || (auth()->user()->can('exit.clear') && $record->isOwnedBy(auth()->user())));

        return [
            Action::make('clear')->label('Clear')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible($can)
                ->schema(fn (ExitClearance $record) => [
                    Section::make('Checklist')->schema(collect($record->items ?? [])->map(fn ($item, $i) => Checkbox::make("items.{$i}")->label($item['item'])->default((bool) $item['done']))->values()->all())->visible(! empty($record->items)),
                    TextInput::make('recoverable_amount')->label('Amount to recover in F&F')->numeric()->minValue(0)->default(0)->helperText('Unreturned property, pending advances, damages'),
                    Textarea::make('remarks')->maxLength(1000),
                ])
                ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->clearStage($record, auth()->user(), $data['remarks'] ?? null, (float) ($data['recoverable_amount'] ?? 0), array_map(fn ($v) => (bool) $v, $data['items'] ?? [])), 'Stage cleared')),
            Action::make('block')->label('Block')->icon(Heroicon::OutlinedHandRaised)->color('danger')
                ->visible($can)
                ->schema([Textarea::make('remarks')->required()->maxLength(1000)])
                ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->blockStage($record, auth()->user(), $data['remarks']), 'Stage blocked; HR notified')),
            Action::make('unblock')->label('Reopen')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn (ExitClearance $record) => $record->status === 'blocked' && (auth()->user()->can('exit.manage') || $record->isOwnedBy(auth()->user())))
                ->action(function (ExitClearance $record) {
                    $record->update(['status' => 'pending']);
                    Notification::make()->success()->title('Stage reopened')->send();
                }),
            Action::make('na')->label('Not applicable')->icon(Heroicon::OutlinedMinusCircle)->color('gray')
                ->visible(fn (ExitClearance $record) => $record->status === 'pending' && auth()->user()->can('exit.manage'))
                ->schema([Textarea::make('remarks')->required()->maxLength(500)])
                ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(fn () => app(Exits::class)->markNotApplicable($record, auth()->user(), $data['remarks']), 'Marked not applicable')),
        ];
    }

    /** @return array<int, Action> */
    public static function forSettlement(): array
    {
        $settle = fn () => auth()->user()->can('exit.settle');

        return [
            Action::make('calculate')->label(fn (FinalSettlement $record) => $record->status === 'draft' ? 'Calculate' : 'Recalculate')->icon(Heroicon::OutlinedCalculator)->color('primary')
                ->visible(fn (FinalSettlement $record) => $record->isEditable() && $settle())
                ->action(fn (FinalSettlement $record) => ServiceDeskActions::run(fn () => app(FinalSettlements::class)->calculate($record->exitCase, auth()->user()), fn ($s) => 'Calculated: net '.number_format((float) $s->net_amount, 2))),
            Action::make('addLine')->label('Add line')->icon(Heroicon::OutlinedPlus)->color('gray')
                ->visible(fn (FinalSettlement $record) => $record->isEditable() && $settle())
                ->schema([
                    Select::make('type')->options(['earning' => 'Earning', 'deduction' => 'Deduction'])->required(),
                    TextInput::make('name')->required()->maxLength(255)->placeholder('Incentive, loan recovery, bonus…'),
                    TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(fn (FinalSettlement $record, array $data) => ServiceDeskActions::run(fn () => app(FinalSettlements::class)->addLine($record, $data['type'], $data['name'], (float) $data['amount'], $data['note'] ?? null), 'Line added')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (FinalSettlement $record) => $record->status === 'calculated' && $settle())
                ->requiresConfirmation()->modalDescription('Freezes the lines and posts leave encashment to the ledger.')
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (FinalSettlement $record, array $data) => ServiceDeskActions::run(fn () => app(FinalSettlements::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Settlement approved')),
            Action::make('paid')->label('Mark paid')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (FinalSettlement $record) => $record->status === 'approved' && $settle())
                ->schema([TextInput::make('reference')->label('Payment reference')->required()->maxLength(255)])
                ->action(fn (FinalSettlement $record, array $data) => ServiceDeskActions::run(fn () => app(FinalSettlements::class)->markPaid($record, $data['reference'], auth()->user()), 'Settlement paid')),
        ];
    }
}
