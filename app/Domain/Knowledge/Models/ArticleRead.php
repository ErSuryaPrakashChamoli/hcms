<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read / acknowledgement tracking per employee per version. */
#[Fillable(['tenant_id', 'article_id', 'employee_id', 'version', 'read_at', 'acknowledged_at'])]
class ArticleRead extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['version' => 'integer', 'read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
