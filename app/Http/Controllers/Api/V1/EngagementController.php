<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Identity\Scopes\AccessScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 engagement API (`/api/v1/engagement/*`, scope engagement.read). Read-only.
 *
 * It returns:
 * - survey definitions (employee-visible questions only);
 * - aggregate participation;
 * - overall results under the same privacy rules as the application (k threshold; anonymous /
 *   confidential results only after closing).
 *
 * It never returns:
 * - response rows, answers, comments or response ids;
 * - respondents or group breakdowns;
 * - administrator, scoring or analysis metadata;
 * - audience criteria;
 * - anyone's participation in an anonymous or confidential survey.
 *
 * Surveys are addressed by code; other tenants' codes are 404.
 */
class EngagementController extends Controller
{
    use PaginatesApi;

    public function surveys(Request $request): JsonResponse
    {
        $query = Survey::query()->with('versions')
            ->when($request->query('type'), fn ($q, $t) => $q->whereIn('survey_type', explode(',', (string) $t)));

        $this->sorted($query, $request, ['code' => 'surveys.code', 'name' => 'surveys.name', 'created_at' => 'surveys.created_at'], 'code');

        return $this->page($query, $request, fn (Survey $s) => $this->summary($s));
    }

    public function survey(string $code): JsonResponse
    {
        $survey = $this->find($code);
        $current = $survey->versions->first(fn (SurveyVersion $v) => in_array($v->status, ['open', 'scheduled', 'closed', 'archived'], true));

        return response()->json(['data' => $this->summary($survey) + [
            'questions' => $current ? SurveyQuestion::query()->where('survey_version_id', $current->id)->orderBy('position')->orderBy('id')->get()->map(fn (SurveyQuestion $q) => $q->employeeView())->all() : [],
        ]]);
    }

    /** Aggregate participation per published version (counts only, never people). */
    public function participation(string $code): JsonResponse
    {
        $survey = $this->find($code);
        $versions = $survey->versions->whereNotNull('opened_at')->values();
        $counts = AccessScope::withoutScoping(fn () => SurveyParticipation::query()->whereIn('survey_version_id', $versions->pluck('id'))
            ->select('survey_version_id', 'status', DB::raw('count(*) as n'))->groupBy('survey_version_id', 'status')->get()->groupBy('survey_version_id'));

        return response()->json(['data' => $versions->map(function (SurveyVersion $v) use ($counts) {
            $rows = $counts->get($v->id, collect());
            $eligible = (int) $rows->sum('n');
            $submitted = (int) $rows->where('status', 'submitted')->sum('n');

            return ['version' => $v->version, 'status' => $v->status, 'eligible' => $eligible, 'submitted' => $submitted, 'response_rate' => $eligible > 0 ? round(100 * $submitted / $eligible, 1) : null];
        })->all()]);
    }

    /** Overall results of one version, privacy-suppressed exactly as in the application. */
    public function results(string $code, int $version, EngagementAnalytics $analytics): JsonResponse
    {
        $survey = $this->find($code);
        $v = $survey->versions->firstWhere('version', $version) ?? abort(404);
        abort_if($v->opened_at === null, 404);
        $r = $analytics->overall($v);

        return response()->json(['data' => [
            'version' => $v->version, 'mode' => $v->anonymity_mode, 'released' => $r['released'], 'threshold' => $r['k'], 'respondents' => $r['respondents'], 'suppressed' => $r['suppressed'],
            'questions' => collect($r['questions'])->reject(fn ($q) => $q['type'] === 'text')->map(fn ($q) => ['key' => $q['key'], 'type' => $q['type'], 'overall' => $q['overall']])->values()->all(),
        ]]);
    }

    /**
     * An employee's surveys (for an employee-facing integration). Participation status is shown for
     * identified surveys only; for anonymous / confidential surveys it is never disclosed per person.
     */
    public function mySurveys(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('employee_code', (string) $request->query('employee'))->first() ?? abort(404);
        $participations = AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('employee_id', $employee->id)->orderByDesc('id')->limit(100)->get()->keyBy('survey_version_id'));
        $versions = SurveyVersion::query()->with('survey')->whereIn('id', $participations->keys())->whereIn('status', ['open', 'closed', 'archived'])->orderByDesc('id')->get();

        return response()->json(['data' => $versions->map(fn (SurveyVersion $v) => [
            'survey' => $v->survey->code, 'name' => $v->survey->name, 'version' => $v->version, 'status' => $v->status, 'mode' => $v->anonymity_mode,
            'closes_at' => $v->closes_at?->toIso8601String(),
            'participation' => $v->isIdentified() ? $participations[$v->id]->status : 'not_disclosed',
        ])->values()->all()]);
    }

    private function find(string $code): Survey
    {
        return Survey::query()->with('versions')->where('code', strtoupper($code))->firstOrFail();
    }

    private function summary(Survey $s): array
    {
        return [
            'code' => $s->code, 'name' => $s->name, 'type' => $s->survey_type, 'category' => $s->category, 'description' => $s->description,
            'versions' => $s->versions->whereNotIn('status', ['draft', 'in_review'])->map(fn (SurveyVersion $v) => [
                'version' => $v->version, 'status' => $v->status, 'mode' => $v->anonymity_mode, 'response_rule' => $v->response_rule,
                'opens_at' => $v->opens_at?->toIso8601String(), 'closes_at' => $v->closes_at?->toIso8601String(), 'eligible' => $v->eligible_count,
            ])->values()->all(),
        ];
    }
}
