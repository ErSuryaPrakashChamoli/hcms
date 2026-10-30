<?php

namespace App\Domain\Experience\Services;

use App\Domain\Audit\Services\ChangeIntelligence;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Policies\EmployeeDocumentPolicy;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\RetirementDates;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Support\TimelineCategories;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Services\PaymentModes;
use App\Domain\People\Models\PersonAddress;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonExperience;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\PersonSkill;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Skills\Services\SkillProfiles;
use App\Domain\Skills\Support\SkillProficiencies;
use App\Domain\Workforce\Models\Position;
use App\Http\Controllers\ParityFileController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity MS-2 (My space → Profile): the employee's own profile, read by section (Snapshot, Personal, Family,
 * Professional, Skills, Other details, Bank, Previous employer, Documents, History).
 *
 * Every value is read through its owning domain (People, Employment, Exit, Payroll pay modes, Compliance declarations,
 * Documents, Skills, custom fields, Lifecycle timeline, the audit trail through ChangeIntelligence). It only ever reads
 * the viewer's own record (the caller passes the employee from CurrentEmployee; every method refuses another person's),
 * and the section list follows the CA-6 profile-section matrix for the employee audience plus each domain's own rule
 * (documents need document.own).
 *
 * Own identifiers (PAN, Aadhaar reference, UAN, PF and ESIC numbers) and bank account numbers are shown only with
 * `employee.self_sensitive` (owner decision PRV-1 / D-MS1 is open; the permission is not in the employee template, so by
 * default they are not shown, as before): masked to the last four, the full value only for fields the page has revealed
 * (each reveal audited by the page through SensitiveAccessAuditor). Nothing here writes.
 */
final class MyProfileSections
{
    /** Statutory identifiers an employee may reveal (field => label). */
    public const IDENTIFIERS = ['pan' => 'PAN', 'aadhaar_reference' => 'Aadhaar reference', 'uan' => 'UAN', 'pf_number' => 'PF number', 'esic_number' => 'ESIC number'];

    public function __construct(private readonly ProfileSectionVisibility $matrix) {}

    public static function isOwn(User $viewer, Employee $employee): bool
    {
        return $employee->getAttribute('user_id') !== null && (int) $employee->getAttribute('user_id') === (int) $viewer->getKey()
            && (int) $employee->getAttribute('tenant_id') === (int) $viewer->getAttribute('tenant_id');
    }

    public static function maySeeSensitive(User $viewer, Employee $employee): bool
    {
        return self::isOwn($viewer, $employee) && $viewer->hasPermission('employee.self_sensitive');
    }

