<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\Pages\Concerns\ConfirmsPayPassword;
use App\Filament\Support\Pages\PeopleViewRecord;

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
