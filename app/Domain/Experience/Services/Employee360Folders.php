<?php

namespace App\Domain\Experience\Services;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Support\Numbering\NumberSeries;
use Illuminate\Support\Collection;

/**
 * The Employee 360 "Documents" and "Letters" cards open as folders: the person's documents by category and document
 * type, and their letters by letter type, with an upload (or a letter) right there for the chosen category or type.
 *
 * Read-only and permission-aware, like Employee360: the same rules as the Documents and Letters records decide what is
 * listed, every document passes EmployeeDocumentPolicy::view (access scope, the employee's own visibility rules), and
 * an employee who only holds document.own sees and uploads only the types HR lets employees see and upload.
 */
final class Employee360Folders
{
    public function __construct(
        private readonly Documents $documents,
        private readonly OptionLists $lists,
        private readonly EntityApplicability $applicability,
        private readonly ProfileSectionVisibility $matrix,
    ) {}

    public function isOwn(User $viewer, Employee $employee): bool
    {
        return $employee->user_id !== null && (int) $employee->user_id === (int) $viewer->id;
    }

    /** The same gate as the 360 Documents card: document readers, or the person with document.own; the profile-section matrix can only hide it. */
    public function maySeeDocuments(User $viewer, Employee $employee): bool
    {
        return ($viewer->hasPermission('document.view') || ($this->isOwn($viewer, $employee) && $viewer->hasPermission('document.own')))
            && $viewer->can('view', $employee)
            && $this->matrix->allows($viewer, $employee, 'documents');
    }

    /** HR uploads any type the company uses; the employee (document.own only) the types employees may upload. */
    public function uploadsAsHr(User $viewer): bool
    {
        return $viewer->hasPermission('document.upload');
    }

    /**
     * Active document types the employee's company uses (configuration applicability). Someone who is not a document
     * reader (the employee on their own record) sees only the types employees may see.
     *
     * @return Collection<int, DocumentType>
     */
    public function types(User $viewer, Employee $employee): Collection
    {
        if (! $this->maySeeDocuments($viewer, $employee)) {
            return collect();
        }
        $company = NumberSeries::companyOf($employee);

        return DocumentType::query()->where('status', 'active')
            ->when($company, fn ($q, $id) => $this->applicability->constrain($q, $id))
            ->when(! $viewer->hasPermission('document.view'), fn ($q) => $q->where('employee_can_view', true))
            ->orderBy('name')->get()->toBase();
    }

    /** @return Collection<int, DocumentType> the types this viewer may upload for this employee here */
    public function uploadableTypes(User $viewer, Employee $employee): Collection
    {
        $types = $this->types($viewer, $employee);
        if ($this->uploadsAsHr($viewer)) {
            return $types;
        }
        if ($this->isOwn($viewer, $employee) && $viewer->hasPermission('document.own')) {
            return $types->filter(fn (DocumentType $type) => (bool) $type->employee_can_upload)->values();
        }

        return collect();
    }

    /** @var array<string, string>|null */
    private ?array $categories = null;

    /**
     * @return array<string, string> category code => label: the organisation's current order, then hidden categories that
     *                               types still carry, then "other" for types of no or an unknown category
     */
    public function categories(): array
    {
        return $this->categories ??= $this->lists->options('document_categories') + $this->lists->labels('document_categories') + ['other' => 'Other'];
    }

    public function categoryOf(?DocumentType $type): string
    {
        $code = (string) $type?->category;

        return $code !== '' && array_key_exists($code, $this->categories()) ? $code : 'other';
    }

