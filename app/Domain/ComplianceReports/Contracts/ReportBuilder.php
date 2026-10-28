<?php

namespace App\Domain\ComplianceReports\Contracts;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;

/**
 * Produces one compliance report for its context (establishment, state or legal entity, and exactly one period).
 * Builders only read: payroll from finalized runs, attendance and leave from their outputs, people data within the
 * generating person's access scope. Statutory parameters come from versioned compliance rules, never from PHP.
 */
interface ReportBuilder
{
    /** @return list<ReportSheet> one or more sheets (e.g. the employee rows and a challan summary) */
    public function build(ReportContext $context): array;
}
