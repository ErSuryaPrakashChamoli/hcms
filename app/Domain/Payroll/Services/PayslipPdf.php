<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\Payslip;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use RuntimeException;

/**
 * The payslip as a PDF file (owner, 2026-11-28: "make it password protected: the first four letters of the name and the
 * last four of the PAN"). The same page the browser prints, in the design the organisation chose (PayslipDesigns — the
 * employee never chooses), made on the server by mPDF and locked with 128-bit encryption (RC4, what mPDF offers; every PDF reader opens it): it opens with the password, printing is
 * allowed, and changing it needs an owner password nobody is given. `payroll.payslip.pdf_password` (default on) turns
 * the password off. The organisation's own Word layout has no PDF: those payslips download as Word files.
 */
final class PayslipPdf
{
    public function __construct(private readonly PayslipDesigns $designs, private readonly SettingsRepository $settings) {}

    public function protects(): bool
    {
        return (bool) $this->settings->get('payroll.payslip.pdf_password', true);
    }

    /**
     * The password: the first four letters of the name and the last four characters of the PAN, in capitals — "RAHU234F"
     * for Rahul Sharma with PAN ABCDE1234F. With no PAN on file, the last four characters of the employee code. The
     * payslip keeps the PAN masked to its last four characters, which is all the password needs.
     */
    public static function password(Payslip $payslip): string
    {
        $letters = mb_strtoupper(mb_substr((string) preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $payslip->get('employee.name'))), 0, 4));
        $pan = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $payslip->get('employee.pan'));
        $tail = $pan !== '' ? $pan : (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $payslip->get('employee.code'));

        return $letters.mb_strtoupper(mb_substr($tail, -4));
    }

    /** How the password is made, for the employee (My payslips) and the design page. */
    public static function passwordRule(): string
    {
        return 'Payslip PDFs open with a password: the first four letters of your name in capitals, then the last four characters of your PAN — for example RAHU234F for Rahul with a PAN ending in 234F. With no PAN on file, the last four characters of your employee code.';
    }

    /**
     * @return array{content: string, name: string, mime: string, protected: bool}
     */
    public function render(Payslip $payslip, ?array $settings = null): array
    {
        $settings ??= $this->designs->settings();
        $design = $this->designs->designFor($payslip, $settings);
        if ($design === PayslipDesigns::CUSTOM) {
            throw new RuntimeException('Payslips in the organisation\'s Word layout download as Word files, not PDFs.');
        }
        $html = $this->designs->html($payslip, $design, $settings, pdf: true);
        $temp = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temp);

        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10,
            'default_font' => 'dejavusans', 'tempDir' => $temp]);
        $pdf->SetTitle(trim($payslip->get('company.name').' · '.($settings['title'] ?? 'Payslip').' '.$payslip->get('period.label')));
        $pdf->SetAuthor((string) $payslip->get('company.name'));
        $pdf->SetCreator('PeopleOS');
        if ($protected = $this->protects()) {
            $pdf->SetProtection(['print', 'print-highres'], self::password($payslip), Str::random(32), 128);
        }
        $pdf->WriteHTML($html);

        return ['content' => $pdf->Output('', 'S'), 'name' => Str::slug($payslip->number).'.pdf', 'mime' => 'application/pdf', 'protected' => $protected];
    }
}
