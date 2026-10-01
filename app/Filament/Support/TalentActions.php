<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Database\Eloquent\Model;

/** Phase 9 Filament helpers for career, talent and succession screens. Every write goes through a domain service. */
final class TalentActions
{
    public static function me(): ?Employee
    {
        return LearningActions::me();
    }

    public static function run(callable $callback, string|callable $success): void
    {
        LearningActions::run($callback, $success);
    }

    /** Employees inside the user's organisation scope (the Employee access scope applies), never the user themself. */
    public static function scopedPeopleOptions(): array
    {
        $me = self::me()?->id;

        return Employee::query()->with('person')->employed()->when($me, fn ($q) => $q->whereKeyNot($me))->orderBy('employee_code')->limit(1000)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function designationOptions(): array
    {
        return Designation::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function nodeOptions(): array
    {
        return OrganisationNode::query()->with('nodeable')->orderBy('depth')->orderBy('id')->limit(1000)->get()
            ->mapWithKeys(fn (OrganisationNode $n) => [$n->id => $n->auditLabel()])->all();
    }

    public static function options(string $key): array
    {
        return (array) config($key, []);
    }

    /** A confidential attribute for the current user, read through TalentAccess (audited) — or a neutral placeholder. */
    public static function confidential(Model $record, string $attribute): string
    {
        return app(TalentAccess::class)->confidential($record, $attribute, auth()->user()) ?? 'Not visible to you';
    }
}