    /**
     * The documents folder: categories → document types → the current documents of each (or "not uploaded").
     *
     * @return array{summary: array{documents: int, missing: int, expiring: int}, upload: bool, categories: list<array<string, mixed>>}
     */
    public function documents(User $viewer, Employee $employee): array
    {
        $empty = ['summary' => ['documents' => 0, 'missing' => 0, 'expiring' => 0], 'upload' => false, 'categories' => []];
        if (! $this->maySeeDocuments($viewer, $employee)) {
            return $empty;
        }
        $types = $this->types($viewer, $employee);
        $uploadable = $this->uploadableTypes($viewer, $employee)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $documents = EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->with('type')
            ->where('employee_id', $employee->getKey())->where('status', '!=', 'archived')
            ->orderByDesc('version')->orderByDesc('id')->get()
            ->filter(fn (EmployeeDocument $d) => $viewer->can('view', $d))->values();

        $soon = now()->addDays(30)->toDateString();
        $row = fn (EmployeeDocument $d) => [
            'id' => $d->id,
            'title' => (string) $d->title,
            'file' => $d->original_name,
            'version' => (int) $d->version,
            'status' => EmployeeDocument::STATUSES[$d->status] ?? ucfirst((string) $d->status),
            'tone' => match ($d->status) {
                'verified' => 'success', 'rejected' => 'danger', default => 'warning'
            },
            'note' => $d->status === 'rejected' ? $d->review_note : null,
            'uploaded' => $d->created_at?->format('j M Y'),
            'expires' => $d->expires_on?->format('j M Y'),
            'expired' => $d->isExpired(),
            'expiring' => ! $d->isExpired() && $d->expires_on !== null && $d->expires_on->toDateString() <= $soon,
            'url' => $this->documents->downloadUrl($d),
        ];
        $typeRow = fn (?DocumentType $type, Collection $docs) => [
            'id' => $type?->id,
            'name' => $type?->name ?? 'No document type',
            'mandatory' => (bool) $type?->mandatory_for_employee,
            'missing' => $type !== null && (bool) $type->mandatory_for_employee && $docs->whereIn('status', DocumentRules::CURRENT)->isEmpty(),
            'upload' => $type !== null && in_array((int) $type->id, $uploadable, true),
            'documents' => $docs->map($row)->values()->all(),
        ];

        $byCategory = [];
        foreach ($types as $type) {
            $byCategory[$this->categoryOf($type)][] = $typeRow($type, $documents->where('document_type_id', $type->id));
        }
        // Documents of a type no longer listed (inactive, or not used by the company) or of no type: under their category.
        $listed = $types->pluck('id')->all();
        $others = $documents->reject(fn (EmployeeDocument $d) => $d->document_type_id !== null && in_array($d->document_type_id, $listed));
        foreach ($others->groupBy(fn (EmployeeDocument $d) => (string) $d->document_type_id) as $docs) {
            $byCategory[$this->categoryOf($docs->first()->type)][] = $typeRow($docs->first()->type, $docs);
        }

        $categories = [];
        foreach ($this->categories() as $code => $label) {
            if (! isset($byCategory[$code])) {
                continue;
            }
            $rows = $byCategory[$code];
            $categories[] = [
                'key' => $code,
                'label' => $label,
                'types' => $rows,
                'count' => array_sum(array_map(fn ($t) => count($t['documents']), $rows)),
                'with' => count(array_filter($rows, fn ($t) => $t['documents'] !== [])),
                'missing' => count(array_filter($rows, fn ($t) => $t['missing'])),
                'upload' => in_array(true, array_column($rows, 'upload'), true),
            ];
        }

        return [
            'summary' => [
                'documents' => $documents->count(),
                'missing' => array_sum(array_column($categories, 'missing')),
                'expiring' => $documents->filter(fn (EmployeeDocument $d) => $d->expires_on !== null && ! $d->isExpired() && $d->expires_on->toDateString() <= $soon)->count(),
            ],
            'upload' => $uploadable !== [],
            'categories' => $categories,
        ];
    }

    /** The same gate as the 360 Letters card and the Letters record. */
    public function maySeeLetters(User $viewer, Employee $employee): bool
    {
        return ($this->readsLetters($viewer) || $this->isOwn($viewer, $employee)) && $viewer->can('view', $employee);
    }

    public function readsLetters(User $viewer): bool
    {
        return $viewer->hasPermission('letter.view') || $viewer->hasPermission('letter.issue') || $viewer->hasPermission('letter.manage');
    }

    /** HR files a letter made outside PeopleOS (a signed offer letter, an old appointment letter) against its letter type. */
    public function uploadsLetters(User $viewer, Employee $employee): bool
    {
        return $viewer->hasPermission('document.upload') && $this->maySeeLetters($viewer, $employee);
    }

    /** @return array<string, string> letter type code => its name as a letter ("Offer letter", "Salary certificate") */
    public function letterTypes(): array
    {
        return array_map(fn (string $label) => self::letterName($label), (array) config('peopleos.letters.types', []));
    }

    /** "Offer" → "Offer letter"; a name that already says what it is ("Salary certificate", "PIP letter") stays as it is. */
    public static function letterName(string $label): string
    {
        return preg_match('/\b(letter|certificate)\b/i', $label) === 1 ? $label : "{$label} letter";
    }

    /**
     * Active letter templates the employee's company uses, for someone who issues letters.
     *
     * @return Collection<int, LetterTemplate>
     */
    public function templates(User $viewer, Employee $employee): Collection
    {
        if (! $viewer->hasPermission('letter.issue') || ! $this->maySeeLetters($viewer, $employee)) {
            return collect();
        }
        $company = NumberSeries::companyOf($employee);

        return LetterTemplate::query()->where('status', 'active')
            ->when($company, fn ($q, $id) => $this->applicability->constrain($q, $id))
            ->orderBy('name')->get()->toBase();
    }

