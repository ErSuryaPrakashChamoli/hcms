<?php

namespace App\Domain\Experience\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity WO-1: the outcome of one record of a bulk run (done, refused with the reason, failed). Derived rows: what
 * the record's owning service changed is audited on the record itself (under the run's audit operation id), and the run
 * closes with a BULK_OPERATION summary, so these rows are not audited again (documented exception in the architecture
 * test). Unique per run and record: a retried job skips the records it already reached.
 */
#[Fillable(['tenant_id', 'bulk_run_id', 'record_id', 'label', 'outcome', 'message'])]
class BulkRunItem extends Model
{
    use BelongsToTenant;

    public const OUTCOMES = ['done' => 'Done', 'refused' => 'Refused', 'failed' => 'Failed'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BulkRun::class, 'bulk_run_id');
    }
}
