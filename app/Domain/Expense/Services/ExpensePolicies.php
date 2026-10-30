<?php

namespace App\Domain\Expense\Services;

use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Support\Answers;
use App\Domain\Expense\Support\ExpenseAdvanceRules;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * HROne setup parity EX-B: reads an employee's expense policy (type `expense`: the categories they may claim, with
 * their overrides) and expense advance policy (type `expense_advance`) on a date, through the Policy Engine.
 */
final class ExpensePolicies
{
    public function __construct(private readonly PolicyResolver $policies, private readonly SettingsRepository $settings) {}

    public function expense(Employee $employee, CarbonInterface|string $date): ?PolicyVersion
    {
        return $this->policies->resolve('expense', $employee, $date);
    }

    /**
     * The policy's row for a category (its overrides), or null when the policy does not offer it. The first row wins
     * when a category was listed twice.
     *
     * @return array<string, mixed>|null
     */
    public function categoryRow(PolicyVersion $version, ExpenseCategory $category): ?array
    {
        foreach ((array) $version->setting('categories', []) as $row) {
            if (is_array($row) && filled($row['category'] ?? null) && (int) $row['category'] === (int) $category->id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The categories the employee can claim on the date: the active categories of their expense policy, leaving out
     * exceptional-only ones while exceptional expenses are switched off.
     *
     * @return Collection<int, ExpenseCategory>
     */
    public function categoriesFor(Employee $employee, CarbonInterface|string $date): Collection
    {
        $version = $this->expense($employee, $date);
        if ($version === null) {
            return collect();
        }
        $exceptional = (bool) $this->settings->get('expense.exceptional.enabled', false);
        $rows = collect((array) $version->setting('categories', []))->filter(fn ($row) => is_array($row) && filled($row['category'] ?? null))
            ->filter(fn (array $row) => $exceptional || ! Answers::yes($row['exceptional_only'] ?? false))
            ->keyBy(fn (array $row) => (int) $row['category']);

        return ExpenseCategory::query()->with('group')->whereKey($rows->keys()->all())->where('status', ActiveStatus::Active)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public function advanceRules(Employee $employee, CarbonInterface|string $date): ExpenseAdvanceRules
    {
        return ExpenseAdvanceRules::from($this->policies->resolve('expense_advance', $employee, $date));
    }
}
