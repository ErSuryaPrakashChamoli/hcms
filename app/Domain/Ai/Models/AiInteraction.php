<?php

namespace App\Domain\Ai\Models;

use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Governance log (§95): every question, the grounded answer, its sources, the provider used, and user feedback.
 * Phase 14: `ai_generated` marks language-model text; `data_policy` records what the AI data boundary did
 * (policy, removed / redacted counts, whether a provider was called). Prohibited values are never stored.
 */
#[Fillable(['tenant_id', 'user_id', 'assistant', 'question', 'answer', 'sources', 'actions', 'intent', 'provider', 'model', 'input_tokens', 'output_tokens', 'latency_ms', 'feedback', 'feedback_note', 'is_inference', 'ai_generated', 'data_policy'])]
class AiInteraction extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['sources' => 'array', 'actions' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'latency_ms' => 'integer', 'is_inference' => 'boolean', 'ai_generated' => 'boolean', 'data_policy' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * UX.19: the suggested actions the signed-in person may still open. Stored suggestions are checked again when shown,
     * because access can change after an answer was given (the screen itself still decides when opened).
     *
     * @return list<array<string, mixed>>
     */
    public function openActions(): array
    {
        return array_values(array_filter($this->actions ?? [], fn ($a) => filled($a['url'] ?? null) && app(ScreenAccess::class)->allows((string) $a['url'])));
    }
}
