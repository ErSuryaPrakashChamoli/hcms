<?php

namespace App\Domain\Skills\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Models\SkillScaleVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Phase 8: versioned skill proficiency scales. Levels are configured, never hard-coded in services. */
final class SkillScales
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  list<array{value: int|float, label: string, description?: ?string, indicator?: ?string}>  $levels */
    public function publish(SkillScale $scale, array $levels, ?User $actor = null): SkillScaleVersion
    {
        $levels = array_values($levels);
        if (count($levels) < 2) {
            throw new RuntimeException('A skill scale needs at least two levels.');
        }
        $values = [];
        foreach ($levels as $level) {
            if (! is_numeric($level['value'] ?? null) || blank($level['label'] ?? null)) {
                throw new RuntimeException('Every level needs a numeric value and a label.');
            }
            $values[] = (float) $level['value'];
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new RuntimeException('Level values must be unique.');
        }
        usort($levels, fn ($a, $b) => (float) $a['value'] <=> (float) $b['value']);

        return DB::transaction(function () use ($scale, $levels, $actor) {
            SkillScale::query()->whereKey($scale->id)->lockForUpdate()->first();
            $version = SkillScaleVersion::query()->create([
                'skill_scale_id' => $scale->id,
                'version' => (int) SkillScaleVersion::query()->where('skill_scale_id', $scale->id)->max('version') + 1,
                'levels' => array_map(fn ($l) => ['value' => (float) $l['value'], 'label' => $l['label'], 'description' => $l['description'] ?? null, 'indicator' => $l['indicator'] ?? null], $levels),
                'published_by' => $actor?->id ?? auth()->id(),
                'published_at' => now(),
            ]);
            $scale->update(['current_version_id' => $version->id]);
            $this->audit->record(AuditAction::Create, 'skills', $version, [], null, actor: $actor, metadata: ['event' => 'skill_scale_published', 'scale' => $scale->code]);

            return $version;
        });
    }

    /** The default scale's current version (seeded per tenant from config on first use). */
    public function defaultVersion(): SkillScaleVersion
    {
        $config = config('peopleos.skills.default_scale');
        $scale = SkillScale::query()->firstOrCreate(['code' => $config['code']], ['name' => $config['name']]);

        return $scale->current_version_id ? SkillScaleVersion::query()->findOrFail($scale->current_version_id) : $this->publish($scale, $config['levels']);
    }

    public function versionForSkill(int $skillId): SkillScaleVersion
    {
        $scaleId = Skill::query()->whereKey($skillId)->value('skill_scale_id');
        if ($scaleId === null) {
            return $this->defaultVersion();
        }
        $scale = SkillScale::query()->findOrFail($scaleId);
        if ($scale->current_version_id === null) {
            throw new RuntimeException("Skill scale {$scale->code} has no published levels.");
        }

        return SkillScaleVersion::query()->findOrFail($scale->current_version_id);
    }
}
