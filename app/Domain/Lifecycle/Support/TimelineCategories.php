<?php

namespace App\Domain\Lifecycle\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\PerformanceRelationships;

/**
 * Phase 14: what each timeline category is, and who may see it.
 *
 * The timeline (employee_timeline_entries) records *events in the employee's working life*. It is
 * distinct from the audit trail: who changed which field, which is Change Intelligence. Each category
 * belongs to one kind:
 * - lifecycle (states, onboarding, exit);
 * - employment (position, reporting, pay, bank / statutory / personal record changes, verification);
 * - service (HR requests);
 * - communication;
 * - domain (leave, performance, learning, documents, assets).
 *
 * Visibility is enforced per category for the viewer, on top of the employee view permission:
 * - sensitive categories need employee.sensitive.view;
 * - performance needs a performance permission, or being the employee's configured manager;
 * - verification needs bgv.view;
 * - an exit entry's description needs exit.view.
 */
final class TimelineCategories
{
    /** category => [label, kind] */
    public const CATEGORIES = [
        'lifecycle' => ['Lifecycle', 'lifecycle'], 'onboarding' => ['Onboarding', 'lifecycle'], 'exit' => ['Exit', 'lifecycle'],
        'position' => ['Position', 'employment'], 'reporting' => ['Reporting', 'employment'], 'compensation' => ['Compensation', 'employment'],
        'bank' => ['Bank account', 'employment'], 'statutory' => ['Statutory', 'employment'], 'personal' => ['Personal details', 'employment'], 'bgv' => ['Verification', 'employment'],
        'service_request' => ['HR request', 'service'], 'communication' => ['Communication', 'communication'],
        'leave' => ['Leave', 'domain'], 'performance' => ['Performance', 'domain'], 'learning' => ['Learning', 'domain'], 'document' => ['Document', 'domain'], 'assets' => ['Assets', 'domain'],
    ];

    public const KINDS = ['lifecycle' => 'Lifecycle event', 'employment' => 'Employment event', 'service' => 'Service event', 'communication' => 'Communication event', 'domain' => 'Domain event'];

    public const SENSITIVE = ['compensation', 'bank', 'statutory', 'personal'];

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category][0] ?? ucfirst(str_replace('_', ' ', $category));
    }

    public static function kind(string $category): string
    {
        return self::CATEGORIES[$category][1] ?? 'domain';
    }

    /** @return list<string> categories this viewer may not see for this employee */
    public static function hiddenFor(?User $viewer, Employee $employee): array
    {
        if ($viewer === null) {
            return array_keys(self::CATEGORIES);
        }
        $hidden = [];
        if (! $viewer->hasPermission('employee.sensitive.view')) {
            array_push($hidden, ...self::SENSITIVE);
        }
        $ownRecord = (int) $employee->user_id === (int) $viewer->id;
        if (! $ownRecord && ! $viewer->hasPermission('performance.view') && ! $viewer->hasPermission('performance.pip')
            && ! app(PerformanceRelationships::class)->manages(app(PerformanceRelationships::class)->forUser($viewer), $employee->id)) {
            $hidden[] = 'performance';
        }
        if (! $viewer->hasPermission('bgv.view')) {
            $hidden[] = 'bgv';
        }

        return array_values(array_unique($hidden));
    }

    public static function showsDescription(?User $viewer, string $category): bool
    {
        return $category !== 'exit' || ($viewer?->hasPermission('exit.view') ?? false);
    }
}
