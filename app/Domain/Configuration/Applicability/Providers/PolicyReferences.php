<?php

namespace App\Domain\Configuration\Applicability\Providers;

use App\Domain\Configuration\Applicability\ReferencesProvider;
use App\Domain\Configuration\Cloning\Adapters\PolicyCloneAdapter;
use App\Domain\Configuration\Models\Policy;
use Illuminate\Database\Eloquent\Model;

/**
 * What a policy's settings point at (leave types, shifts, letter templates, checklists, …), read the way the policy clone
 * adapter classifies them (PolicyCloneAdapter::OPTION_SOURCES), mapped to configuration types.
 */
final class PolicyReferences implements ReferencesProvider
{
    /** Settings source => configuration type key. */
    public const TYPES = [
        'leave_types' => 'leave_type', 'shifts' => 'shift', 'competencies' => 'competency', 'ticket_categories' => 'ticket_category',
        'expense_categories' => 'expense_category', 'asset_categories' => 'asset_category', 'asset_subtypes' => 'asset_subtype',
        'asset_models' => 'asset_model', 'clearance_checklists' => 'clearance_checklist', 'letter_templates' => 'letter_template',
        'feedback_templates' => 'feedback_template', 'goal_templates' => 'goal_template', 'service_definitions' => 'service_definition',
    ];

    public function __construct(private readonly PolicyCloneAdapter $policies) {}

    public function references(Model $record): array
    {
        /** @var Policy $record */
        $found = [];
        foreach ($this->policies->references($this->policies->snapshot($record)) as $reference) {
            if (isset(self::TYPES[$reference->type]) && $reference->targetId !== null) {
                $found[] = ['type' => self::TYPES[$reference->type], 'id' => $reference->targetId, 'label' => $reference->label, 'path' => $reference->path];
            }
        }

        return $found;
    }

    public function referencing(string $type, int $id): array
    {
        if (! in_array($type, self::TYPES, true)) {
            return [];
        }

        return Policy::query()->orderBy('id')->get()
            ->filter(fn (Policy $policy) => collect($this->references($policy))->contains(fn (array $r) => $r['type'] === $type && $r['id'] === $id))
            ->values()->all();
    }
}
