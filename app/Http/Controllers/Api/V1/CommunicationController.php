<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Services\CommunicationPreferences;
use App\Domain\Communication\Services\Communications;
use App\Domain\Employment\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 13 communications API (`/api/v1/communications/*`, scope communications.read). Read-only.
 *
 * It returns:
 * - published (and archived) announcements with their body, Knowledge Base link and aggregate
 *   delivery, read and acknowledgement counts;
 * - an employee's preferences.
 *
 * It never returns:
 * - drafts or items in review;
 * - audience criteria or recipient lists (who received what);
 * - delivery records;
 * - attachment files;
 * - approval notes.
 */
class CommunicationController extends Controller
{
    use PaginatesApi;

    public function index(Request $request, Communications $communications): JsonResponse
    {
        $query = Announcement::query()->with('article')->whereIn('status', ['published', 'archived'])
            ->when($request->query('type'), fn ($q, $t) => $q->whereIn('type', explode(',', (string) $t)));

        $this->sorted($query, $request, ['published_at' => 'announcements.published_at', 'title' => 'announcements.title'], '-published_at');

        return $this->page($query, $request, fn (Announcement $a) => $this->summary($a, $communications));
    }

    public function show(int $communication, Communications $communications): JsonResponse
    {
        $a = Announcement::query()->whereIn('status', ['published', 'archived'])->findOrFail($communication);

        return response()->json(['data' => $this->summary($a, $communications) + ['body' => $a->body]]);
    }

    public function preferences(Request $request, CommunicationPreferences $preferences): JsonResponse
    {
        $employee = Employee::query()->where('employee_code', (string) $request->query('employee'))->first() ?? abort(404);

        return response()->json(['data' => [
            'employee_code' => $employee->employee_code,
            'optional' => $preferences->for($employee),
            'mandatory' => config('peopleos.communication.mandatory_types'),
        ]]);
    }

    private function summary(Announcement $a, Communications $communications): array
    {
        $stats = $communications->stats($a);

        return [
            'id' => $a->id, 'title' => $a->title, 'type' => $a->type, 'priority' => $a->priority, 'version' => $a->version, 'status' => $a->status,
            'published_at' => $a->published_at?->toIso8601String() ?? $a->publish_at?->toIso8601String(), 'expires_at' => $a->expires_at?->toIso8601String(),
            'requires_acknowledgement' => $a->requires_acknowledgement, 'article' => $a->article?->slug, 'has_attachment' => $a->attachment_path !== null,
            'delivery' => ['recipients' => $stats['audience'], 'sent' => $stats['sent'] ?? null, 'failed' => $stats['failed'] ?? null, 'skipped' => $stats['skipped'] ?? null, 'pending' => $stats['pending'] ?? null, 'read' => $stats['read'], 'acknowledged' => $stats['acknowledged']],
        ];
    }
}
