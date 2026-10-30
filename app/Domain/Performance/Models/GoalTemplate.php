<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne setup parity HP-8e (Goal → Goal template): a set of KRAs with their KPIs that the system maps to an employee's
 * goal sheet (goal sheet policy, goal creation "system") or that a person picks when creating goals ("Use template").
 * Applicability (rule conditions; empty = everyone) is HROne's "Map template"; the feedback template is the form the
 * manager answers about the goal sheet at the manager review. A template goals were created from is made inactive,
 * never deleted.
 */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'applicability', 'feedback_template_id', 'status', 'sort_order', 'created_by'])]
class GoalTemplate extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            $template->code = strtoupper(trim((string) $template->code));
            if ($template->code === '' || trim((string) $template->name) === '') {
                throw new RuntimeException('A goal template needs a name and a code.');
            }
            if (! array_key_exists((string) $template->status, self::STATUSES)) {
                throw new RuntimeException('A goal template is active or inactive.');
            }
        });
        static::deleting(function (self $template): void {
            if (Goal::query()->where('goal_template_id', $template->id)->exists()) {
                throw new RuntimeException('Goals were created from this template; make it inactive instead.');
            }
        });
    }

    protected function casts(): array
    {
        return ['applicability' => 'array', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /** Every KRA and KPI of the template. */
    public function items(): HasMany
    {
        return $this->hasMany(GoalTemplateItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The KRAs (goals) of the template; each carries its KPIs (kpis). */
    public function goals(): HasMany
    {
        return $this->hasMany(GoalTemplateItem::class)->whereNull('parent_item_id')->orderBy('sort_order')->orderBy('id');
    }

    public function feedbackTemplate(): BelongsTo
    {
        return $this->belongsTo(FeedbackTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether the template's "Map template" conditions match the employee (no conditions = everyone). */
    public function appliesTo(Employee $employee): bool
    {
        if (empty($this->applicability)) {
            return true;
        }

        return app(RuleEngine::class)->matches($this->applicability, app(EmployeeRuleContext::class)->build($employee, today()));
    }
}
