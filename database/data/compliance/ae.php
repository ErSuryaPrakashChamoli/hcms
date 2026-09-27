<?php

/*
 | UAE pack (illustrative): no personal income tax; GPSSA pension applies to UAE nationals only and is
 | modelled as a generic social-security rule the company profile can switch on. Verify before production.
 */
// Every entry defaults to verification_status = 'illustrative' (see ComplianceRules::sync). Set
// 'verification_status' => 'verified' and 'verified_at' only after checking the official source.
return [
    ['code' => 'SS', 'state' => null, 'name' => 'GPSSA pension (UAE nationals, private sector)', 'version' => 1, 'effective_from' => '2023-10-31',
        'source' => 'Federal Decree-Law No. 57 of 2023', 'parameters' => ['employee_rate' => 0.11, 'employer_rate' => 0.15, 'wage_ceiling' => 70000, 'wage_floor' => 3000, 'round' => 'nearest']],
];
