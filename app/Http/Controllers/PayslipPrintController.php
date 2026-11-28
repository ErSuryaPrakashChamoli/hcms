<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayslipDesigns;
use App\Filament\Resources\Payslips\PayslipResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A payslip in the organisation's payslip design (owner, 2026-11-27), to print or save as a PDF from the browser — or,
 * when its design is the organisation's Word layout, the filled Word file. Signed link → session checks → the payslip
 * as the payslip register would show it to this person (payroll within scope; an employee only their own, once
 * published) and its view policy → audited as a payslip access.
 */
class PayslipPrintController extends Controller
{
    public static function url(Payslip $payslip): string
    {
        return URL::temporarySignedRoute('payslips.print', now()->addMinutes(30), ['payslip' => $payslip->getKey()]);
    }

    public function __invoke(Request $request, int $payslip, PayslipDesigns $designs, AuditRecorder $audit): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        /** @var Payslip $record */
        $record = PayslipResource::getEloquentQuery()->whereKey($payslip)->firstOrFail();
        abort_unless($request->user()?->can('view', $record), 403);

        $settings = $designs->settings();
        $design = $designs->designFor($record, $settings);
        $audit->record(AuditAction::PayslipAccessed, 'payroll', $record, [], null, metadata: ['sensitive' => true, 'part' => 'print', 'design' => $design]);

        if ($design === PayslipDesigns::CUSTOM) {
            try {
                $file = $designs->word($record, $settings);
            } catch (RuntimeException $e) {
                // The Word layout went missing: the payslip still prints, in Classic.
                return response($designs->html($record, 'classic', $settings));
            }

            return response($file['content'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"']);
        }

        return response($designs->html($record, $design, $settings));
    }
}
