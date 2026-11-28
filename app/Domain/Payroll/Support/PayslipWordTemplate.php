<?php

namespace App\Domain\Payroll\Support;

use ZipArchive;

/**
 * The organisation's own payslip layout as a Word file (owner, 2026-11-27): the {{ variables }} it may use, and a starter
 * file to edit in Word. A table variable ({{ payslip.earnings }} …) on a line of its own becomes the table, as the salary
 * annexure does in letters (DocxLetter).
 */
final class PayslipWordTemplate
{
    /** variable => what it prints */
    public const VARIABLES = [
        'company.name' => 'Company (legal name)', 'company.code' => 'Company code', 'company.place' => 'Company city and state',
        'payslip.title' => 'Payslip title (Payslip designs)', 'payslip.number' => 'Payslip number', 'payslip.month' => 'Pay month, e.g. October 2026',
        'payslip.period' => 'Pay period dates', 'payslip.footer' => 'Footer text (Payslip designs)',
        'payslip.earnings' => 'Table: earnings, this month and year to date (on a line of its own)',
        'payslip.deductions' => 'Table: deductions, this month and year to date (on a line of its own)',
        'payslip.employer' => 'Table: employer contributions (on a line of its own)',
        'employee.name' => 'Employee name', 'employee.code' => 'Employee code', 'employee.designation' => 'Designation', 'employee.department' => 'Department',
        'employee.joining_date' => 'Date of joining', 'employee.pan' => 'PAN (masked)', 'employee.uan' => 'UAN (masked)', 'employee.esic' => 'ESI number (masked)',
        'employee.bank_name' => 'Bank', 'employee.bank_account' => 'Account number (masked)',
        'days.in_month' => 'Days in the pay month', 'days.paid' => 'Days paid', 'days.lop' => 'Loss-of-pay days',
        'totals.gross' => 'Gross pay', 'totals.earnings' => 'Total earnings', 'totals.deductions' => 'Total deductions', 'totals.net' => 'Net pay',
        'totals.employer' => 'Employer contributions in all', 'net.words' => 'Net pay in words',
    ];

    /** A starter payslip in Word that uses the main variables and the tables, to edit and upload again. */
    public static function sample(): string
    {
        $text = fn (string $value, bool $bold = false, int $size = 0) => '<w:r><w:rPr>'.($bold ? '<w:b/>' : '').($size ? '<w:sz w:val="'.$size.'"/>' : '').'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</w:t></w:r>';
        $p = fn (string $value, bool $bold = false, ?string $align = null, int $size = 0) => '<w:p><w:pPr><w:spacing w:after="80"/>'.($align ? '<w:jc w:val="'.$align.'"/>' : '').'</w:pPr>'.$text($value, $bold, $size).'</w:p>';
        $cell = fn (string $label, string $value) => '<w:tc><w:tcPr><w:tcW w:w="2350" w:type="dxa"/></w:tcPr><w:p>'.$text($label, true).'</w:p></w:tc>'
            .'<w:tc><w:tcPr><w:tcW w:w="2350" w:type="dxa"/></w:tcPr><w:p>'.$text($value).'</w:p></w:tc>';
        $row = fn (array $a, array $b) => '<w:tr>'.$cell(...$a).$cell(...$b).'</w:tr>';
        $borders = implode('', array_map(fn (string $side) => '<w:'.$side.' w:val="single" w:sz="4" w:space="0" w:color="BBBBBB"/>', ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']));
        $details = '<w:tbl><w:tblPr><w:tblW w:w="9400" w:type="dxa"/><w:tblBorders>'.$borders.'</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="2350"/><w:gridCol w:w="2350"/><w:gridCol w:w="2350"/><w:gridCol w:w="2350"/></w:tblGrid>'
            .$row(['Employee', '{{ employee.name }}'], ['Code', '{{ employee.code }}'])
            .$row(['Designation', '{{ employee.designation }}'], ['Department', '{{ employee.department }}'])
            .$row(['Date of joining', '{{ employee.joining_date }}'], ['PAN', '{{ employee.pan }}'])
            .$row(['UAN', '{{ employee.uan }}'], ['Bank account', '{{ employee.bank_name }} {{ employee.bank_account }}'])
            .$row(['Days paid', '{{ days.paid }} of {{ days.in_month }}'], ['Loss of pay', '{{ days.lop }} days'])
            .'</w:tbl>';
        $body = implode('', [
            $p('{{ company.name }}', true, 'center', 32),
            $p('{{ company.place }}', false, 'center'),
            $p('{{ payslip.title }} for {{ payslip.month }}', true, 'center', 26),
            $p('Payslip {{ payslip.number }} · {{ payslip.period }}', false, 'center'),
            $details,
            $p(''),
            $p('{{ payslip.earnings }}'),
            $p('{{ payslip.deductions }}'),
            $p('Net pay: {{ totals.net }}', true, null, 28),
            $p('{{ net.words }}'),
            $p(''),
            $p('{{ payslip.footer }}', false, 'center', 16),
        ]);
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'payslip');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
