<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewPayslip extends PeopleViewRecord
{
    protected static string $resource = PayslipResource::class;

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
