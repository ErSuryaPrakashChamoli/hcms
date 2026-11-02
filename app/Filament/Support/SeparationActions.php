<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Services\ExitHandover;
use App\Domain\Exit\Services\ExitWithdrawals;
use App\Domain\Exit\Services\SeparationDesk;
use App\Domain\Exit\Support\NoticeShortfall;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

/**
 * HROne parity WO-11 (matrix 23 §23.6): the separation desk's actions — on the exit case (accept / reject, last working day,
 * notice relaxation, approver's reason, rehire eligibility, settlement month), on a clearance stage (expected date,
 * evidence, cancel, the HR stage's "Roles held" and team hand-over), on withdrawal requests and on an exited employee
 * (not eligible for rehire). Each forwards to SeparationDesk / ExitWithdrawals / ExitHandover, which check again.
 */
final class SeparationActions
{
    /** @return list<Action> header actions of the exit case (appended after the existing ones; "Decide" leads when shown) */
    public static function forCase(): array
    {
        return [self::changeLastWorkingDay(), self::noticeRelaxation(), self::approverReason(), self::rehireEligibility(), self::settlementMonth()];
    }

    /** §23.6.1 #7–10: accept or do not accept the resignation (only with exit.resignation.requires_acceptance on). */
    public static function decide(): Action
    {
        return Action::make('decideResignation')->label('Accept / reject resignation')->icon(Heroicon::OutlinedCheckCircle)->color('primary')
            ->visible(fn (ExitCase $record) => app(SeparationDesk::class)->awaitsAcceptance($record) && app(SeparationDesk::class)->mayDecide(auth()->user(), $record))
            ->steps([
                Step::make('Decision')->description('Accept the resignation, or do not accept it (it is withdrawn)')->schema([
                    Radio::make('decision')->options(['accept' => 'Yes, accept', 'reject' => 'Do not accept (the resignation is withdrawn)'])->default('accept')->required()->live(),
                    Select::make('approver_reason_id')->label('Approver\'s separation reason')->native(false)->placeholder('—')
                        ->options(fn () => self::approverReasons())->visible(fn (Get $get) => $get('decision') === 'accept' && self::approverReasons() !== []),
                    Textarea::make('approver_remark')->label('Approver\'s remark')->rows(2)->maxLength(2000)->visible(fn (Get $get) => $get('decision') === 'accept' && self::approverReasons() !== []),
                    Textarea::make('note')->label('Note')->rows(2)->maxLength(1000)->required(fn (Get $get) => $get('decision') === 'reject'),
                ]),
                Step::make('Last working day')->description('Only when it changes')->schema([
                    DatePicker::make('last_working_day')->label('Last working day')->native(false)->default(fn (ExitCase $record) => $record->last_working_day),
                    Textarea::make('last_working_day_reason')->label('Why the last working day changes')->rows(2)->maxLength(500),
                    TextInput::make('notice_relaxation_days')->label('Notice relaxation (days)')->numeric()->minValue(0)
                        ->default(fn (ExitCase $record) => (int) $record->notice_relaxation_days)->helperText('Days of the notice shortfall that are not recovered.'),
                ])->visible(fn (Get $get) => $get('decision') === 'accept'),
            ])
            ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->decide($record, auth()->user(), (string) $data['decision'], $data['note'] ?? null, [
                    'approver_reason_id' => $data['approver_reason_id'] ?? null, 'approver_remark' => $data['approver_remark'] ?? null,
                    'last_working_day' => $data['last_working_day'] ?? null, 'last_working_day_reason' => $data['last_working_day_reason'] ?? null,
                    'notice_relaxation_days' => $data['notice_relaxation_days'] ?? null,
                ]),
                fn () => ($data['decision'] ?? null) === 'reject' ? 'Resignation not accepted; it is withdrawn' : 'Resignation accepted'));
    }

    /** §23.6.1 #9: change the last working day (a reason; within the exit policy's backdating). */
    public static function changeLastWorkingDay(): Action
    {
        return Action::make('changeLastWorkingDay')->label('Change last working day')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
            ->visible(fn (ExitCase $record) => $record->isOpen() && self::manages($record) && ! in_array($record->settlement?->status, ['approved', 'paid'], true))
            ->schema([
                DatePicker::make('last_working_day')->label('New last working day')->native(false)->required()->default(fn (ExitCase $record) => $record->last_working_day),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(500),
            ])
            ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->changeLastWorkingDay($record, $data['last_working_day'], (string) $data['reason'], auth()->user()),
                fn (ExitCase $case) => 'Last working day is now '.$case->last_working_day->format('d M Y').(($days = NoticeShortfall::days($case)) > 0 ? "; notice shortfall {$days} day(s)" : '')));
    }

    /** §23.6.1 #10: notice relaxation days (part of the shortfall waived). */
    public static function noticeRelaxation(): Action
    {
        return Action::make('noticeRelaxation')->label('Notice relaxation')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
            ->visible(fn (ExitCase $record) => $record->type === 'resignation' && $record->isOpen() && self::manages($record) && ! in_array($record->settlement?->status, ['approved', 'paid'], true))
            ->modalDescription(fn (ExitCase $record) => ($n = NoticeShortfall::for($record)) !== null
                ? "Notice {$n['notice_days']} day(s); served {$n['served_days']}; relaxed {$n['relaxation_days']}; shortfall {$n['shortfall_days']} day(s). Relaxation days are added, for instance, when the last day falls on a holiday or weekly off." : null)
            ->schema([
                TextInput::make('days')->label('Relaxation (days)')->numeric()->minValue(0)->required()->default(fn (ExitCase $record) => (int) $record->notice_relaxation_days),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(500),
            ])
            ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->setNoticeRelaxation($record, (int) $data['days'], (string) $data['reason'], auth()->user()),
                fn (ExitCase $case) => 'Notice shortfall now '.NoticeShortfall::days($case).' day(s)'));
    }

    /** §23.6.1 #8, #17: the approver's separation reason, kept apart from the employee's. */
    public static function approverReason(): Action
    {
        return Action::make('approverReason')->label('Approver\'s reason')->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->color('gray')
            ->visible(fn (ExitCase $record) => ($record->isOpen() || $record->status === 'completed') && self::manages($record) && self::approverReasons() !== [])
            ->schema([
                Select::make('approver_reason_id')->label('Approver\'s separation reason')->native(false)->required()->options(fn () => self::approverReasons())
                    ->default(fn (ExitCase $record) => $record->approver_reason_id),
                Textarea::make('approver_remark')->label('Remark')->rows(2)->maxLength(2000)->default(fn (ExitCase $record) => $record->approver_remark),
            ])
            ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->setApproverReason($record, (int) $data['approver_reason_id'], $data['approver_remark'] ?? null, auth()->user()), 'Approver\'s reason saved'));
    }

    /** §23.6.1 #11: rehire eligibility on any open or completed exit. */
    public static function rehireEligibility(): Action
    {
        return Action::make('rehireEligibility')->label('Rehire eligibility')->icon(Heroicon::OutlinedArrowPathRoundedSquare)->color('gray')
            ->visible(fn (ExitCase $record) => ($record->isOpen() || $record->status === 'completed') && self::manages($record))
            ->schema(self::rehireFields(fn (ExitCase $record) => (bool) $record->is_rehire_eligible))
            ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->setRehireEligibility($record, (bool) $data['is_rehire_eligible'], filled($data['rehire_block_reason_id'] ?? null) ? (int) $data['rehire_block_reason_id'] : null, $data['rehire_comment'] ?? null, auth()->user()),
                fn (ExitCase $case) => $case->is_rehire_eligible ? 'Eligible for rehire' : 'Not eligible for rehire'));
    }

    /** §23.6.1 #14: the month the full and final settlement is paid in. */
    public static function settlementMonth(): Action
    {
        return Action::make('settlementMonth')->label('Settlement month')->icon(Heroicon::OutlinedBanknotes)->color('gray')
            ->visible(fn (ExitCase $record) => $record->isOpen() && (auth()->user()->can('exit.manage') || auth()->user()->can('exit.settle')) && $record->settlement?->status !== 'paid')
            ->schema([
                TextInput::make('month')->label('Pay the settlement in (YYYY-MM)')->placeholder(fn (ExitCase $record) => $record->last_working_day->format('Y-m').' (the month of the last working day)')
                    ->regex('/^\d{4}-\d{2}$/')->default(fn (ExitCase $record) => $record->settlement_month?->format('Y-m')),
            ])
            ->action(fn (ExitCase $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->setSettlementMonth($record, $data['month'] ?? null, auth()->user()),
                fn (ExitCase $case) => 'Settlement month: '.($case->settlement_month ?? $case->last_working_day)->format('F Y')));
    }

    /** @return list<Action> row actions of a clearance stage (after Clear / Block / Reopen / Not applicable) */
    public static function forClearance(): array
    {
        return [self::expectedDate(), self::evidence(), self::downloadEvidence(), self::cancelStage(), self::handOverRole(), self::changeTeamManager()];
    }

    public static function expectedDate(): Action
    {
        return Action::make('expectedDate')->label('Expected date')->icon(Heroicon::OutlinedCalendar)->color('gray')
            ->visible(fn (ExitClearance $record) => $record->isOpen() && self::actsOn($record))
            ->schema([DatePicker::make('expected_on')->label('Expected completion date')->native(false)->default(fn (ExitClearance $record) => $record->expected_on)])
            ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(fn () => app(SeparationDesk::class)->setExpectedDate($record, $data['expected_on'] ?? null, auth()->user()), 'Expected date saved'));
    }

    public static function evidence(): Action
    {
        return Action::make('evidence')->label(fn (ExitClearance $record) => $record->evidence_path ? 'Replace evidence' : 'Attach evidence')->icon(Heroicon::OutlinedPaperClip)->color('gray')
            ->visible(fn (ExitClearance $record) => in_array($record->exitCase?->status, [...ExitCase::OPEN, 'completed'], true) && self::actsOn($record))
            ->schema([
                FileUpload::make('file')->label('Evidence file')->required()->disk(config('peopleos.documents.disk', 'local'))->directory('exit-clearances')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('file_name'),
            ])
            ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(fn () => app(SeparationDesk::class)->attachEvidence($record, (string) $data['file'], $data['file_name'] ?? null, auth()->user()), 'Evidence attached'));
    }

    public static function downloadEvidence(): Action
    {
        return Action::make('downloadEvidence')->label('Evidence')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (ExitClearance $record) => filled($record->evidence_path) && auth()->user()->can('view', $record))
            ->url(fn (ExitClearance $record) => ParityFileController::url('exit-clearance', $record, 0))->openUrlInNewTab();
    }

    /** D-WO3: cancel a stage with a reason (closed and kept). */
    public static function cancelStage(): Action
    {
        return Action::make('cancelStage')->label('Cancel stage')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn (ExitClearance $record) => $record->isOpen() && $record->exitCase?->isOpen() && self::manages($record->exitCase))
            ->requiresConfirmation()->modalDescription('The stage is closed as cancelled (not applicable) and kept with its history; nothing is deleted.')
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(fn () => app(SeparationDesk::class)->cancelStage($record, auth()->user(), (string) $data['reason']), 'Stage cancelled'));
    }

    /** §23.6.3 #12 (D-WO10): replace or remove a role the leaver holds — on the HR stage. */
    public static function handOverRole(): Action
    {
        return Action::make('handOverRole')->label('Roles held')->icon(Heroicon::OutlinedKey)->color('gray')
            ->visible(fn (ExitClearance $record) => $record->stage === 'hr' && $record->exitCase?->isOpen() && self::manages($record->exitCase) && auth()->user()->can('user.assign_roles'))
            ->modalDescription(fn (ExitClearance $record) => self::rolesSummary($record->exitCase))
            ->schema(fn (ExitClearance $record) => [
                Select::make('role_id')->label('Role')->native(false)->required()
                    ->options(fn () => collect(app(ExitHandover::class)->rolesHeld($record->exitCase))->reject(fn (array $r) => $r['by_rule'])->mapWithKeys(fn (array $r) => [$r['role_id'] => $r['name'].($r['company'] ? ' ('.$r['company'].')' : '')])->all()),
                Radio::make('mode')->label('Hand over')->options(['replace' => 'Replace: another person takes the role', 'remove' => 'Remove the role'])->default('replace')->required()->live(),
                Select::make('replacement_id')->label('Replace with')->searchable()->required(fn (Get $get) => $get('mode') === 'replace')->visible(fn (Get $get) => $get('mode') === 'replace')
                    ->options(fn () => User::forCurrentTenant()->where('status', 'active')->where('is_platform_admin', false)
                        ->where('id', '!=', (int) $record->exitCase->employee()->value('user_id'))->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(500),
            ])
            ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(
                fn () => app(ExitHandover::class)->handOverRole($record->exitCase, (int) $data['role_id'], ($data['mode'] ?? 'replace') === 'replace' ? User::forCurrentTenant()->findOrFail($data['replacement_id']) : null, auth()->user(), (string) $data['reason']),
                fn () => ($data['mode'] ?? 'replace') === 'replace' ? 'Role handed over' : 'Role removed'));
    }

    /** §23.6.3 #13: move the leaver's team to a new manager from the day after the last working day — on the HR stage. */
    public static function changeTeamManager(): Action
    {
        return Action::make('changeTeamManager')->label('Change manager of the team')->icon(Heroicon::OutlinedUsers)->color('gray')
            ->visible(fn (ExitClearance $record) => $record->stage === 'hr' && $record->exitCase?->isOpen() && self::manages($record->exitCase) && auth()->user()->can('employee.position'))
            ->modalDescription(fn (ExitClearance $record) => 'Effective '.$record->exitCase->last_working_day->copy()->addDay()->format('d M Y').' (the day after the last working day). Reassigning a team is permanent.')
            ->schema(fn (ExitClearance $record) => [
                Select::make('employee_ids')->label('Team members')->multiple()->required()
                    ->options(fn () => app(ExitHandover::class)->reportees($record->exitCase)->mapWithKeys(fn ($r) => [$r->employee_id => trim(($r->employee?->employee_code ?? '').' · '.($r->employee?->person?->full_name ?? ''), ' ·')])->all()),
                Select::make('manager_id')->label('New manager')->searchable()->required()
                    ->options(fn () => Employee::query()->with('person')->employed()->where('id', '!=', $record->exitCase->employee_id)->limit(500)->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(500),
            ])
            ->action(fn (ExitClearance $record, array $data) => ServiceDeskActions::run(
                fn () => app(ExitHandover::class)->changeManager($record->exitCase, array_map('intval', (array) $data['employee_ids']), Employee::query()->findOrFail($data['manager_id']), auth()->user(), (string) $data['reason']),
                fn (int $moved) => "{$moved} team member(s) moved to the new manager"));
    }

    /** §23.6.2: approve / reject / call off a withdrawal request (the relation manager's row actions). */
    public static function forWithdrawal(): array
    {
        return [
            Action::make('approveWithdrawal')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (ExitWithdrawalRequest $record) => $record->isPending() && app(ExitWithdrawals::class)->mayDecide(auth()->user(), $record))
                ->requiresConfirmation()->modalDescription('The resignation is withdrawn: the employee is active again and the open clearance stages become not applicable.')
                ->schema([Textarea::make('note')->maxLength(1000)])
                ->action(fn (ExitWithdrawalRequest $record, array $data) => ServiceDeskActions::run(fn () => app(ExitWithdrawals::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Withdrawal approved')),
            Action::make('rejectWithdrawal')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (ExitWithdrawalRequest $record) => $record->isPending() && app(ExitWithdrawals::class)->mayDecide(auth()->user(), $record))
                ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(1000)])
                ->action(fn (ExitWithdrawalRequest $record, array $data) => ServiceDeskActions::run(fn () => app(ExitWithdrawals::class)->reject($record, auth()->user(), $data['note'] ?? null), 'Withdrawal rejected; the exit goes on')),
            Action::make('cancelWithdrawal')->label('Call off')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn (ExitWithdrawalRequest $record) => $record->isPending() && (int) $record->requested_by === (int) auth()->id())
                ->requiresConfirmation()
                ->action(fn (ExitWithdrawalRequest $record) => ServiceDeskActions::run(fn () => app(ExitWithdrawals::class)->cancel($record, auth()->user()), 'Request called off')),
        ];
    }

    /** §23.2.2 #20: mark an exited employee not eligible for rehire (Employees register). */
    public static function notEligibleForRehire(): Action
    {
        return Action::make('notEligibleForRehire')->label('Not eligible for rehire')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn (Employee $record) => in_array($record->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true) && auth()->user()->can('exit.manage')
                && ExitCase::query()->where('employee_id', $record->id)->where('status', 'completed')->where('is_rehire_eligible', true)->exists())
            ->schema([
                Select::make('rehire_block_reason_id')->label('Reason for blacklisting')->native(false)
                    ->options(fn () => WorkforceReason::query()->ofKind('blacklist')->pluck('name', 'id')->all())
                    ->visible(fn () => WorkforceReason::query()->ofKind('blacklist')->exists())->required(fn () => WorkforceReason::query()->ofKind('blacklist')->exists()),
                Textarea::make('rehire_comment')->label('Comment')->rows(2)->maxLength(500),
            ])
            ->action(fn (Employee $record, array $data) => ServiceDeskActions::run(
                fn () => app(SeparationDesk::class)->markNotEligibleForRehire($record, filled($data['rehire_block_reason_id'] ?? null) ? (int) $data['rehire_block_reason_id'] : null, $data['rehire_comment'] ?? null, auth()->user()),
                'Marked not eligible for rehire'));
    }

    /** "Roles held": the leaver's roles and data access, and what is left to hand over. */
    public static function rolesSummary(?ExitCase $case): ?string
    {
        if ($case === null) {
            return null;
        }
        $handover = app(ExitHandover::class);
        $roles = collect($handover->rolesHeld($case))->map(fn (array $r) => $r['name'].($r['company'] ? ' ('.$r['company'].')' : '').($r['by_rule'] ? ' — by a membership rule' : ''))->implode(', ');
        $scopes = $handover->scopes($case);

        return 'Roles held: '.($roles !== '' ? $roles : 'none').'. Data access: '.($scopes === [] ? 'the whole organisation' : implode('; ', $scopes)).'. Removing a role is permanent for the leaver.';
    }

    /** The "Complete exit" warning: what is still to hand over (never a block). */
    public static function completionWarning(ExitCase $case): ?string
    {
        $left = app(ExitHandover::class)->unresolved($case);

        return $left === [] ? null : 'Warning — still to hand over: '.implode('; ', $left).'. You can complete the exit anyway.';
    }

    /** @return array<int, string> separation reasons offered to approvers */
    public static function approverReasons(): array
    {
        return WorkforceReason::query()->ofKind('separation')->where('for_approver', true)->get()
            ->mapWithKeys(fn (WorkforceReason $r) => [$r->id => $r->name.($r->separation_kind ? ' ('.config("peopleos.people.separation_kinds.{$r->separation_kind}").')' : '')])->all();
    }

    /** @return list<Component|Field> */
    private static function rehireFields(\Closure $eligible): array
    {
        return [
            Toggle::make('is_rehire_eligible')->label('Eligible for rehire')->default($eligible)->live(),
            Select::make('rehire_block_reason_id')->label('Why not eligible for rehire')->native(false)
                ->options(fn () => WorkforceReason::query()->ofKind('blacklist')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => ! $get('is_rehire_eligible') && WorkforceReason::query()->ofKind('blacklist')->exists())->required(fn (Get $get) => ! $get('is_rehire_eligible')),
            Textarea::make('rehire_comment')->label('Comment')->rows(2)->maxLength(500),
            Text::make('Not eligible: the alumni profile (if any) follows, and the pre-employee check shows it when the person applies again.'),
        ];
    }

    private static function manages(?ExitCase $case): bool
    {
        return $case !== null && auth()->user()->can('exit.manage') && app(AccessScopes::class)->allows(auth()->user(), $case);
    }

    private static function actsOn(ExitClearance $stage): bool
    {
        return self::manages($stage->exitCase) || (auth()->user()->can('exit.clear') && $stage->isOwnedBy(auth()->user()));
    }
}
