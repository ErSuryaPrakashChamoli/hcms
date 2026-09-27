<?php

/*
 | Country packs (§96): Country -> Jurisdiction -> Currency -> Tax framework -> Social security -> Labour rules -> Documents -> Localisation.
 | India is the complete pack (statutory engine "india", compliance rules in database/data/compliance/in.php). Other packs carry
 | metadata and use the generic statutory engine, which reads jurisdiction-specific compliance rules when a pack supplies them.
 */
return [
    'IN' => ['name' => 'India', 'currency' => 'INR', 'locale' => 'en-IN', 'timezone' => 'Asia/Kolkata', 'date_format' => 'd/m/Y', 'week_start' => 1, 'financial_year_start_month' => 4,
        'statutory_engine' => 'india', 'tax_framework' => 'Income-tax Act 1961 (s.192 TDS)', 'social_security' => ['EPF', 'ESI', 'PT', 'LWF', 'Gratuity'],
        'labour' => ['standard_weekly_hours' => 48, 'max_daily_hours' => 9, 'default_notice_days' => 30, 'minimum_leave_days' => 12, 'overtime_multiplier' => 2.0],
        'documents' => ['PAN', 'Aadhaar', 'UAN', 'Bank proof', 'Form 16'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'indian']],
    'AE' => ['name' => 'United Arab Emirates', 'currency' => 'AED', 'locale' => 'ar', 'timezone' => 'Asia/Dubai', 'date_format' => 'd/m/Y', 'week_start' => 1, 'financial_year_start_month' => 1,
        'statutory_engine' => 'generic', 'tax_framework' => 'No personal income tax', 'social_security' => ['GPSSA (UAE nationals only)'],
        'labour' => ['standard_weekly_hours' => 48, 'max_daily_hours' => 8, 'default_notice_days' => 30, 'minimum_leave_days' => 30, 'overtime_multiplier' => 1.25, 'end_of_service_gratuity' => true],
        'documents' => ['Emirates ID', 'Passport', 'Visa', 'Labour card', 'WPS bank details'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'standard']],
    'GB' => ['name' => 'United Kingdom', 'currency' => 'GBP', 'locale' => 'en-GB', 'timezone' => 'Europe/London', 'date_format' => 'd/m/Y', 'week_start' => 1, 'financial_year_start_month' => 4,
        'statutory_engine' => 'generic', 'tax_framework' => 'PAYE income tax', 'social_security' => ['National Insurance', 'Workplace pension'],
        'labour' => ['standard_weekly_hours' => 48, 'max_daily_hours' => 13, 'default_notice_days' => 30, 'minimum_leave_days' => 28, 'overtime_multiplier' => 1.0],
        'documents' => ['NI number', 'Right to work', 'P45/Starter checklist'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'standard']],
    'US' => ['name' => 'United States', 'currency' => 'USD', 'locale' => 'en-US', 'timezone' => 'America/New_York', 'date_format' => 'm/d/Y', 'week_start' => 0, 'financial_year_start_month' => 1,
        'statutory_engine' => 'generic', 'tax_framework' => 'Federal and state withholding', 'social_security' => ['FICA (Social Security, Medicare)', 'FUTA/SUTA'],
        'labour' => ['standard_weekly_hours' => 40, 'max_daily_hours' => null, 'default_notice_days' => 14, 'minimum_leave_days' => 0, 'overtime_multiplier' => 1.5],
        'documents' => ['SSN', 'I-9', 'W-4'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'standard']],
    'SG' => ['name' => 'Singapore', 'currency' => 'SGD', 'locale' => 'en', 'timezone' => 'Asia/Singapore', 'date_format' => 'd/m/Y', 'week_start' => 1, 'financial_year_start_month' => 1,
        'statutory_engine' => 'generic', 'tax_framework' => 'IRAS income tax (annual)', 'social_security' => ['CPF', 'SDL', 'SHG funds'],
        'labour' => ['standard_weekly_hours' => 44, 'max_daily_hours' => 12, 'default_notice_days' => 30, 'minimum_leave_days' => 7, 'overtime_multiplier' => 1.5],
        'documents' => ['NRIC/FIN', 'Work pass'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'standard']],
    'AU' => ['name' => 'Australia', 'currency' => 'AUD', 'locale' => 'en', 'timezone' => 'Australia/Sydney', 'date_format' => 'd/m/Y', 'week_start' => 1, 'financial_year_start_month' => 7,
        'statutory_engine' => 'generic', 'tax_framework' => 'PAYG withholding', 'social_security' => ['Superannuation guarantee'],
        'labour' => ['standard_weekly_hours' => 38, 'max_daily_hours' => null, 'default_notice_days' => 14, 'minimum_leave_days' => 20, 'overtime_multiplier' => 1.5],
        'documents' => ['TFN', 'Superannuation choice'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'standard']],
    'CA' => ['name' => 'Canada', 'currency' => 'CAD', 'locale' => 'en', 'timezone' => 'America/Toronto', 'date_format' => 'Y-m-d', 'week_start' => 0, 'financial_year_start_month' => 1,
        'statutory_engine' => 'generic', 'tax_framework' => 'Federal and provincial income tax', 'social_security' => ['CPP', 'EI'],
        'labour' => ['standard_weekly_hours' => 40, 'max_daily_hours' => null, 'default_notice_days' => 14, 'minimum_leave_days' => 10, 'overtime_multiplier' => 1.5],
        'documents' => ['SIN', 'TD1'], 'number_format' => ['decimals' => 2, 'thousands' => ',', 'grouping' => 'standard']],
];
