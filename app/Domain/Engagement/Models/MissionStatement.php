<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity HP-9b (Engage → Mission statement): the organisation's mission, vision, purpose and core values
 * ([{title, description, icon}]), for everyone or for the employees its audience rule conditions match (empty =
 * everyone), from effective_from. Shown on Home when show_on_home (MissionStatements::forEmployee picks one).
 */
#[Fillable(['tenant_id', 'code', 'name', 'mission', 'vision', 'purpose', 'core_values', 'show_on_home', 'audience', 'status', 'effective_from'])]
class MissionStatement extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'show_on_home' => true];

    protected static function booted(): void
    {
        static::saving(function (self $statement): void {
            $statement->code = strtoupper(trim((string) $statement->code));
            if (! array_key_exists((string) $statement->status, self::STATUSES)) {
                throw new RuntimeException("Unknown status [{$statement->status}].");
            }
            // Core values keep their order and drop empty rows.
            $statement->core_values = array_values(array_filter((array) ($statement->core_values ?? []), fn ($v) => is_array($v) && filled($v['title'] ?? null)));
            $statement->audience = array_values((array) ($statement->audience ?? []));
            if (blank($statement->mission) && blank($statement->vision) && blank($statement->purpose) && $statement->core_values === []) {
                throw new RuntimeException('Write the mission, vision, purpose or at least one core value.');
            }
        });
    }

    protected function casts(): array
    {
        return ['core_values' => 'array', 'audience' => 'array', 'show_on_home' => 'boolean', 'effective_from' => 'date'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Mission statement {$this->name}";
    }
}
