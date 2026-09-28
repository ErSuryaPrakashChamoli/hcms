<?php

namespace App\Domain\Performance\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceTemplate;
use App\Domain\Performance\Models\PerformanceTemplateVersion;
use App\Domain\Performance\Models\RatingScale;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 versioned review templates. Publishing snapshots the rating scale and competencies as
 * they are now, so a later edit of the library never changes a review that used this version.
 */
final class PerformanceTemplates
{
    /**
     * @param  array{sections?: list<string>, rating_scale_id: int, competency_ids?: list<int>, workflow: list<array{key: string}>, weights?: array<string, float>, goal_rules?: array<string, mixed>}  $content
     */
    public function publish(PerformanceTemplate $template, array $content, ?User $actor = null): PerformanceTemplateVersion
    {
        $sections = array_values($content['sections'] ?? PerformanceTemplateVersion::SECTIONS);
        if (array_diff($sections, PerformanceTemplateVersion::SECTIONS) !== []) {
            throw new RuntimeException('Unknown template section(s): '.implode(', ', array_diff($sections, PerformanceTemplateVersion::SECTIONS)).'.');
        }
        $stages = array_column($content['workflow'] ?? [], 'key');
        if ($stages === [] || array_diff($stages, array_keys(config('peopleos.performance.stages'))) !== []) {
            throw new RuntimeException('The workflow needs known stages ('.implode(', ', array_keys(config('peopleos.performance.stages'))).').');
        }
        $weights = $content['weights'] ?? config('peopleos.performance.default_weights');
        foreach ($weights as $weight) {
            if ((float) $weight < 0) {
                throw new RuntimeException('Weights cannot be negative.');
            }
        }
        $required = $content['goal_rules']['required_total_weight'] ?? null;
        if ($required !== null && (float) $required <= 0) {
            throw new RuntimeException('The required goal weight total must be positive.');
        }

        $scale = RatingScale::query()->findOrFail($content['rating_scale_id']);
        $competencies = Competency::query()->whereIn('id', $content['competency_ids'] ?? [])->orderBy('id')->get();

        return DB::transaction(function () use ($template, $content, $sections, $scale, $competencies, $weights, $actor) {
            PerformanceTemplate::query()->whereKey($template->getKey())->lockForUpdate()->first();

            return PerformanceTemplateVersion::query()->create([
                'performance_template_id' => $template->getKey(),
                'version' => (int) PerformanceTemplateVersion::query()->where('performance_template_id', $template->getKey())->max('version') + 1,
                'sections' => $sections,
                'rating_scale_id' => $scale->getKey(),
                'rating_scale_snapshot' => ['id' => $scale->getKey(), 'code' => $scale->code, 'name' => $scale->name, 'levels' => $scale->levels],
                'competency_snapshot' => $competencies->map(fn (Competency $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'category' => $c->category, 'level' => $c->level, 'weight' => $c->weight, 'indicators' => $c->indicators])->all(),
                'workflow' => array_values($content['workflow']),
                'weights' => $weights,
                'goal_rules' => $content['goal_rules'] ?? [],
                'status' => 'published',
                'published_by' => $actor?->getKey() ?? auth()->id(),
                'published_at' => now(),
            ]);
        });
    }

    /**
     * The version a cycle pins at launch: its chosen template version, or an implicit version built
     * from the cycle's own configuration (so cycles without a template are pinned too).
     */
    public function pinFor(PerformanceCycle $cycle, ?User $actor = null): PerformanceTemplateVersion
    {
        if ($cycle->performance_template_version_id) {
            return PerformanceTemplateVersion::query()->findOrFail($cycle->performance_template_version_id);
        }

        $template = PerformanceTemplate::query()->firstOrCreate(['code' => 'CYCLE-'.$cycle->code], ['name' => "{$cycle->name} (cycle configuration)", 'description' => 'Pinned automatically when the cycle launched.']);

        return $this->publish($template, [
            'rating_scale_id' => $cycle->rating_scale_id,
            'competency_ids' => $cycle->competency_ids ?? [],
            'workflow' => $cycle->stages ?? [],
            'weights' => $cycle->weights ?: config('peopleos.performance.default_weights'),
            'goal_rules' => ['required_total_weight' => $cycle->setting('required_goal_weight')],
        ], $actor);
    }
}
