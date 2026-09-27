<?php

namespace App\Domain\Ai\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Governance log (§95): every question, the grounded answer, its sources, the provider used, and user feedback. */
#[Fillable(['tenant_id', 'user_id', 'assistant', 'question', 'answer', 'sources', 'actions', 'intent', 'provider', 'model', 'input_tokens', 'output_tokens', 'latency_ms', 'feedback', 'feedback_note', 'is_inference'])]
class AiInteraction extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['sources' => 'array', 'actions' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'latency_ms' => 'integer', 'is_inference' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
