<?php

namespace App\Filament\Support;

use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Part O/P/T: the statutory return lifecycle as Filament actions. Every step goes through
 * StatutoryReturns, which enforces permissions and separation of duties; approval, submission,
 * cancellation and revision ask for confirmation and a reason where the step records one.
 */
final class StatutoryReturnActions
{
    /** @return list<Action> */
    public static function forReturn(): array
    {
        $service = fn () => app(StatutoryReturns::class);

        return [
            Action::make('validateReturn')->label('Validate & reconcile')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (StatutoryReturn $record) => in_array($record->status, [StatutoryReturn::CALCULATED, StatutoryReturn::VALIDATED, StatutoryReturn::RECONCILIATION_REQUIRED], true) && $record->acknowledged_at === null && self::user()->hasPermission('compliance.returns.generate'))
                ->action(fn (StatutoryReturn $record) => self::run(fn () => $service()->validate($record, self::user()), 'Validated')),
            Action::make('approveReturn')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')->requiresConfirmation()
                ->modalDescription('Approval freezes the return and captures its snapshots. You cannot approve a return you generated.')
                ->visible(fn (StatutoryReturn $record) => $record->status === StatutoryReturn::VALIDATED && self::user()->hasPermission('compliance.returns.approve'))
                ->schema([Textarea::make('reason')->label('Approval note')->rows(2)])
                ->action(fn (StatutoryReturn $record, array $data) => self::run(fn () => $service()->approve($record, self::user(), $data['reason'] ?? null), 'Approved')),
            Action::make('exportReturn')->label('Export file')->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (StatutoryReturn $record) => in_array($record->status, [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED], true) && self::user()->hasPermission('compliance.returns.export'))
                ->action(function (StatutoryReturn $record) use ($service) {
                    try {
                        $record = $service()->export($record, self::user());
                        $content = $service()->exportContent($record, self::user());
                        Notification::make()->success()->title('Exported — not filed')->body('Upload it on the portal, then record the submission with the portal reference.')->send();

                        return response()->streamDownload(fn () => print ($content), $record->export_filename, ['Content-Type' => 'text/plain']);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('recordPortalValidation')->label('Record portal validation')->icon(Heroicon::OutlinedShieldCheck)
                ->modalDescription('Record the result of the authority\'s own validation of this file (portal upload check or validation utility). Accepted by the portal is not the same as filed.')
                ->visible(fn (StatutoryReturn $record) => $record->status === StatutoryReturn::EXPORTED && self::user()->hasPermission('compliance.returns.file'))
                ->schema([
                    Select::make('result')->options(['accepted' => 'Accepted', 'rejected' => 'Rejected'])->required(),
                    TextInput::make('reference')->label('Validation reference')->required()->maxLength(128),
                    DateTimePicker::make('validated_at')->required()->default(now())->maxDate(now()),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (StatutoryReturn $record, array $data) => self::run(fn () => $service()->recordPortalValidation($record, self::user(), $data['result'], $data['reference'], $data['validated_at'], $data['notes'] ?? null), 'Portal validation recorded')),
            Action::make('recordSubmission')->label('Record submission')->icon(Heroicon::OutlinedPaperAirplane)->requiresConfirmation()
                ->modalDescription('Record only a filing that has actually happened on the portal. You cannot record it if you generated or approved the return.')
                ->visible(fn (StatutoryReturn $record) => $record->status === StatutoryReturn::EXPORTED && self::user()->hasPermission('compliance.returns.file'))
                ->schema([
                    TextInput::make('external_reference')->label('Portal reference (e.g. TRRN / token / ack no.)')->required()->maxLength(128),
                    DateTimePicker::make('submitted_at')->label('Filed at')->required()->default(now())->maxDate(now()),
                    Textarea::make('reason')->label('Note')->rows(2),
                ])
                ->action(fn (StatutoryReturn $record, array $data) => self::run(fn () => $service()->recordSubmission($record, self::user(), $data['external_reference'], $data['submitted_at'], $data['reason'] ?? null), 'Submission recorded')),
            Action::make('recordAcknowledgement')->label('Record acknowledgement')->icon(Heroicon::OutlinedInboxArrowDown)
                ->visible(fn (StatutoryReturn $record) => $record->status === StatutoryReturn::SUBMITTED && self::user()->hasPermission('compliance.returns.file'))
                ->schema([
                    TextInput::make('reference')->label('Acknowledgement reference')->required()->maxLength(128),
                    DateTimePicker::make('acknowledged_at')->required()->default(now()),
                ])
                ->action(fn (StatutoryReturn $record, array $data) => self::run(fn () => $service()->recordAcknowledgement($record, self::user(), $data['reference'], $data['acknowledged_at']), 'Acknowledgement recorded')),
            Action::make('reconcileFiling')->label('Reconcile filing')->icon(Heroicon::OutlinedScale)
                ->visible(fn (StatutoryReturn $record) => in_array($record->status, [StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILIATION_REQUIRED], true) && $record->acknowledged_at !== null && self::user()->hasPermission('compliance.reconcile'))
                ->schema(fn (StatutoryReturn $record) => [KeyValue::make('acknowledged')->label('Amounts as acknowledged by the authority')->default(collect($record->totals)->only(config("peopleos.compliance.return_types.{$record->return_type}.filing_totals", []))->map(fn () => '')->all())->required()])
                ->action(fn (StatutoryReturn $record, array $data) => self::run(fn () => $service()->reconcileFiling($record, self::user(), array_map('floatval', array_filter($data['acknowledged'], 'strlen'))), 'Reconciliation recorded')),
            Action::make('cancelReturn')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')->requiresConfirmation()
                ->visible(fn (StatutoryReturn $record) => in_array($record->status, [StatutoryReturn::DRAFT, StatutoryReturn::CALCULATED, StatutoryReturn::VALIDATED, StatutoryReturn::RECONCILIATION_REQUIRED, StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED], true) && $record->submitted_at === null && self::user()->hasPermission('compliance.returns.generate'))
                ->schema([Textarea::make('reason')->required()->rows(2)])
                ->action(fn (StatutoryReturn $record, array $data) => self::run(fn () => $service()->cancel($record, self::user(), $data['reason']), 'Cancelled')),
        ];
    }

    public static function run(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    public static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
