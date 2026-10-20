<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Leave\Services\LeaveYear;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
            Select::make('leave_type_id')
                ->label('Leave type')
                ->options(function () use ($employee) {
                    $emp = $employee();
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
            Select::make('document_id')->label('Supporting document')->options(fn () => $employee()->documents()->pluck('title', 'id')->all())->placeholder('Optional'),
        ];
    }

    public static function apply(Employee $employee, array $data): void
    {
        try {
            $type = LeaveType::query()->findOrFail($data['leave_type_id']);
            $document = isset($data['document_id']) ? EmployeeDocument::query()->find($data['document_id']) : null;
            $request = app(Leaves::class)->request($employee, $type, $data['from_date'], $data['to_date'], $data['reason'], $data['from_session'] ?? 'full', ($data['from_date'] === $data['to_date'] ? $data['from_session'] : $data['to_session']) ?? 'full', $document);
            Notification::make()->success()->title(sprintf('Leave requested: %.1f day(s)', (float) $request->days))->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Cannot apply')->body($e->getMessage())->persistent()->send();
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
