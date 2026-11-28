<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Payroll\Services\PayslipDesigns;
use App\Domain\Payroll\Services\PayslipPdf;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\Pages\Concerns\ConfirmsPayPassword;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\PayslipPrintController;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ViewPayslip extends PeopleViewRecord
{
    use ConfirmsPayPassword;

    protected static string $resource = PayslipResource::class;

    protected static function payPasswordScope(): string
    {
        return 'payslips';
    }

    protected function payPasswordReturnUrl(): string
    {
        return PayslipResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * Owner, 2026-11-27/28: the payslip in the design the organisation chose — a password-protected PDF (or the filled
     * Word file of its own layout), and the same page to print.
     */
    protected function getHeaderActions(): array
    {
        $word = app(PayslipDesigns::class)->designFor($this->getRecord()) === PayslipDesigns::CUSTOM;

        return [
            ...parent::getHeaderActions(),
            Action::make('pdf')->label($word ? 'Download payslip (Word)' : 'Download PDF')->icon(Heroicon::OutlinedArrowDownTray)
                ->tooltip($word || ! app(PayslipPdf::class)->protects() ? null : 'Password: the first four letters of the name and the last four characters of the PAN')
                ->url(fn () => PayslipPdfController::url($this->getRecord())),
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->visible(! $word)
                ->url(fn () => PayslipPrintController::url($this->getRecord()), shouldOpenInNewTab: true),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $payslip = $this->getRecord();
        if ($payslip->viewed_at === null && $payslip->employee()->value('user_id') === auth()->id()) {
            $payslip->forceFill(['viewed_at' => now()])->saveQuietly();
        }
        app(AuditRecorder::class)->record(AuditAction::PayslipAccessed, 'payroll', $payslip, [], null, metadata: ['sensitive' => true]);
    }
}
