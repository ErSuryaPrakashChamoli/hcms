<?php

namespace App\Filament\Support;

use App\Domain\Compliance\Services\RecordVerifications;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;

/** Phase 6.3: "submit for verification" and "verify" actions for legal entities and establishments. */
final class RecordVerificationActions
{
    public static function column(): TextColumn
    {
        return TextColumn::make('verification_status')->label('Verification')->badge()
            ->color(fn (?string $state) => match ($state) {
                'verified' => 'success', 'review' => 'warning', default => 'danger'
            });
    }

    /** @return list<Action> */
    public static function make(string $permissionPrefix): array
    {
        return [
            Action::make('submitVerification')->label('Submit for verification')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                ->visible(fn (LegalEntity|Establishment $record) => $record->verification_status !== 'verified' && StatutoryReturnActions::user()->hasPermission("{$permissionPrefix}.update"))
                ->schema([
                    TextInput::make('reference')->label('Certificate / document reference')->required()->maxLength(255),
                    FileUpload::make('file')->label('Copy of the certificate (optional)')->disk('local')->directory('compliance-evidence/uploads'),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (LegalEntity|Establishment $record, array $data) => StatutoryReturnActions::run(function () use ($record, $data) {
                    $file = $data['file'] ?? null;
                    app(RecordVerifications::class)->submit($record, StatutoryReturnActions::user(), $data['reference'], $file ? (string) Storage::disk('local')->get($file) : null, $file ? basename($file) : null, $data['notes'] ?? null);
                    if ($file) {
                        Storage::disk('local')->delete($file);
                    }
                }, 'Submitted for verification')),
            Action::make('verifyRecord')->label('Verify')->icon(Heroicon::OutlinedCheckBadge)->color('success')->requiresConfirmation()
                ->modalDescription('Confirm the legal name, identifiers, state and address match the certificate. You cannot verify details you submitted.')
                ->visible(fn (LegalEntity|Establishment $record) => $record->verification_status === 'review' && StatutoryReturnActions::user()->hasPermission("{$permissionPrefix}.verify"))
                ->schema([Textarea::make('notes')->label('Verification notes')->required()->rows(2)])
                ->action(fn (LegalEntity|Establishment $record, array $data) => StatutoryReturnActions::run(fn () => app(RecordVerifications::class)->verify($record, StatutoryReturnActions::user(), $data['notes']), 'Verified')),
        ];
    }
}
