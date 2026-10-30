<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * HROne parity WO-2 ("Download profile data"): the Employee 360 as a printable page (the browser prints or saves it as a
 * PDF; a server-side PDF waits for the PDF-library decision). Signed link → authenticated user → tenant → employee.view and
 * the record's `view` policy → audit → render. Sensitive sections (date of birth, blood group, personal contacts, statutory and bank
 * details) are included only with `viewSensitive`, masked, and that access is recorded like on the 360. Sections the profile
 * access matrix (CA-6, profile.section_visibility) hides from this viewer are left out, as on the 360 tabs.
 */
class EmployeeProfilePrintController extends Controller
{
    public static function url(Employee $employee): string
    {
        return URL::temporarySignedRoute('employees.profile-print', now()->addMinutes(30), ['employee' => $employee->getKey()]);
    }

    /** HR's printout (HROne "Download profile data" is an HR right): employee.view and the record's view policy, not employee.self. */
    public static function allows(?User $user, Employee $employee): bool
    {
        return $user !== null && $user->hasPermission('employee.view') && $user->can('view', $employee);
    }

    public function __invoke(Request $request, int $employee, AuditRecorder $audit, SensitiveAccessAuditor $sensitive, ProfileSectionVisibility $visibility): View
    {
        abort_unless($request->hasValidSignature(), 403);
        $employee = Employee::query()->with([
            'person.addresses', 'person.familyMembers', 'person.emergencyContacts', 'person.qualifications', 'person.experiences',
            'currentPosition.designation', 'currentPosition.department', 'currentPosition.company', 'currentPosition.location',
            'currentPosition.businessUnit', 'currentPosition.grade', 'currentPosition.employmentType', 'currentManager.manager.person',
        ])->findOrFail($employee);
        abort_unless(self::allows($request->user(), $employee), 403);
        $withSensitive = Gate::allows('viewSensitive', $employee);
        $shows = collect(['personal', 'family', 'professional', 'bank'])->mapWithKeys(fn (string $section) => [$section => $visibility->allows($request->user(), $employee, $section)])->all();

        $positions = $employee->positions()->with(['designation', 'department', 'location', 'company'])->orderByDesc('effective_from')->limit(50)->get();
        $reporting = $employee->reportingRelationships()->with('manager.person')->orderByDesc('effective_from')->limit(50)->get();
        if ($withSensitive) {
            $sensitive->recordView($employee, 'profile', 'print');
        }
        $audit->record(AuditAction::Export, 'employment', $employee, metadata: ['document' => 'employee_profile', 'sensitive_sections' => $withSensitive], reason: 'Profile printed');

        return view('employees.profile-print', [
            'employee' => $employee,
            'person' => $employee->person,
            'position' => $employee->currentPosition,
            'positions' => $positions,
            'reporting' => $reporting,
            'withSensitive' => $withSensitive,
            'statutory' => $withSensitive ? $employee->statutoryDetail : null,
            'bank' => $withSensitive && $shows['bank'] ? $employee->bankAccounts()->orderByDesc('is_primary')->get() : collect(),
            'shows' => $shows,
            'mask' => fn (?string $value) => blank($value) ? '—' : str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4),
        ]);
    }
}
