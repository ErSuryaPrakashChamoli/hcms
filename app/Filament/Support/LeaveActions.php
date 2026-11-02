<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\CompOffCredits;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeavePrivacy;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Leave\Services\TeamAway;
use App\Support\Storage\StagedUpload;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use RuntimeException;

/** Apply / approve / reject / cancel shared by the register, the 360 and the inbox. */
final class LeaveActions
{
    /** @return array<int, Component> */
    public static function applyForm(callable $employee): array
    {
        return [
            // HROne parity RQ-2: whose request it is.
            RequestChrome::header(fn () => $employee()),
            Select::make('leave_type_id')
                ->label('Leave type')
                ->options(function () use ($employee) {
                    $emp = $employee();
                    if (! $emp->exists) {
                        return []; // "Apply on behalf" before an employee is picked
                    }
                    $codes = array_keys(app(LeaveEntitlements::class)->for($emp));

                    return LeaveType::query()->where('status', 'active')->orderBy('sort_order')->get()
                        ->filter(fn (LeaveType $t) => in_array($t->code, $codes, true) || $t->category === 'unpaid')
                        ->mapWithKeys(fn (LeaveType $t) => [$t->id => self::typeLabel($emp, $t)])
                        ->all();
                })
                ->required()
                ->live(),
            // HROne parity RQ-4 (§20.1.18 Leave #9): the worked days the comp-off comes from.
            Section::make('Comp-off from')->description('The days you worked that this comp-off comes from. The oldest is used first.')
                ->visible(fn (Get $get) => self::isCompOff($get('leave_type_id')) && $employee()->exists)
                ->schema([Text::make(fn (Get $get) => self::renderCompOffSources($employee(), $get('leave_type_id')))]),
            Grid::make(2)->schema([
                DatePicker::make('from_date')->native(false)->required()->default(now())->live(),
                DatePicker::make('to_date')->native(false)->required()->default(now())->afterOrEqual('from_date')->live(),
                Select::make('from_session')->label('Start')->options(LeaveRequest::SESSIONS)->default('full')->live(),
                Select::make('to_session')->label('End')->options(LeaveRequest::SESSIONS)->default('full')->live()
                    ->visible(fn (Get $get) => $get('from_date') !== $get('to_date')),
            ]),
            // Experience Transformation §21: days and balance before → after, before submitting.
            BeforeAfterPreview::leave($employee),
            // HROne parity RQ-4 (§20.1.18 Leave #10): who else in the team is away on these dates, by the leave calendar's rules.
            Section::make('Who else is away')->description('People in the team on leave on these dates, as the leave calendar shows them to you.')
                ->collapsible()->collapsed()
                ->visible(fn (Get $get) => TeamAway::mayView(auth()->user()) && $employee()->exists && filled($get('from_date')))
                ->schema([Text::make(fn (Get $get) => self::renderTeamAway($employee(), $get('from_date'), $get('to_date')))]),
            Textarea::make('reason')->required()->maxLength(255),
            FileUpload::make('document_upload')->label('Supporting document')->helperText('Optional. Saved to the employee\'s documents.')
                ->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(config('peopleos.documents.max_kb'))
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                ->live(),
            Select::make('document_id')->label('Or attach an existing document')->options(fn () => $employee()->documents()->pluck('title', 'id')->all())->placeholder('Optional')
                ->visible(fn (Get $get) => blank($get('document_upload')) && $employee()->documents()->exists()),
            // HROne parity RQ-4 (§20.1.18 Leave #8): asked when the leave type's privacy rule lets the employee decide (also when
            // the policy says nothing); "Yes", the default, keeps the leave visible as before.
            Radio::make('visible_to_colleagues')->label('Make this leave visible to colleagues?')
                ->options(['yes' => 'Yes', 'no' => 'No, show me as "Away"'])->default('yes')->inline()
                ->helperText('With "No", colleagues see "Away" on these dates, without the leave type or reason. Your approvers and HR still see it in full.')
                ->visible(fn (Get $get) => self::privacyMode($employee(), $get('leave_type_id')) === 'employee'),
            Text::make('Colleagues see this leave as "Away", without the leave type or reason: the leave policy keeps this leave type private.')
                ->visible(fn (Get $get) => self::privacyMode($employee(), $get('leave_type_id')) === 'private'),
            // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the leave policy.
            RequestChrome::route('leave', fn () => $employee()),
            // HROne parity RQ-1: the person's own open requests of the time-office family (hidden on an on-behalf form).
            PendingRequestsPanel::make(fn () => $employee()),
        ];
    }

    public static function apply(Employee $employee, array $data): void
    {
        $uploaded = null;
        try {
            $type = LeaveType::query()->findOrFail($data['leave_type_id']);
            $document = filled($data['document_upload'] ?? null)
                ? $uploaded = self::storeUpload($employee, $type, $data['document_upload'])
                : (isset($data['document_id']) ? EmployeeDocument::query()->find($data['document_id']) : null);
            $request = app(Leaves::class)->request($employee, $type, $data['from_date'], $data['to_date'], $data['reason'], $data['from_session'] ?? 'full', ($data['from_date'] === $data['to_date'] ? $data['from_session'] : $data['to_session']) ?? 'full', $document,
                private: isset($data['visible_to_colleagues']) ? $data['visible_to_colleagues'] === 'no' : null);
            // HROne parity RQ-2: the same message, with "Follow this request" / "Raise another" / "View my requests" on one's own.
            RequestChrome::confirmed('leave', $request, sprintf('Leave requested: %.1f day(s)', (float) $request->days));
        } catch (RuntimeException $e) {
            // A refused request leaves no orphan document behind.
            $uploaded && app(Documents::class)->delete($uploaded, 'Leave request not submitted');
            Notification::make()->danger()->title('Cannot apply')->body($e->getMessage())->persistent()->send();
        }
    }