    /**
     * The letters folder: every letter type by name (Offer letter, Appointment letter, Appraisal letter, …) with this
     * person's letters of that type — generated ones (the employee sees issued ones only) and files uploaded against the
     * type (kept as documents of the type's document type, Letters::documentType). Someone who issues letters generates
     * one of a type that has a template; someone who uploads documents uploads a file against any type. 'staff' says the
     * viewer works with letters (the Letters screen opens the letter for them).
     *
     * @return array{summary: array{letters: int, issued: int, pending: int}, generate: bool, upload: bool, staff: bool, types: list<array<string, mixed>>}
     */
    public function letters(User $viewer, Employee $employee): array
    {
        $empty = ['summary' => ['letters' => 0, 'issued' => 0, 'pending' => 0], 'generate' => false, 'upload' => false, 'staff' => false, 'types' => []];
        if (! $this->maySeeLetters($viewer, $employee)) {
            return $empty;
        }
        $staff = $this->readsLetters($viewer);
        $upload = $this->uploadsLetters($viewer, $employee);
        $letters = Letter::query()->withoutGlobalScope(AccessScope::class)->with(['template:id,name', 'document' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)])
            ->where('employee_id', $employee->getKey())
            ->when(! $staff, fn ($q) => $q->where('status', 'issued'))
            ->latest('id')->get()
            ->filter(fn (Letter $l) => $viewer->can('view', $l))->values();
        // Files on the letter types' document types that no letter of PeopleOS made: the uploaded ones.
        $issuedDocuments = Letter::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->whereNotNull('document_id')->pluck('document_id')->all();
        $uploaded = EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->with('type')
            ->where('employee_id', $employee->getKey())->where('status', '!=', 'archived')->whereNotIn('id', $issuedDocuments)
            ->whereHas('type', fn ($q) => $q->where('code', 'like', 'LETTER_%'))
            ->latest('id')->get()
            ->filter(fn (EmployeeDocument $d) => str_starts_with((string) $d->type?->code, 'LETTER_') && $viewer->can('view', $d))->values();
        $statuses = (array) config('peopleos.letters.statuses', []);
        // A letter or template of no type is a custom one.
        $typeOf = fn (Letter|LetterTemplate $item) => filled($item->type) ? (string) $item->type : 'custom';
        $uploadTypeOf = fn (EmployeeDocument $d) => strtolower(substr((string) $d->type?->code, strlen('LETTER_')));
        $templates = $this->templates($viewer, $employee)->groupBy($typeOf);
        $row = fn (Letter $l) => [
            'id' => $l->id,
            'kind' => 'letter',
            'number' => (string) $l->number,
            'template' => $l->template?->name,
            'status' => $statuses[$l->status] ?? ucfirst(str_replace('_', ' ', (string) $l->status)),
            'tone' => match ($l->status) {
                'issued' => 'success', 'rejected', 'revoked' => 'danger', 'draft' => 'neutral', default => 'warning'
            },
            'date' => ($l->issued_at ?? $l->created_at)?->format('j M Y'),
            'dated' => $l->issued_at !== null ? 'Issued' : 'Created',
            'download' => $l->status === 'issued' && $l->document !== null && $viewer->can('view', $l->document) ? $this->documents->downloadUrl($l->document) : null,
        ];
        $fileRow = fn (EmployeeDocument $d) => [
            'id' => $d->id,
            'kind' => 'upload',
            'number' => (string) $d->title,
            'template' => $d->original_name,
            'status' => EmployeeDocument::STATUSES[$d->status] ?? ucfirst((string) $d->status),
            'tone' => match ($d->status) {
                'verified' => 'success', 'rejected' => 'danger', default => 'warning'
            },
            'date' => ($d->issued_on ?? $d->created_at)?->format('j M Y'),
            'dated' => $d->issued_on !== null ? 'Dated' : 'Uploaded',
            'download' => $this->documents->downloadUrl($d),
        ];

        $names = $this->letterTypes();
        $groups = [];
        foreach (array_unique([...array_keys($names), ...$letters->map($typeOf)->all(), ...$templates->keys()->all(), ...$uploaded->map($uploadTypeOf)->all()]) as $type) {
            $type = (string) $type;
            $usable = $templates->get($type, collect());
            $items = [
                ...$letters->filter(fn (Letter $l) => $typeOf($l) === $type)->map($row)->all(),
                ...$uploaded->filter(fn (EmployeeDocument $d) => $uploadTypeOf($d) === $type)->map($fileRow)->all(),
            ];
            // Someone who can neither generate nor upload (the employee on their own record) sees the types they have letters of.
            if ($items === [] && ! $staff && ! $upload) {
                continue;
            }
            $groups[] = [
                'key' => $type,
                'label' => $names[$type] ?? self::letterName(ucfirst(str_replace('_', ' ', $type))),
                'letters' => array_values($items),
                'templates' => $usable->count(),
                'generate' => $usable->isNotEmpty(),
                'upload' => $upload,
            ];
        }
        // The types with something on file first (in the configured order), then the rest.
        usort($groups, fn (array $a, array $b) => ($a['letters'] === []) <=> ($b['letters'] === []));

        return [
            'summary' => [
                'letters' => $letters->count() + $uploaded->count(),
                'issued' => $letters->where('status', 'issued')->count(),
                'pending' => $letters->whereIn('status', ['pending_approval', 'approved'])->count(),
            ],
            'generate' => $templates->isNotEmpty(),
            'upload' => $upload,
            'staff' => $staff,
            'types' => $groups,
        ];
    }
}
