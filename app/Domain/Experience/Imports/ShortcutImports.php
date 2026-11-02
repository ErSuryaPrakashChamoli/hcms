<?php

namespace App\Domain\Experience\Imports;

use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\ExperiencePreference;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\ModuleCatalogue;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use RuntimeException;

/**
 * Data import DI-2 (Workforce → Shortcut): pin page shortcuts for employees — the area bar's favourites (the star beside
 * a page), which lead the bar of their area and have their own row under "All in …". A row names the employee and the
 * page as people see it in the menu, and pins it (or unpins it with Remove = yes) for the employee's sign-in through
 * ExperienceNavigation::toggleFavouriteModule, decided as that person: only pages they may open are pinned. A person keeps
 * at most ExperiencePreferences::MAX_FAVOURITE_MODULES favourites (the oldest drops off). Rows only pin or unpin, so the
 * upload has no "new or existing" choice. Needs user.update (it changes people's sign-in preferences).
 */
final class ShortcutImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'shortcuts';

    public const PERMISSION = 'user.update';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'page' => ['Page', true], 'remove' => ['Remove', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Shortcut upload';
    }

    protected function module(): string
    {
        return 'experience';
    }

    protected function entityType(): string
    {
        return ExperiencePreference::class;
    }

    protected function aliases(): array
    {
        return ['shortcut' => 'page', 'menu' => 'page', 'page_name' => 'page', 'menu_name' => 'page', 'screen' => 'page', 'unpin' => 'remove', 'delete' => 'remove'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with(['person', 'user'])->find($employeeId);
        $remove = $this->yesNo($r['remove'] ?? null, 'Remove', $errors) ?? false;
        if (trim((string) ($r['page'] ?? '')) === '') {
            $errors[] = 'Page is required.';
        }
        if ($employee !== null && $employee->user === null) {
            $errors[] = "{$employee->employee_code} has no sign-in to pin shortcuts for.";
        }
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $user = $employee->user;
        $context['modules'][$user->id] ??= $this->modulesOf($user);
        [$module, $problem] = $this->find($context['modules'][$user->id], (string) $r['page'], $employee);
        if ($module === null) {
            return ['action' => 'error', 'errors' => [$problem], 'employee_id' => $employeeId];
        }
        $key = $user->id.'|'.$module['key'];
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same page for the same employee is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        if (! ($this->uploader($import)?->hasPermission(self::PERMISSION) ?? false)) {
            return ['action' => 'error', 'errors' => ['This needs '.self::PERMISSION.'.'], 'employee_id' => $employeeId];
        }

        $pinned = in_array($module['key'], (array) app(ExperiencePreferences::class)->for($user)['favourite_modules'], true);
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code', 'page'], 'page' => $module['label']];
        if ($pinned !== $remove) {
            return ['action' => 'skip', 'match' => $match + ['reason' => $remove ? "{$module['label']} is not pinned." : "{$module['label']} is already pinned."], 'employee_id' => $employeeId];
        }

        return $remove
            ? ['action' => 'update', 'match' => $match + ['reason' => "Unpins {$module['label']}."], 'employee_id' => $employeeId]
            : ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        $employee->loadMissing('user');
        $person = $employee->user ?? throw new RuntimeException("{$employee->employee_code} has no sign-in to pin shortcuts for.");
        if (! $user->hasPermission(self::PERMISSION)) {
            throw new RuntimeException('This needs '.self::PERMISSION.'.');
        }
        $errors = [];
        $remove = $this->yesNo($r['remove'] ?? null, 'Remove', $errors) ?? false;
        [$module, $problem] = $this->find($this->modulesOf($person), (string) ($r['page'] ?? ''), $employee);
        if ($module === null) {
            throw new RuntimeException((string) $problem);
        }
        $pinned = in_array($module['key'], (array) app(ExperiencePreferences::class)->for($person)['favourite_modules'], true);
        if ($pinned === $remove) {
            $done = $this->asPerson($person, fn () => app(ExperienceNavigation::class)->toggleFavouriteModule($person, $module['key']));
            if (! $done) {
                throw new RuntimeException("{$employee->employee_code} may not open {$module['label']}.");
            }
        }

        return ($remove ? 'Unpinned ' : 'Pinned ').$module['label'].' for '.$employee->employee_code;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee whose sign-in gets the shortcut (they need a sign-in).', 'EMP0001'],
                ['Page', 'Yes', 'The page as it is named in the menu (e.g. "Leave requests"). When two areas have a page of that name, write the area first: "Leave › Reports".', 'Leave requests'],
                ['Remove', 'No', 'yes to unpin the page; blank or no to pin it.', ''],
            ],
            'notes' => [
                'A shortcut is the star beside a page in the area bar: pinned pages lead the bar of their area and have their own row under "All in …".',
                'Only pages the employee may open are pinned: each row is checked with the employee\'s own access.',
                'A person keeps at most '.ExperiencePreferences::MAX_FAVOURITE_MODULES.' shortcuts; pinning another drops the oldest. People can change their shortcuts themselves at any time.',
                'Needs user.update (it changes people\'s sign-in preferences).',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->whereNotNull('user_id')->orderBy('id')->limit(2)->pluck('employee_code')->all();

        return [
            [$codes[0] ?? 'EMP0001', 'Leave requests', ''],
            [$codes[1] ?? ($codes[0] ?? 'EMP0002'), 'Attendance', ''],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> the pages this person may open (each page's own access check, run as them) */
    private function modulesOf(User $person): array
    {
        return $this->asPerson($person, fn () => app(ModuleCatalogue::class)->for($person));
    }

    /**
     * The page a cell names: the menu name (not case-sensitive), "Area › Page" (or "Area > Page", "Area / Page") when the
     * name is in several areas, or the page's class name.
     *
     * @param  list<array<string, mixed>>  $modules
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function find(array $modules, string $cell, Employee $employee): array
    {
        $cell = trim($cell);
        $lower = mb_strtolower($cell);
        $byLabel = array_values(array_filter($modules, fn (array $m) => mb_strtolower(trim((string) $m['label'])) === $lower));
        if ($byLabel === [] && preg_match('/^(.+?)\s*(?:›|>|\/)\s*(.+)$/u', $cell, $parts)) {
            [, $area, $page] = $parts;
            $byLabel = array_values(array_filter($modules, fn (array $m) => mb_strtolower(trim((string) $m['label'])) === mb_strtolower(trim($page))
                && in_array(mb_strtolower(trim($area)), [mb_strtolower((string) $m['group']), mb_strtolower((string) $m['space'])], true)));
        }
        if ($byLabel === []) {
            $byLabel = array_values(array_filter($modules, fn (array $m) => mb_strtolower((string) $m['key']) === $lower || mb_strtolower(class_basename((string) $m['key'])) === $lower));
        }
        if (count($byLabel) === 1) {
            return [$byLabel[0], null];
        }

        return [null, $byLabel === []
            ? "'{$cell}' is not a page {$employee->employee_code} may open."
            : "'{$cell}' is in more than one area: write the area first, e.g. '".$byLabel[0]['group'].' › '.$byLabel[0]['label']."'."];
    }

    /**
     * Run as the person: pages answer "may I open?" for the signed-in user (ScreenAccess::as), inside the admin panel.
     *
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     */
    private function asPerson(User $person, callable $check): mixed
    {
        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
        }

        return ScreenAccess::as($person, $check);
    }
}
