<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayslipDesigns;
use App\Domain\Payroll\Services\PayslipPdf;
use App\Filament\Resources\Payslips\PayslipResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payslip as a password-protected PDF in the organisation's design (owner, 2026-11-28) — or, when the organisation
 * prints payslips in its own Word layout, the filled Word file. Same checks as PayslipPrintController: signed link →
 * session checks → the payslip as the payslip register shows it to this person, and its view policy → audited.
 */
class PayslipPdfController extends Controller
{
    public static function url(Payslip $payslip): string
    {
        return URL::temporarySignedRoute('payslips.pdf', now()->addMinutes(30), ['payslip' => $payslip->getKey()]);
    }

    public function __invoke(Request $request, int $payslip, PayslipDesigns $designs, PayslipPdf $pdf, AuditRecorder $audit): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        /** @var Payslip $record */
        $record = PayslipResource::getEloquentQuery()->whereKey($payslip)->firstOrFail();
        abort_unless($request->user()?->can('view', $record), 403);

        $settings = $designs->settings();
        $word = $designs->designFor($record, $settings) === PayslipDesigns::CUSTOM;
        $file = $word ? $designs->word($record, $settings) + ['protected' => false] : $pdf->render($record, $settings);
        $audit->record(AuditAction::PayslipAccessed, 'payroll', $record, [], null, metadata: ['sensitive' => true, 'part' => $word ? 'word' : 'pdf', 'protected' => $file['protected']]);

        return response($file['content'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"', 'Cache-Control' => 'private, no-store']);
    }
}
