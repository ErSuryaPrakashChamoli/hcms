<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Leave\Services\LeaveYear;
use App\Support\Storage\StagedUpload;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
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
            Grid::make(2)->schema([
                DatePicker::make('from_date')->native(false)->required()->default(now())->live(),
                DatePicker::make('to_date')->native(false)->required()->default(now())->afterOrEqual('from_date')->live(),
                Select::make('from_session')->label('Start')->options(LeaveRequest::SESSIONS)->default('full')->live(),
                Select::make('to_session')->label('End')->options(LeaveRequest::SESSIONS)->default('full')->live()
                    ->visible(fn (Get $get) => $get('from_date') !== $get('to_date')),
            ]),
            // Experience Transformation §21: days and balance before → after, before submitting.
            BeforeAfterPreview::leave($employee),
            Textarea::make('reason')->required()->maxLength(255),
            FileUpload::make('document_upload')->label('Supporting document')->helperText('Optional. Saved to the employee\'s documents.')
                ->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(config('peopleos.documents.max_kb'))
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                ->live(),
            Select::make('document_id')->label('Or attach an existing document')->options(fn () => $employee()->documents()->pluck('title', 'id')->all())->placeholder('Optional')
                ->visible(fn (Get $get) => blank($get('document_upload')) && $employee()->documents()->exists()),
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
            $request = app(Leaves::class)->request($employee, $type, $data['from_date'], $data['to_date'], $data['reason'], $data['from_session'] ?? 'full', ($data['from_date'] === $data['to_date'] ? $data['from_session'] : $data['to_session']) ?? 'full', $document);
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
