<?php

namespace App\Domain\Letters\Services;

use App\Domain\Letters\Models\LetterTemplate;

/** Starter letter templates (§40) for every tenant. Tenants edit freely. */
final class LetterDefaults
{
    public function seed(): void
    {
        $sign = "\n\nFor **{{ company.name }}**\n\nAuthorised Signatory";

        $templates = [
            ['code' => 'EXPERIENCE', 'type' => 'experience', 'name' => 'Experience letter', 'requires_approval' => true, 'subject' => 'Experience certificate – {{ employee.name }}',
                'body' => "**TO WHOMSOEVER IT MAY CONCERN**\n\nThis is to certify that **{{ employee.name }}** (Employee code {{ employee.code }}) was employed with {{ company.name }} from **{{ employee.joining_date }}** to **{{ employee.exit_date }}**, last holding the position of **{{ employee.designation }}** in the {{ employee.department }} department.\n\nDuring this period we found their conduct and performance satisfactory. We wish them success in their future endeavours.".$sign],
            ['code' => 'RELIEVING', 'type' => 'relieving', 'name' => 'Relieving letter', 'requires_approval' => true, 'subject' => 'Relieving letter – {{ employee.name }}',
                'body' => "Dear {{ employee.first_name }},\n\nThis is with reference to your separation from {{ company.name }}. You have been relieved from your duties as **{{ employee.designation }}** with effect from the close of business on **{{ employee.exit_date }}**.\n\nYour full and final settlement will be processed as per company policy. We thank you for your contribution and wish you the very best.".$sign],
            ['code' => 'SALARY_CERT', 'type' => 'salary_certificate', 'name' => 'Salary certificate', 'requires_approval' => true, 'subject' => 'Salary certificate – {{ employee.name }}',
                'body' => "**TO WHOMSOEVER IT MAY CONCERN**\n\nThis is to certify that **{{ employee.name }}** (Employee code {{ employee.code }}) is employed with {{ company.name }} as **{{ employee.designation }}** since **{{ employee.joining_date }}**. Their current annual cost to company is **INR {{ employee.ctc_annual }}** (monthly INR {{ employee.ctc_monthly }}).\n\nThis certificate is issued at the employee's request for the purpose stated by them.".$sign],
            ['code' => 'EMPLOYMENT_CERT', 'type' => 'employment_certificate', 'name' => 'Employment certificate', 'requires_approval' => false, 'subject' => 'Employment certificate – {{ employee.name }}',
                'body' => "**TO WHOMSOEVER IT MAY CONCERN**\n\nThis is to certify that **{{ employee.name }}** (Employee code {{ employee.code }}) is employed with {{ company.name }} as **{{ employee.designation }}** in the {{ employee.department }} department since **{{ employee.joining_date }}**.".$sign],
            ['code' => 'NOC', 'type' => 'noc', 'name' => 'No objection certificate', 'requires_approval' => true, 'subject' => 'No objection certificate – {{ employee.name }}',
                'body' => "**TO WHOMSOEVER IT MAY CONCERN**\n\n{{ company.name }} has no objection to **{{ employee.name }}** ({{ employee.designation }}, Employee code {{ employee.code }}) in respect of {{ purpose }}.".$sign],
            ['code' => 'APPOINTMENT', 'type' => 'appointment', 'name' => 'Appointment letter', 'requires_approval' => true, 'subject' => 'Appointment letter – {{ employee.name }}',
                'body' => "Dear {{ employee.first_name }},\n\nWe are pleased to appoint you as **{{ employee.designation }}** in the {{ employee.department }} department of {{ company.name }} with effect from **{{ employee.joining_date }}**, at an annual cost to company of INR {{ employee.ctc_annual }}.\n\nYour employment is governed by the company's policies as amended from time to time.".$sign],
            ['code' => 'CONFIRMATION', 'type' => 'confirmation', 'name' => 'Confirmation letter', 'requires_approval' => false, 'subject' => 'Confirmation of employment – {{ employee.name }}',
                'body' => "Dear {{ employee.first_name }},\n\nWe are pleased to confirm your employment with {{ company.name }} as **{{ employee.designation }}** with effect from **{{ confirmation_date }}**. Congratulations.".$sign],
            ['code' => 'WARNING', 'type' => 'warning', 'name' => 'Warning letter', 'requires_approval' => true, 'subject' => 'Warning letter – {{ employee.name }}',
                'body' => "Dear {{ employee.first_name }},\n\nThis letter serves as a formal warning regarding {{ matter }}. You are advised to correct this immediately; further instances may lead to disciplinary action as per company policy.".$sign],
            ['code' => 'APPRECIATION', 'type' => 'appreciation', 'name' => 'Appreciation letter', 'requires_approval' => false, 'subject' => 'Letter of appreciation – {{ employee.name }}',
                'body' => "Dear {{ employee.first_name }},\n\nWe would like to recognise and thank you for {{ achievement }}. Your contribution is valued by everyone at {{ company.name }}.".$sign],
        ];

        foreach ($templates as $row) {
            LetterTemplate::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active']);
        }
    }
}
