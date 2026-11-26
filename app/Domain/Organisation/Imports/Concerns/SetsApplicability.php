<?php

namespace App\Domain\Organisation\Imports\Concerns;

use App\Domain\Configuration\Applicability\ApplicabilityMode;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Imports\ClearValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Master uploads: where a configuration master applies, from two optional columns, through the same services as the
 * record's "Applies to" step (EntityApplicability, docs/architecture/configuration-applicability.md):
 *
 *  - Applies to: `all` (every company), one company (that company only; for a company-owned unit, its own company) or
 *    several companies separated by `;` — by name (names first; codes and "Name (CODE)" work too). Blank keeps what is
 *    on file; a new record then has its usual meaning (a shared master: every company; a company-owned unit: its company).
 *  - Future companies: with `all`, whether companies added later join automatically (yes, the default) or not.
 *
 * Checked as the uploader with plan() (their company access, configuration.share for several companies, the master's
 * own right per company, dependencies) and written with apply() in the row's transaction. A record that already reaches
 * companies outside the uploader's access is refused, as its edit screen refuses it.
 */
trait SetsApplicability
{
    /** @return array<string, array{0: string, 1: bool}> */
    protected static function applicabilityFields(): array
    {
        return ['applies_to' => ['Applies to', false], 'future_companies' => ['Future companies', false]];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    protected static function applicabilityColumns(): array
    {
        return [
            ['Applies to', 'No', 'Where it may be used: all (every company), one company\'s name (that company only) or several company names separated by ; (codes work too). Blank keeps what is on file; a new record then applies as it would on its own screen.', 'all'],
            ['Future companies', 'No', 'With Applies to = all: yes (default) lets companies added later use it too; no keeps it to today\'s companies.', 'yes'],
        ];
    }

    protected function rowErrors(array $r, array $attributes, ?Model $existing, array &$context, EmployeeImport $import): array
    {
        $context['row_selection'] = null;
        $type = $this->applicabilityType();
        if ($type === null) {
            return [];
        }
        $errors = [];
        $service = app(EntityApplicability::class);
        $uploader = $context['uploader'] ??= User::query()->find($import->uploaded_by);
        if ($existing !== null && $uploader !== null && ! $service->editableBy($existing, $uploader)) {
            return ["'".($existing->getAttributes()['name'] ?? $existing->getAttribute($this->keyColumn()))."' is also used by companies outside your access, so you may not change it here."];
        }
        [$selection, $pending] = $this->applicabilitySelection($r, $context, $errors);
        if ($errors !== []) {
            return $errors;
        }
        $owner = $type->ownerColumn;
        if ($selection === null) {
            // A company-owned unit limited to some companies cannot move to a company outside them without saying where.
            $moved = $owner !== null && $existing !== null && ($attributes[$owner] ?? 0) > 0 && (int) $attributes[$owner] !== (int) $existing->getRawOriginal($owner);
            if ($moved && $service->isExplicit($existing) && ! in_array((int) $attributes[$owner], $service->reach($existing) ?? [(int) $attributes[$owner]], true)) {
                $errors[] = 'It moves to a company it does not apply to: give Applies to as well (for example that company).';
            }

            return $errors;
        }
        $context['row_selection'] = $selection;
        // A company or a linked record an earlier worksheet adds does not exist yet: the run checks where it applies.
        if ($pending || in_array(MasterImports::PLANNED, $attributes, true)) {
            return [];
        }
        $plan = $service->plan($existing ?? $this->probe($attributes, $r), $this->forOwner($selection, $existing, $attributes, $owner), $uploader);

        return $plan->blocked() ? array_values($plan->blockers) : [];
    }

    protected function otherChanges(array $r, Model $existing, array &$context, EmployeeImport $import): array
    {
        $selection = $context['row_selection'] ?? null;
        if ($selection === null) {
            return [];
        }
        $selection = $this->forOwner($selection, $existing, [], $this->applicabilityType()?->ownerColumn);

        return self::differs($selection, app(EntityApplicability::class)->state($existing)) ? ['applies_to'] : [];
    }

    protected function saved(Model $record, bool $created, array $r, array &$context, EmployeeImport $import): void
    {
        $selection = $context['row_selection'] ?? null;
        $type = $this->applicabilityType();
        if ($selection === null || $type === null) {
            return;
        }
        $service = app(EntityApplicability::class);
        $selection = $this->forOwner($selection, $record, [], $type->ownerColumn);
        if ($created) {
            // As the record's own screen: a choice that means what a new record means anyway writes nothing.
            $earlier = $service->earlierReach($type, $record);
            $same = ($earlier === null && $selection->mode === ApplicabilityMode::All && $selection->includeFuture)
                || ($earlier !== null && $selection->mode === ApplicabilityMode::Single && $selection->companyIds === $earlier);
        } else {
            $same = ! self::differs($selection, $service->state($record));
        }
        if (! $same) {
            $service->apply($record, $selection, $context['uploader'] ?? User::query()->find($import->uploaded_by), $this->label()." #{$import->getKey()} ({$import->original_name})");
        }
    }

    /**
     * Whether a selection differs from a record's current state (EntityApplicability::state): every company and the
     * future-companies choice, or the companies listed.
     *
     * @param  array{mode: string, companies: list<int>, include_future: bool}  $state
     */
    private static function differs(ApplicabilitySelection $selection, array $state): bool
    {
        $all = $selection->mode === ApplicabilityMode::All;
        if ($all !== ($state['mode'] === ApplicabilityMode::All->value)) {
            return true;
        }
        if ($all) {
            return $selection->includeFuture !== (bool) $state['include_future'];
        }
        $wanted = array_map('intval', $selection->companyIds);
        $current = array_map('intval', $state['companies']);
        sort($wanted);
        sort($current);

        return $wanted !== $current;
    }

    /**
     * The selection the row asks for, or null for "keep"; and whether it names a company an earlier worksheet adds.
     *
     * @param  list<string>  $errors
     * @return array{0: ?ApplicabilitySelection, 1: bool}
     */
    private function applicabilitySelection(array $r, array &$context, array &$errors): array
    {
        $value = trim((string) ($r['applies_to'] ?? ''));
        $future = $this->flag($r['future_companies'] ?? null, 'Future companies', $errors);
        if ($value === '') {
            if ($future !== null) {
                $errors[] = 'Future companies goes with Applies to = all.';
            }

            return [null, false];
        }
        if (ClearValue::isClear($value)) {
            $errors[] = 'Applies to cannot be '.ClearValue::NONE.': write all, or the companies.';

            return [null, false];
        }
        if (in_array(mb_strtolower($value), ['all', 'all companies', 'every company'], true)) {
            return [ApplicabilitySelection::all($future ?? true), false];
        }
        if ($future !== null) {
            $errors[] = 'Future companies goes with Applies to = all.';
        }
        $ids = [];
        $pending = false;
        foreach (preg_split('/\s*;\s*/', $value) as $part) {
            if ($part === '') {
                continue;
            }
            $id = $this->lookup($context, Company::class, $part, 'Applies to company', $errors);
            $pending = $pending || $id === MasterImports::PLANNED;
            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($errors !== [] || ($ids === [] && ! $pending)) {
            return [null, $pending];
        }

        return [count($ids) === 1 && ! $pending ? ApplicabilitySelection::single($ids[0]) : ApplicabilitySelection::selected($ids), $pending];
    }

    /** A company-owned unit "only here" means its own company (the record's screen does the same). */
    private function forOwner(ApplicabilitySelection $selection, ?Model $record, array $attributes, ?string $owner): ApplicabilitySelection
    {
        $ownerId = $owner === null ? null : (($attributes[$owner] ?? 0) > 0 ? (int) $attributes[$owner] : $record?->getAttribute($owner));

        return $selection->mode === ApplicabilityMode::Single && $ownerId !== null ? ApplicabilitySelection::single((int) $ownerId) : $selection;
    }

    /** An unsaved record with the row's values, for plan() before it exists. */
    private function probe(array $attributes, array $r): Model
    {
        $model = $this->model();

        return new $model(array_merge($this->createDefaults(), array_map(fn ($v) => $v === MasterImports::CLEARED ? null : $v, $attributes),
            [$this->keyColumn() => trim((string) ($r[$this->keyField()] ?? ''))]));
    }

    private function applicabilityType(): ?ConfigurationType
    {
        $type = app(ConfigurationTypes::class)->forModel($this->model());

        return $type !== null && $type->share ? $type : null;
    }
}
