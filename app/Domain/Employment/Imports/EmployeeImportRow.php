<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staged row: the mapped data, the validation outcome, the decided action and the result. Row data is staging, not
 * employee data. A row holding secrets (a bank details upload) keeps them encrypted in sealed_data, not in data.
 */
#[Fillable(['tenant_id', 'employee_import_id', 'row_number', 'data', 'sealed_data', 'action', 'status', 'employee_id', 'match', 'errors', 'result'])]
#[Hidden(['sealed_data'])]
class EmployeeImportRow extends Model
{
    use BelongsToTenant;

    protected $attributes = ['action' => 'pending', 'status' => 'pending'];

    protected function casts(): array
    {
        return ['data' => 'array', 'sealed_data' => 'encrypted:array', 'match' => 'array', 'errors' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(EmployeeImport::class, 'employee_import_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