    /** @return array<string, string> the sections this employee sees on My profile (key => label), in order */
    public function sections(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee)) {
            return [];
        }
        $out = ['snapshot' => 'Snapshot'];
        foreach (ProfileSectionVisibility::sections() as $key => $label) {
            if (! $this->matrix->allowsAudience('employee', $key)) {
                continue;
            }
            if ($key === 'documents' && ! $viewer->hasPermission('document.own')) {
                continue;
            }
            $out[$key] = match ($key) {
                'personal' => 'Personal', 'professional' => 'Professional', 'other_details' => 'Other details', default => $label,
            };
        }

        return $out;
    }

    /** @return array{name: string, code: string, line: string} */
    public function header(Employee $employee): array
    {
        $employee->loadMissing(['person', 'currentPosition.company', 'currentPosition.businessUnit', 'currentPosition.designation', 'currentPosition.department']);
        $p = $employee->currentPosition;

        return [
            'name' => (string) ($employee->person?->display_name ?? $employee->employee_code),
            'code' => (string) $employee->employee_code,
            'line' => collect([$p?->designation?->name, $p?->department?->name, $p?->businessUnit?->name, $p?->company?->name])->filter()->implode(' · '),
        ];
    }

    /** @return array<string, ?string> label => value (the snapshot: basic HR facts, including the stored-but-hidden ones) */
    public function snapshot(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee)) {
            return [];
        }
        $employee->loadMissing(['person', 'currentPosition.company', 'currentPosition.businessUnit', 'currentPosition.division', 'currentPosition.department',
            'currentPosition.team', 'currentPosition.location', 'currentPosition.designation', 'currentPosition.level', 'currentPosition.grade',
            'currentPosition.employmentType', 'currentManager.manager.person']);
        $person = $employee->person;
        $p = $employee->currentPosition;
        $date = fn ($d) => $d?->format('j M Y');
        $functional = ReportingRelationship::query()->with('manager.person')->where('employee_id', $employee->getKey())->where('type', 'functional')->currentlyEffective()->first();
        $retirement = $this->safe(fn () => app(RetirementDates::class)->for($employee));
        $category = $p?->designation?->wage_skill_category;

        $facts = [
            'Employee code' => $employee->employee_code,
            'Name' => $person?->full_name,
            'Preferred name' => $person?->preferred_name,
            'Date of birth' => $date($person?->date_of_birth),
            'Gender' => $person?->gender ? (string) config("peopleos.people.genders.{$person->gender}", $person->gender) : null,
            'Blood group' => $person?->blood_group,
            'Nationality' => $person?->nationality,
            'Work email' => $employee->work_email,
            'Work phone' => $employee->work_phone,
            'Date of joining' => $date($employee->joining_date),
            'Employment type' => $p?->employmentType?->name,
            'Employment status' => $employee->lifecycle_state?->getLabel(),
            'Date of confirmation' => $date($employee->confirmation_date),
            'Company' => $p?->company?->name,
            'Business unit' => $p?->businessUnit?->name,
            'Department' => $p?->department?->name,
            'Team' => $p?->team?->name,
            'Designation' => $p?->designation?->name,
            'Location' => $p?->location?->name,
            'Grade' => $p?->grade?->name,
            'Level' => $p?->level?->name,
            'Reporting manager' => $employee->currentManager?->manager?->person?->display_name,
            'Functional manager' => $functional?->manager?->person?->display_name,
            'Position' => $p?->position_id ? AccessScope::withoutScoping(fn () => Position::query()->whereKey($p->position_id)->value('code')) : null,
            'Skill type' => $category ? (string) config("peopleos.compliance.wage_skill_categories.{$category}", $category) : null,
            'Retirement date' => $retirement ? $date($retirement['date']) : null,
        ];
        // HROne parity 22.2.1 #18: exit dates while an exit case exists (never the organisation's reason or rehire status, PRV-3).
        $exit = ExitCase::query()->with('settlement')->where('employee_id', $employee->getKey())->where('status', '!=', 'cancelled')->latest('id')->first();
        if ($exit !== null) {
            $facts += [
                'Date of resignation' => $date($exit->resignation_date),
                'Last working day' => $date($exit->last_working_day),
                'Settlement' => $exit->settlement ? ucfirst(str_replace('_', ' ', (string) $exit->settlement->status)).($exit->settlement->paid_at ? ' ('.$date($exit->settlement->paid_at).')' : '') : null,
            ];
        }

        return array_filter($facts, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Identification (masked; full only for revealed fields and only with employee.self_sensitive), contact, addresses and
     * emergency contacts.
     *
     * @param  list<string>  $revealed
     * @return array{identifiers: list<array{field: string, label: string, value: string}>, sensitive: bool, contact: array<string, ?string>, addresses: list<array<string, ?string>>, emergency: list<array<string, ?string>>}
     */
    public function personal(User $viewer, Employee $employee, array $revealed = []): array
    {
        $empty = ['identifiers' => [], 'sensitive' => false, 'contact' => [], 'addresses' => [], 'emergency' => []];
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'personal')) {
            return $empty;
        }
        $employee->loadMissing('person');
        $identifiers = [];
        $sensitive = self::maySeeSensitive($viewer, $employee);
        if ($sensitive) {
            $detail = EmployeeStatutoryDetail::query()->where('employee_id', $employee->getKey())->first();
            foreach (self::IDENTIFIERS as $field => $label) {
                $value = $detail?->{$field};
                if (filled($value)) {
                    $identifiers[] = ['field' => $field, 'label' => $label, 'value' => in_array($field, $revealed, true) ? (string) $value : (string) EmployeeStatutoryDetail::mask((string) $value), 'revealed' => in_array($field, $revealed, true)];
                }
            }
        }
        $types = (array) config('peopleos.people.address_types', []);

        return [
            'identifiers' => $identifiers,
            'sensitive' => $sensitive,
            'contact' => array_filter(['Personal email' => $employee->person?->personal_email, 'Personal phone' => $employee->person?->personal_phone]),
            'addresses' => PersonAddress::query()->where('person_id', $employee->person_id)->currentlyEffective()->orderBy('type')->get()
                ->map(fn (PersonAddress $a) => ['type' => (string) ($types[$a->type] ?? ucfirst((string) $a->type)),
                    'address' => collect([$a->address_line_1, $a->address_line_2, $a->city, $a->state_code, $a->postal_code, $a->country_code])->filter()->implode(', ')])->values()->all(),
            'emergency' => PersonEmergencyContact::query()->where('person_id', $employee->person_id)->orderBy('priority')->get()
                ->map(fn (PersonEmergencyContact $c) => ['name' => $c->name, 'relation' => $c->relation, 'phone' => collect([$c->phone, $c->alternate_phone])->filter()->implode(' / '), 'email' => $c->email])->values()->all(),
        ];
    }

    /** @return array{marital_status: ?string, members: list<array<string, ?string>>} */
    public function family(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'family')) {
            return ['marital_status' => null, 'members' => []];
        }
        $employee->loadMissing('person');
        $status = $employee->person?->marital_status;
        $relations = app(OptionLists::class);

        return [
            'marital_status' => $status ? (string) config("peopleos.people.marital_statuses.{$status}", $status) : null,
            'members' => PersonFamilyMember::query()->where('person_id', $employee->person_id)->orderBy('id')->get()
                ->map(fn (PersonFamilyMember $m) => ['name' => $m->name, 'relation' => $relations->label('family_relations', $m->relation), 'date_of_birth' => $m->date_of_birth?->format('j M Y'),
                    'dependent' => $m->is_dependent ? 'Yes' : 'No', 'nominee' => $m->is_nominee ? (($m->nominee_share !== null ? rtrim(rtrim((string) $m->nominee_share, '0'), '.').'%' : 'Yes')) : 'No'])->values()->all(),
        ];
    }

    /** @return array{experience: list<array<string, ?string>>, total_months: int, education: list<array<string, ?string>>} */
    public function professional(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'professional')) {
            return ['experience' => [], 'total_months' => 0, 'education' => []];
        }
        $total = 0;
        $experience = PersonExperience::query()->where('person_id', $employee->person_id)->orderByDesc('from_date')->get()
            ->map(function (PersonExperience $x) use (&$total) {
                $months = $x->from_date ? (int) $x->from_date->diffInMonths($x->to_date ?? now()) : null;
                $total += (int) $months;

                return ['employer' => $x->employer, 'designation' => $x->designation, 'from' => $x->from_date?->format('M Y'), 'to' => $x->to_date?->format('M Y') ?? 'Present',
                    'months' => $months === null ? null : (string) $months, 'description' => $x->responsibilities];
            })->values()->all();

        return [
            'experience' => $experience,
            'total_months' => $total,
            'education' => PersonQualification::query()->where('person_id', $employee->person_id)->orderByDesc('year_of_completion')->get()
                ->map(fn (PersonQualification $q) => ['qualification' => $q->qualification, 'specialisation' => $q->specialisation, 'institution' => $q->institution,
                    'board' => $q->board_or_university, 'year' => $q->year_of_completion ? (string) $q->year_of_completion : null, 'grade' => $q->grade_or_score])->values()->all(),
        ];
    }

    /** @return list<array{skill: string, level: ?string, verified: bool}> the current skill profile (Skills) */
    public function skills(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'skills')) {
            return [];
        }

        return collect(app(SkillProfiles::class)->profile($employee))->filter(fn (array $s) => $s['skill'] !== null)
            ->map(fn (array $s) => ['skill' => (string) $s['skill'], 'level' => $s['label'], 'verified' => (bool) $s['verified']])->values()->all();
    }

    /**
     * HROne parity MS-3: the skills the person declared on their profile (person_skills; changed through My profile when
     * the organisation allows it). Separate from the assessed skill profile above.
     *
     * @return list<array{skill: string, level: ?string, years: ?int}>
     */
    public function profileSkills(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'skills')) {
            return [];
        }

        return PersonSkill::query()->with('skill')->where('person_id', $employee->person_id)->orderBy('id')->get()
            ->map(fn (PersonSkill $s) => ['skill' => (string) ($s->skill?->name ?? '—'), 'level' => SkillProficiencies::label($s->proficiency), 'years' => $s->years_of_experience])->values()->all();
    }

    /** @return list<array{label: string, value: ?string}> the custom fields the organisation shows to employees */
    public function otherDetails(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'other_details')) {
            return [];
        }
        $fields = app(CustomFields::class)->definitionsFor(Employee::class)->filter(fn (CustomField $f) => (bool) $f->visible_to_employee);
        if ($fields->isEmpty()) {
            return [];
        }
        $values = app(CustomFields::class)->valuesFor($employee);

        return $fields->map(function (CustomField $f) use ($values) {
            $value = $values[$f->key] ?? null;
            $options = $f->optionMap();
            $text = match (true) {
                $value === null || $value === '' || $value === [] => null,
                is_bool($value) => $value ? 'Yes' : 'No',
                is_array($value) => collect($value)->map(fn ($v) => $options[$v] ?? $v)->implode(', '),
                default => (string) ($options[$value] ?? $value),
            };

            return ['label' => (string) $f->label, 'value' => $text];
        })->values()->all();
    }

    /**
     * Pay mode (as payroll pays today) and the bank accounts: bank, branch, IFSC, type, primary; the account number only
     * with employee.self_sensitive (masked, full when revealed).
     *
     * @param  list<string>  $revealed  revealed fields ("bank:{id}")
     * @return array{pay_mode: string, reimbursement_mode: ?string, sensitive: bool, accounts: list<array<string, mixed>>}
     */
    public function bank(User $viewer, Employee $employee, array $revealed = []): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'bank')) {
            return ['pay_mode' => '', 'reimbursement_mode' => null, 'sensitive' => false, 'accounts' => []];
        }
        $mode = app(PaymentModes::class)->for((int) $employee->getKey(), now());
        $sensitive = self::maySeeSensitive($viewer, $employee);
        // HROne setup parity BP-13: payroll.bank.show_ifsc_branch hides the IFSC and branch here as on the Bank tab.
        $ifscBranch = (bool) app(SettingsRepository::class)->get('payroll.bank.show_ifsc_branch', true);

        return [
            'pay_mode' => EmployeePaymentMode::MODES[$mode?->salary_mode ?? 'online_transfer'] ?? 'Bank transfer',
            'reimbursement_mode' => $mode?->reimbursement_mode ? (EmployeePaymentMode::MODES[$mode->reimbursement_mode] ?? $mode->reimbursement_mode) : null,
            'sensitive' => $sensitive,
            'accounts' => EmployeeBankAccount::query()->where('employee_id', $employee->getKey())->orderByDesc('is_primary')->orderBy('id')->get()
                ->map(fn (EmployeeBankAccount $a) => ['id' => (int) $a->id, 'holder' => $a->account_holder_name, 'bank' => $a->bank_name, 'branch' => $ifscBranch ? $a->branch_name : null, 'ifsc' => $ifscBranch ? $a->ifsc : null,
                    'type' => $a->account_type ? ucfirst((string) $a->account_type) : null, 'primary' => (bool) $a->is_primary,
                    'number' => $sensitive ? (in_array('bank:'.$a->id, $revealed, true) ? (string) $a->account_number : $a->maskedAccountNumber()) : null,
                    'revealed' => $sensitive && in_array('bank:'.$a->id, $revealed, true)])->values()->all(),
        ];
    }

    /** @return array{year: string, declared: bool, facts: array<string, ?string>} the previous employer on this financial year's declaration */
    public function previousEmployer(User $viewer, Employee $employee): array
    {
        $year = app(FinancialYear::class)->label(now());
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'previous_employer')) {
            return ['year' => $year, 'declared' => false, 'facts' => []];
        }
        $declaration = $this->safe(fn () => app(InvestmentDeclarations::class)->declaration($employee, $year));
        if ($declaration === null || ((float) $declaration->previous_employer_income <= 0 && (float) $declaration->previous_employer_tds <= 0 && blank($declaration->previous_employer))) {
            return ['year' => $year, 'declared' => false, 'facts' => []];
        }
        $money = fn ($v) => $v === null || $v === '' ? null : number_format((float) $v, 2);
        $date = fn ($v) => blank($v) ? null : Carbon::parse($v)->format('j M Y');

        return ['year' => $year, 'declared' => true, 'facts' => array_filter([
            'Employer' => $declaration->previousEmployer('name'),
            'TAN' => $declaration->previousEmployer('tan'),
            'Worked from' => $date($declaration->previousEmployer('from')),
            'Until' => $date($declaration->previousEmployer('to')),
            'Taxable salary' => $money($declaration->previous_employer_income),
            'Tax deducted (TDS)' => $money($declaration->previous_employer_tds),
            'PF deducted' => $money($declaration->previousEmployer('pf')),
            'Professional tax deducted' => $money($declaration->previousEmployer('pt')),
        ], fn ($v) => $v !== null && $v !== '')];
    }

    /**
     * Every document type the employee may see, grouped by category, with the current document of each (file name, last
     * updated, status, expiry, review note) or "not uploaded", the sample when there is one, plus documents of no type.
     *
     * @return array<string, list<array<string, mixed>>> category label => rows
     */
    public function documents(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $viewer->hasPermission('document.own') || ! $this->matrix->allowsAudience('employee', 'documents')) {
            return [];
        }
        $documents = EmployeeDocument::query()->with('type')->where('employee_id', $employee->getKey())->where('status', '!=', 'archived')->orderByDesc('version')->orderByDesc('id')->get()
            ->filter(fn (EmployeeDocument $d) => EmployeeDocumentPolicy::isOwn($viewer, $d))->values();
        $types = DocumentType::query()->where('status', 'active')->where('employee_can_view', true)->orderBy('name')->get();
        $categories = app(OptionLists::class);
        $row = fn (?DocumentType $type, ?EmployeeDocument $d) => [
            'document_id' => $d?->id,
            'name' => (string) ($d?->title ?? $type?->name),
            'type' => $type?->name,
            'file' => $d?->original_name,
            'updated' => $d?->updated_at?->format('j M Y'),
            'status' => $d ? ucfirst((string) $d->status) : 'Not uploaded',
            'expires' => $d?->expires_on?->format('j M Y'),
            'note' => $d?->review_note,
            // HROne parity MS-3 (22.2.9 #5): the uploader's own comment.
            'comment' => $d?->upload_comment,
            'sample' => $type !== null && filled($type->sample_path) ? $this->safe(fn () => ParityFileController::url('document-type-sample', $type, 0)) : null,
            'instructions' => $type?->sample_instructions,
        ];
        $grouped = [];
        foreach ($types as $type) {
            $label = (string) ($categories->label('document_categories', $type->category) ?? 'Other');
            $current = $documents->where('document_type_id', $type->id);
            foreach ($current->isEmpty() ? [null] : $current->all() as $d) {
                $grouped[$label][] = $row($type, $d);
            }
        }
        // Documents of a type no longer listed (inactive) or of no type.
        foreach ($documents->reject(fn (EmployeeDocument $d) => $d->document_type_id !== null && $types->contains('id', $d->document_type_id)) as $d) {
            $label = (string) ($d->type ? ($categories->label('document_categories', $d->type->category) ?? 'Other') : 'Other');
            $grouped[$label][] = $row($d->type, $d);
        }
        ksort($grouped);

        return $grouped;
    }

    /** @return Collection<int, EmployeeDocument> the documents "Download all" puts in the ZIP (current, the employee may open) */
    public function downloadable(User $viewer, Employee $employee): Collection
    {
        if (! self::isOwn($viewer, $employee) || ! $viewer->hasPermission('document.own') || ! $this->matrix->allowsAudience('employee', 'documents')) {
            return collect();
        }

        return EmployeeDocument::query()->with('type')->where('employee_id', $employee->getKey())->where('status', '!=', 'archived')->orderBy('id')->get()
            ->filter(fn (EmployeeDocument $d) => EmployeeDocumentPolicy::isOwn($viewer, $d))->values()->toBase();
    }

    /**
     * The lifecycle timeline (the categories the employee may see, as on their 360) and "My changes": their own audited
     * non-sensitive profile fields with earlier and new values.
     *
     * @return array{timeline: list<array<string, ?string>>, changes: list<array<string, mixed>>}
     */
    public function history(User $viewer, Employee $employee): array
    {
        if (! self::isOwn($viewer, $employee) || ! $this->matrix->allowsAudience('employee', 'history')) {
            return ['timeline' => [], 'changes' => []];
        }
        $hidden = TimelineCategories::hiddenFor($viewer, $employee);

        return [
            'timeline' => EmployeeTimelineEntry::query()->where('employee_id', $employee->getKey())->whereNotIn('category', $hidden)
                ->orderByDesc('occurred_on')->orderByDesc('id')->limit(30)->get()
                ->map(fn (EmployeeTimelineEntry $t) => ['date' => $t->occurred_on?->format('j M Y'), 'title' => $t->title, 'category' => TimelineCategories::label($t->category),
                    'detail' => TimelineCategories::showsDescription($viewer, $t->category) ? $t->description : null])->values()->all(),
            'changes' => $this->withRequests($employee, app(ChangeIntelligence::class)->ownProfileChanges($viewer, $employee)),
        ];
    }

    /**
     * HROne parity MS-3 (22.2.10 #4): a change made by a self-service profile change request names it — requested on / by,
     * approved on / by (or "applied at once").
     *
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    private function withRequests(Employee $employee, array $changes): array
    {
        $ids = array_values(array_filter(array_map(fn (array $c) => ProfileChangeRequest::idFromReference($c['reference'] ?? null), $changes)));
        if ($ids === []) {
            return $changes;
        }
        $requests = ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->with(['requester', 'decider'])->where('employee_id', $employee->getKey())->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(function (array $c) use ($requests) {
            $request = $requests->get(ProfileChangeRequest::idFromReference($c['reference'] ?? null));
            if ($request !== null) {
                $c['request'] = $request->reference().' · requested '.$request->created_at?->format('j M Y').' by '.($request->requester?->name ?? '—')
                    .($request->route === 'direct' ? ' · applied at once' : ($request->decided_at ? ' · approved '.$request->decided_at->format('j M Y').' by '.($request->decider?->name ?? '—') : ''));
            }

            return $c;
        }, $changes);
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