    private static function storeUpload(Employee $employee, LeaveType $type, string $staged): EmployeeDocument
    {
        $file = StagedUpload::toUploadedFile($staged);
        try {
            return app(Documents::class)->store($employee, $file, title: "{$type->name}: supporting document");
        } finally {
            StagedUpload::discard($staged, $file);
        }
    }

    /** @return array<int, Action> */
    public static function forRequests(): array
    {
        $pending = fn (LeaveRequest $record) => $record->status === 'pending';

        return [
            Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                ->visible(fn (LeaveRequest $record) => $pending($record) && auth()->user()->can('approve', $record))
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (LeaveRequest $record, array $data) => self::run(fn () => app(Leaves::class)->approve($record, $data['note'] ?? null), 'Leave approved')),
            Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn (LeaveRequest $record) => $pending($record) && auth()->user()->can('approve', $record))
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(fn (LeaveRequest $record, array $data) => self::run(fn () => app(Leaves::class)->reject($record, $data['note']), 'Leave rejected')),
            Action::make('cancel')->label('Cancel')->icon('heroicon-m-no-symbol')->color('gray')
                ->visible(fn (LeaveRequest $record) => $record->isOpen() && auth()->user()->can('cancel', $record))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (LeaveRequest $record, array $data) => self::run(fn () => app(Leaves::class)->cancel($record, $data['reason']), 'Cancellation recorded')),
            Action::make('approveCancellation')->label('Approve cancellation')->icon('heroicon-m-check-circle')->color('warning')
                ->visible(fn (LeaveRequest $record) => $record->status === 'cancel_requested' && auth()->user()->can('approve', $record))
                ->requiresConfirmation()
                ->schema([Textarea::make('note')->maxLength(255)])
                ->action(fn (LeaveRequest $record, array $data) => self::run(fn () => app(Leaves::class)->approveCancellation($record, $data['note'] ?? null), 'Cancellation approved; balance restored')),
            Action::make('rejectCancellation')->label('Keep leave')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                ->visible(fn (LeaveRequest $record) => $record->status === 'cancel_requested' && auth()->user()->can('approve', $record))
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(fn (LeaveRequest $record, array $data) => self::run(fn () => app(Leaves::class)->rejectCancellation($record, $data['note']), 'Cancellation rejected; leave stays approved')),
            // HROne setup parity P3: a request sent back to its requester is resubmitted here.
            SendBackActions::resubmit(),
        ];
    }

    /** HROne parity RQ-4: the leave type's privacy rule for the employee ('public', 'employee', 'private'); null before a type is chosen. */
    public static function privacyMode(Employee $employee, mixed $typeId): ?string
    {
        $type = $employee->exists && filled($typeId) ? LeaveType::query()->find($typeId) : null;

        return $type !== null ? app(LeavePrivacy::class)->mode($employee, $type) : null;
    }

    public static function isCompOff(mixed $typeId): bool
    {
        return filled($typeId) && LeaveType::query()->whereKey($typeId)->where('category', 'comp_off')->exists();
    }

    /** HROne parity RQ-4: "Comp-off from" — the worked days behind the comp-off available now (CompOffCredits::sources). */
    public static function renderCompOffSources(Employee $employee, mixed $typeId): HtmlString
    {
        $type = filled($typeId) ? LeaveType::query()->find($typeId) : null;
        $sources = $type !== null && $employee->exists ? app(CompOffCredits::class)->sources($employee, $type) : [];
        if ($sources === []) {
            return new HtmlString('<p class="pos-body-sm pos-muted">No comp-off is available to take.</p>');
        }
        $days = fn (float $d) => rtrim(rtrim(number_format($d, 2), '0'), '.').' '.($d == 1.0 ? 'day' : 'days');
        $items = collect($sources)->map(fn (array $c) => '<li class="pos-body-sm">'.e(implode(' · ', array_filter([
            ($c['claim_id'] !== null ? 'Worked on ' : 'Credited on ').$c['worked_on']->format('D, d M Y'),
            $c['left'] < $c['days'] ? $days($c['left']).' left of '.$days($c['days']) : $days($c['days']),
            $c['expires_on'] !== null ? 'use by '.$c['expires_on']->format('d M Y') : null,
        ]))).'</li>')->implode('');

        return new HtmlString('<ul class="space-y-1">'.$items.'</ul>');
    }

    /** HROne parity RQ-4: "Who else is away" for the chosen dates (TeamAway: the leave calendar's rules). */
    public static function renderTeamAway(Employee $employee, mixed $from, mixed $to): HtmlString
    {
        $rows = app(TeamAway::class)->for(auth()->user(), $employee, $from, $to);
        if ($rows === []) {
            return new HtmlString('<p class="pos-body-sm pos-muted">Nobody else in the team is away on these dates.</p>');
        }
        $items = collect($rows)->map(fn (array $r) => '<li class="pos-body-sm">'.e(implode(' · ', array_filter([
            $r['name'], $r['label'],
            $r['from']->isSameDay($r['to']) ? $r['from']->format('D, d M') : $r['from']->format('d M').' – '.$r['to']->format('d M'),
            $r['pending'] ? 'waiting for approval' : null,
        ]))).'</li>')->implode('');

        return new HtmlString('<ul class="space-y-1">'.$items.'</ul>');
    }

    private static function typeLabel(Employee $employee, LeaveType $type): string
    {
        if ($type->category === 'unpaid') {
            return "{$type->name} ({$type->code})";
        }

        $balance = app(LeaveBalances::class)->balance($employee, $type, app(LeaveYear::class)->periodFor(now()));

        return sprintf('%s (%s) · %.1f available', $type->name, $type->code, $balance->available());
    }

    private static function run(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }
}
