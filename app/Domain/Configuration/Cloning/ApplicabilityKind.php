<?php

namespace App\Domain\Configuration\Cloning;

/**
 * How a configuration type says whom it applies to, which decides how a clone rewrites it for its destination
 * (ApplicabilityRewriter): rule conditions (company_id among RuleEngine conditions), a company_id column (null = every
 * company), a list of company ids inside JSON, or nothing at all.
 */
enum ApplicabilityKind: string
{
    case RuleConditions = 'rule_conditions';
    case CompanyColumn = 'company_column';
    case CompanyIdList = 'company_id_list';
    case None = 'none';
}
