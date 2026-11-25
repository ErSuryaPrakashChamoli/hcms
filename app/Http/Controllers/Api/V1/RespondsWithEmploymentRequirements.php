<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirementsException;
use App\Domain\Employment\Requirements\RequirementProblem;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Master relationships R2 over the API (POST /employees, POST /pre-employees), documented in docs/api/openapi.json:
 *
 * - A hire the employment data requirements refuse (a rule on Block) is 422 with code `employment_requirements` and a
 *   `requirements` list: {rule, field, mode, message} per failed rule. Nothing is written.
 * - A master that does not apply to the company is 422 with code `master_not_applicable`.
 * - Any other business refusal stays 422 `unprocessable` (ApiResponseContract).
 * - A hire with warnings succeeds and carries `meta.requirement_warnings` (the messages) and
 *   `meta.requirement_warning_details` ({rule, field, mode, message}); without warnings there is no `meta`.
 */
trait RespondsWithEmploymentRequirements
{
    /** @return array{meta?: array{requirement_warnings: list<string>, requirement_warning_details: list<array<string, mixed>>}} */
    protected function requirementWarningsMeta(Employee $employee): array
    {
        return $employee->requirementWarnings === [] ? [] : ['meta' => [
            'requirement_warnings' => $employee->requirementWarnings,
            'requirement_warning_details' => $employee->requirementProblems,
        ]];
    }

    protected function refusal(InvalidArgumentException|ApplicabilityRefusedException $e): JsonResponse
    {
        if ($e instanceof EmploymentRequirementsException) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'employment_requirements',
                'requirements' => array_map(fn (RequirementProblem $p) => $p->toArray(), $e->result->blocking())], 422);
        }

        return response()->json(['message' => $e->getMessage()] + ($e instanceof ApplicabilityRefusedException ? ['code' => 'master_not_applicable'] : []), 422);
    }
}
