<?php

namespace App\Domain\Configuration\Applicability\Providers;

use App\Domain\Configuration\Applicability\ReferencesProvider;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Models\OnboardingTemplateItem;
use Illuminate\Database\Eloquent\Model;

/** What an onboarding template's checklist items point at: document types, forms and feedback templates. */
final class OnboardingTemplateReferences implements ReferencesProvider
{
    /** Item column => configuration type key. */
    public const COLUMNS = ['document_type_id' => 'document_type', 'form_id' => 'form', 'feedback_template_id' => 'feedback_template'];

    public function references(Model $record): array
    {
        /** @var OnboardingTemplate $record */
        $found = [];
        foreach ($record->items()->get() as $item) {
            foreach (self::COLUMNS as $column => $type) {
                if (filled($item->getAttribute($column))) {
                    $found[] = ['type' => $type, 'id' => (int) $item->getAttribute($column), 'label' => (string) $item->title, 'path' => "items.{$item->getKey()}.{$column}"];
                }
            }
        }

        return $found;
    }

    public function referencing(string $type, int $id): array
    {
        $column = array_search($type, self::COLUMNS, true);
        if ($column === false) {
            return [];
        }
        $templateIds = OnboardingTemplateItem::query()->where($column, $id)->distinct()->pluck('onboarding_template_id');

        return OnboardingTemplate::query()->whereKey($templateIds)->get()->all();
    }
}
