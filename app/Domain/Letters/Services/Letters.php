<?php

namespace App\Domain\Letters\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\HtmlLetter;
use App\Domain\Letters\Support\LetterVariables;
use App\Domain\Letters\Support\SalaryAnnexure;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The letter factory (§40): render a template for an employee, approve if required, issue as a stored document.
 * A template is html (rich-text editor) or docx (the client's own Word file, filled and kept as a .docx); variables
 * are LetterVariables, filled by LetterContext.
 */
final class Letters
{
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private readonly TemplateRenderer $renderer, private readonly Documents $documents, private readonly LetterContext $contexts, private readonly AuditRecorder $audit) {}

    /** @return array<string, mixed> the variables a template may reference */
    public function context(Employee $employee, array $extra = [], ?LetterSignatory $signatory = null, array $others = []): array
    {
        return $this->contexts->build($employee, $extra, $signatory, $others);
    }

    /** Where a tenant's letter files live (uploaded templates, signatures, rendered Word letters). */
    public static function directory(string $kind): string
    {
        return 'tenants/'.app(TenantContext::class)->id()."/letters/{$kind}";
    }

    /**
     * @param  array<int, LetterSignatory>|null  $others  HROne parity WO-13: the second, third and fourth signatories by position
     *                                                    (2–4); null = the template's
     * @param  string|null  $process  WO-13: what drafted the letter (a policy hook such as confirmation.letter_on_extend, or schedule)
     * @param  Letter|null  $supersedes  WO-13 (D-WO6): the letter this one replaces (use supersede())
     */
    public function generate(LetterTemplate|string $template, Employee $employee, array $extra = [], ?User $requester = null, ?Model $source = null, ?LetterSignatory $signatory = null,
        ?array $others = null, ?string $process = null, ?Letter $supersedes = null, ?LetterSchedule $schedule = null): Letter
    {
        $template = $template instanceof LetterTemplate ? $template : LetterTemplate::query()->where('status', 'active')->where(fn ($q) => $q->where('code', strtoupper($template))->orWhere('type', $template))->orderBy('id')->first();
        if ($template === null) {
            throw new RuntimeException('No active letter template of that type.');
        }
        $signatory ??= $template->signatory()->where('status', ActiveStatus::Active)->first();
        $others = array_slice(array_filter($others ?? $template->additionalSignatories(), fn ($s, $position) => $s instanceof LetterSignatory && in_array($position, [2, 3, 4], true), ARRAY_FILTER_USE_BOTH), 0, 3, true);

        $file = null;
        $company = NumberSeries::companyOf($employee);
        app(NumberSeries::class)->prepare(self::codeType($template->type), $company);
        try {
            return DB::transaction(function () use ($template, $employee, $extra, $requester, $source, $signatory, $others, $process, $supersedes, $schedule, $company, &$file) {
                $number = $this->nextNumber($template->type, $company);
                $context = $this->contexts->build($employee, $extra + ['letter' => ['number' => $number, 'date' => now()]], $signatory, $others);
                $rendered = $this->render($template, $context);
                if ($rendered['file'] !== null) {
                    $file = self::directory('issued').'/'.Str::ulid().'.docx';
                    Storage::disk(config('peopleos.documents.disk'))->put($file, $rendered['file']);
                }
                $letter = Letter::create([
                    'number' => $number,
                    'employee_id' => $employee->id,
                    'letter_template_id' => $template->id,
                    'letter_signatory_id' => $signatory?->id ?? LetterSignatory::default()?->id,
                    'letter_signatory_2_id' => ($others[2] ?? null)?->id,
                    'letter_signatory_3_id' => ($others[3] ?? null)?->id,
                    'letter_signatory_4_id' => ($others[4] ?? null)?->id,
                    'type' => $template->type,
                    'format' => $template->format,
                    'subject' => $rendered['subject'],
                    'body' => $rendered['body'],
                    'file_disk' => $file ? config('peopleos.documents.disk') : null,
                    'file_path' => $file,
                    'context' => ['template_version' => $template->version] + LetterContext::snapshot($context, $rendered['variables']),
                    'status' => $template->requires_approval ? 'pending_approval' : 'approved',
                    'requested_by' => $requester?->id ?? auth()->id(),
                    'approved_by' => $template->requires_approval ? null : ($requester?->id ?? auth()->id()),
                    'approved_at' => $template->requires_approval ? null : now(),
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'process' => $process !== null ? mb_substr($process, 0, 64) : null,
                    'supersedes_letter_id' => $supersedes?->id,
                    'letter_schedule_id' => $schedule?->id,
                ]);

                $this->audit->record(AuditAction::Create, 'letters', $letter, [], null, actor: $requester, metadata: array_filter(['type' => $template->type, 'template' => $template->code, 'format' => $template->format,
                    'process' => $letter->process, 'supersedes' => $supersedes?->number, 'signatories' => count($letter->signatoryIds())]));
                if ($template->requires_approval) {
                    ExitEvent::dispatch('letter.requested', $employee, $letter, ['number' => $number, 'type' => config("peopleos.letters.types.{$template->type}")], User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('letter.issue'))->pluck('id')->all());
                }

                return $letter;
            });
        } catch (Throwable $e) {
            if ($file !== null) {
                Storage::disk(config('peopleos.documents.disk'))->delete($file);
            }
            throw $e;
        }
    }

    /**
     * A template filled for an employee without generating a letter, to check a template before using it. Audited, as
     * it shows the employee's details: once per user, template and employee in ten minutes (a preview re-renders as
     * its form changes).
     *
     * @return array{content: string, name: string, mime: string, text: string}
     */
    public function preview(LetterTemplate $template, Employee $employee, User $user, array $extra = [], ?LetterSignatory $signatory = null): array
    {
        $signatory ??= $template->signatory()->where('status', ActiveStatus::Active)->first();
        $context = $this->contexts->build($employee, $extra + ['letter' => ['number' => 'PREVIEW', 'date' => now()]], $signatory, $template->additionalSignatories());
        $rendered = $this->render($template, $context);
        if (Cache::add("letters:preview:{$user->id}:{$template->id}:{$employee->id}", true, now()->addMinutes(10))) {
            $this->audit->record(AuditAction::View, 'letters', $template, [], null, actor: $user, metadata: ['preview_for_employee' => $employee->id]);
        }
        $name = Str::slug("{$template->code} {$employee->employee_code} preview");

        return $rendered['file'] !== null
            ? ['content' => $rendered['file'], 'name' => "{$name}.docx", 'mime' => self::DOCX_MIME, 'text' => $rendered['body']]
            : ['content' => $this->page($context['company']['name'] ?? '', 'PREVIEW', $rendered['subject'], $rendered['body']), 'name' => "{$name}.html", 'mime' => 'text/html', 'text' => $rendered['body']];
    }

    /**
     * Variables the template uses that the catalogue does not know: they print only when typed in at generation.
     *
     * @return array{used: list<string>, extras: list<string>}
     */
    public function check(LetterTemplate $template): array
    {
        $used = LetterVariables::usedIn($template->subject.' '.($template->isDocx() ? $this->templateText($template) : $template->body));

        return ['used' => $used, 'extras' => LetterVariables::extras($used)];
    }

    /** The uploaded Word template's text (kept in body for search and the check). */
    public function templateText(LetterTemplate $template): string
    {
        return $template->isDocx() && filled($template->file_path) ? $this->withTemplateFile($template, fn (string $path) => DocxLetter::text($path)) : (string) $template->body;
    }

    /** The uploaded Word template itself, to download and edit again. */
    public function templateFile(LetterTemplate $template): string
    {
        return $this->withTemplateFile($template, fn (string $path) => (string) file_get_contents($path));
    }

    /** @return array{subject: string, body: string, file: ?string, variables: list<string>} */
    private function render(LetterTemplate $template, array $context): array
    {
        $subject = $this->renderer->render($template->subject, $context);
        if ($template->isDocx()) {
            [$file, $variables] = $this->withTemplateFile($template, fn (string $path) => [DocxLetter::render($path, $context), DocxLetter::variables($path)]);
            $tmp = tempnam(sys_get_temp_dir(), 'letter');
            file_put_contents($tmp, $file);
            $body = DocxLetter::text($tmp);
            @unlink($tmp);

            return ['subject' => $subject, 'body' => $body, 'file' => $file, 'variables' => array_values(array_unique([...LetterVariables::usedIn($template->subject), ...$variables]))];
        }

        return [
            'subject' => $subject,
            'body' => $template->format === LetterTemplate::HTML ? HtmlLetter::render((string) $template->body, $context) : $this->renderer->render((string) $template->body, $context),
            'file' => null,
            'variables' => LetterVariables::usedIn($template->subject.' '.$template->body),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback  receives a local copy of the uploaded template
     * @return T
     */
    private function withTemplateFile(LetterTemplate $template, callable $callback): mixed
    {
        $disk = Storage::disk($template->file_disk ?: config('peopleos.documents.disk'));
        if (blank($template->file_path) || ! $disk->exists($template->file_path)) {
            throw new RuntimeException("The Word file of the template {$template->name} is missing. Upload it again.");
        }
        $tmp = tempnam(sys_get_temp_dir(), 'letter');
        file_put_contents($tmp, (string) $disk->get($template->file_path));
        try {
            return $callback($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function approve(Letter $letter, User $approver, ?string $note = null): Letter
    {
        if ($letter->status !== 'pending_approval') {
            throw new RuntimeException('The letter is not waiting for approval.');
        }
        // Phase 12: separation of duties — whoever asked for a letter does not approve it.
        if ($letter->requested_by !== null && (int) $letter->requested_by === (int) $approver->id) {
            throw new RuntimeException('The person who requested a letter cannot approve it.');
        }
        $letter->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'review_note' => $note]);
        $this->audit->record(AuditAction::Approved, 'letters', $letter, [], $note, actor: $approver);
        ExitEvent::dispatch('letter.approved', $letter->employee()->first(), $letter, ['number' => $letter->number], array_filter([$letter->requested_by]));

        return $letter;
    }

    public function reject(Letter $letter, User $approver, string $note): Letter
    {
        if (! in_array($letter->status, ['pending_approval', 'approved'], true)) {
            throw new RuntimeException('The letter cannot be rejected now.');
        }
        $letter->update(['status' => 'rejected', 'review_note' => $note]);
        $this->audit->record(AuditAction::Rejected, 'letters', $letter, [], $note, actor: $approver);

        return $letter;
    }

    /** Freezes the letter in the employee's document store: the page for a written letter, the filled Word file for a .docx one. */
    public function issue(Letter $letter, ?User $issuer = null): Letter
    {
        if ($letter->status !== 'approved') {
            throw new RuntimeException('Only an approved letter can be issued.');
        }

        return DB::transaction(function () use ($letter, $issuer) {
            $employee = $letter->employee()->firstOrFail();
            ['content' => $content, 'name' => $name, 'mime' => $mime] = $this->file($letter);
            $tmp = tempnam(sys_get_temp_dir(), 'letter');
            file_put_contents($tmp, $content);
            $file = new UploadedFile($tmp, $name, $mime, null, true);
            $type = DocumentType::query()->firstOrCreate(['code' => 'LETTER_'.strtoupper($letter->type)], ['name' => config("peopleos.letters.types.{$letter->type}", ucfirst($letter->type)).' letter', 'category' => 'company', 'requires_expiry' => false, 'mandatory_for_onboarding' => false]);
            $document = $this->documents->store($employee, $file, $type, $letter->subject, null, now()->toDateString(), 'Issued letter '.$letter->number, generatedBySystem: true);
            @unlink($tmp);

            $letter->update(['status' => 'issued', 'issued_by' => $issuer?->id ?? auth()->id(), 'issued_at' => now(), 'document_id' => $document->id]);
            $this->audit->record(AuditAction::Update, 'letters', $letter, [['field' => 'status', 'before' => 'approved', 'after' => 'issued']], null, actor: $issuer, metadata: ['document_id' => $document->id]);
            ExitEvent::dispatch('letter.issued', $employee, $letter, ['number' => $letter->number, 'subject' => $letter->subject], array_filter([$employee->user_id]));

            // HROne parity WO-13 (D-WO6): issuing a replacement revokes the letter it supersedes (kept, hidden from the employee).
            $old = $letter->supersedes_letter_id ? Letter::query()->lockForUpdate()->find($letter->supersedes_letter_id) : null;
            if ($old !== null && $old->status === 'issued') {
                $this->withdraw($old, $issuer, "Superseded by {$letter->number}", $letter);
            }

            return $letter->refresh();
        });
    }

    /**
     * HROne parity WO-13 ("Revoke" / "Unpublish", D-WO5): an issued letter is withdrawn from the employee — marked revoked
     * with the reason, its document archived so the employee no longer sees or opens it — and kept with its file. Nothing
     * is deleted. Needs letter.issue.
     */
    public function revoke(Letter $letter, User $actor, string $reason): Letter
    {
        self::mayChange($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Say why the letter is revoked (at most 500 characters).');
        }

        return DB::transaction(function () use ($letter, $actor, $reason) {
            $locked = Letter::query()->lockForUpdate()->findOrFail($letter->id);
            if ($locked->status !== 'issued') {
                throw new RuntimeException('Only an issued letter can be revoked; a letter not yet issued is rejected instead.');
            }

            return $this->withdraw($locked, $actor, $reason);
        });
    }

    /**
     * HROne parity WO-13 ("Re-generate", D-WO6): a new letter for the same employee and process, from the same template or
     * another, linked to this one — never an overwrite. A letter not yet issued is revoked at once; an issued one stays with
     * the employee until its replacement is issued (issue() revokes it then). With $issue, a replacement that needs no
     * approval is issued straight away. Needs letter.issue.
     */
    public function supersede(Letter $letter, User $actor, string $reason, ?LetterTemplate $template = null, bool $issue = false): Letter
    {
        self::mayChange($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Say why the letter is generated again (at most 500 characters).');
        }
        if (! in_array($letter->status, ['draft', 'pending_approval', 'approved', 'issued'], true)) {
            throw new RuntimeException('A rejected or revoked letter is not generated again; generate a new letter instead.');
        }
        $pending = $letter->replacement()->first();
        if ($pending !== null) {
            throw new RuntimeException("Letter {$pending->number} already replaces this letter.");
        }
        $template ??= $letter->template()->where('status', 'active')->first() ?? throw new RuntimeException('The template of this letter is no longer active: choose a template.');
        if ($template->status instanceof ActiveStatus ? $template->status !== ActiveStatus::Active : $template->status !== 'active') {
            throw new RuntimeException('Choose an active template.');
        }
        $employee = $letter->employee()->firstOrFail();
        $sameTemplate = (int) $template->id === (int) $letter->letter_template_id;
        $active = fn (?int $id) => $id ? LetterSignatory::query()->where('status', ActiveStatus::Active)->find($id) : null;
        $signatory = $sameTemplate ? $active($letter->letter_signatory_id) : null;
        $others = $sameTemplate ? array_filter([2 => $active($letter->letter_signatory_2_id), 3 => $active($letter->letter_signatory_3_id), 4 => $active($letter->letter_signatory_4_id)]) : null;

        $replacement = DB::transaction(function () use ($letter, $actor, $reason, $template, $employee, $signatory, $others) {
            $locked = Letter::query()->lockForUpdate()->findOrFail($letter->id);
            if (! in_array($locked->status, ['draft', 'pending_approval', 'approved', 'issued'], true) || $locked->replacement()->exists()) {
                throw new RuntimeException('This letter changed meanwhile; open it again.');
            }
            $new = $this->generate($template, $employee, self::extrasOf($locked), $actor, $locked->source_type ? $locked->source()->first() : null, $signatory, $others,
                $locked->process, $locked, $locked->letter_schedule_id ? LetterSchedule::query()->find($locked->letter_schedule_id) : null);
            $this->audit->record(AuditAction::LetterSuperseded, 'letters', $locked, [], $reason, actor: $actor, metadata: ['replacement' => $new->number, 'template' => $template->code, 'was' => $locked->status]);
            if ($locked->status !== 'issued') {
                $this->withdraw($locked, $actor, "Superseded by {$new->number}", $new);
            }

            return $new;
        });

        return $issue && $replacement->status === 'approved' ? $this->issue($replacement, $actor) : $replacement;
    }

    /** Marks a letter revoked and, when it reached the employee, archives its document (kept, hidden from them). */
    private function withdraw(Letter $letter, ?User $actor, string $reason, ?Letter $replacement = null): Letter
    {
        $was = $letter->status;
        if ($letter->document_id !== null && ($document = EmployeeDocument::query()->withoutGlobalScope(AccessScope::class)->find($letter->document_id)) !== null && $document->status !== 'archived') {
            $document->withAuditReason("Letter {$letter->number} revoked")->update(['status' => 'archived']);
        }
        $letter->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor?->id ?? auth()->id(), 'revoke_reason' => mb_substr($reason, 0, 500)])->saveQuietly();
        $this->audit->record(AuditAction::LetterRevoked, 'letters', $letter, [['field' => 'status', 'before' => $was, 'after' => 'revoked']], $reason, actor: $actor,
            metadata: array_filter(['document_id' => $letter->document_id, 'replacement' => $replacement?->number]));

        return $letter;
    }

    /** Revoking and generating again are for people who issue letters (fail closed). */
    private static function mayChange(User $actor): void
    {
        if (! $actor->hasPermission('letter.issue')) {
            throw new RuntimeException('You cannot change issued letters.');
        }
    }

    /**
     * The extra values typed in when a letter was generated (variables outside the catalogue, e.g. purpose), from its kept
     * context, so a letter generated again says the same.
     *
     * @return array<string, mixed>
     */
    public static function extrasOf(Letter $letter): array
    {
        $flat = Arr::dot(Arr::except((array) ($letter->context ?? []), ['company', 'letter', 'template_version']));
        $known = array_flip(LetterVariables::known());
        $extras = array_filter($flat, fn ($value, string $path) => ! isset($known[$path]) && ! str_starts_with($path, 'salary.') && ! str_starts_with($path, 'custom.') && is_scalar($value), ARRAY_FILTER_USE_BOTH);

        return Arr::undot($extras);
    }

    /**
     * Phase 14: the issued letter as a download, audited (who downloaded which letter).
     *
     * @return array{content: string, name: string, mime: string}
     */
    public function download(Letter $letter, User $user): array
    {
        // WO-13: a revoked letter is kept with its file; people who work with letters may still download it.
        $readsLetters = $user->hasPermission('letter.view') || $user->hasPermission('letter.issue') || $user->hasPermission('letter.manage');
        if ($letter->status !== 'issued' && ! ($letter->status === 'revoked' && $readsLetters)) {
            throw new RuntimeException('Only an issued letter can be downloaded.');
        }
        $this->audit->record(AuditAction::Download, 'letters', $letter, [], null, actor: $user, metadata: ['number' => $letter->number]);

        return $this->file($letter);
    }

    /**
     * The letter before it is issued, as it will be issued, for whoever approves or issues it (audited).
     *
     * @return array{content: string, name: string, mime: string}
     */
    public function draft(Letter $letter, User $user): array
    {
        if (! in_array($letter->status, ['pending_approval', 'approved'], true)) {
            throw new RuntimeException('Only a letter waiting for approval or issue has a draft.');
        }
        $this->audit->record(AuditAction::View, 'letters', $letter, [], null, actor: $user, metadata: ['number' => $letter->number, 'draft' => true]);

        return $this->file($letter);
    }

    /** @return array{content: string, name: string, mime: string} */
    public function file(Letter $letter): array
    {
        if (! $letter->isDocx()) {
            return ['content' => $this->html($letter), 'name' => Str::slug($letter->number).'.html', 'mime' => 'text/html'];
        }
        $disk = Storage::disk($letter->file_disk ?: config('peopleos.documents.disk'));
        if (blank($letter->file_path) || ! $disk->exists($letter->file_path)) {
            throw new RuntimeException('The Word file of this letter is missing.');
        }

        return ['content' => (string) $disk->get($letter->file_path), 'name' => Str::slug($letter->number).'.docx', 'mime' => self::DOCX_MIME];
    }

    public function html(Letter $letter): string
    {
        $body = match ($letter->format) {
            LetterTemplate::HTML => $letter->body,
            LetterTemplate::DOCX => '<div style="white-space:pre-wrap">'.e($letter->body).'</div>',
            default => Str::markdown($letter->body),
        };

        return $this->page($letter->context['company']['name'] ?? '', $letter->number, $letter->subject, $body, $letter->created_at?->format('d M Y'));
    }

    /**
     * HROne parity PO-2 (matrix 25 §25.2.2 #21, "Print Salary"): the salary annexure of a proposed (or any) compensation as a
     * printable page in the letter studio's layout — the same annexure {{ salary.annexure }} prints, no template. Audited
     * as an export of compensation (who printed whose breakup, never the amounts).
     *
     * @return array{content: string, name: string, mime: string}
     */
    public function breakup(Employee $employee, CompensationSegment $segment, User $actor, ?string $reference = null, bool $proposal = true): array
    {
        $employee->loadMissing(['person', 'currentPosition.company']);
        $on = $segment->from->copy()->startOfDay();
        $salary = $this->contexts->salaryFor($employee, $segment, $on);
        $annexure = $salary['annexure'] ?? null;
        if (! $annexure instanceof SalaryAnnexure) {
            throw new RuntimeException('The breakup cannot be printed: the structure has no approved components on '.$on->format('j M Y').'.');
        }
        $company = $employee->currentPosition?->company;
        $name = (string) ($employee->person?->full_name ?? $employee->employee_code);
        $facts = collect([
            'Employee' => $name.' ('.$employee->employee_code.')',
            'Salary structure' => $segment->compensation->structureCode,
            'Effective from' => $on->format('j M Y'),
            'Annual CTC' => $segment->compensation->currency.' '.number_format($segment->compensation->ctcAnnual, 2),
        ])->filter()->map(fn ($value, $label) => '<tr><td style="padding:2px 12px 2px 0;color:#555">'.e($label).'</td><td>'.e($value).'</td></tr>')->implode('');
        $note = $proposal ? '<p style="font-size:12px;color:#555">Proposed salary, not yet approved: it applies only after review, approval and execution. Statutory deductions and income tax are worked out by payroll.</p>' : '';
        $body = '<table style="margin-bottom:12px">'.$facts.'</table>'.$annexure->toHtml().$note;

        $this->audit->record(AuditAction::Export, 'compensation', null, [], null, actor: $actor, entityLabel: 'Salary breakup · '.$employee->employee_code, metadata: [
            'export' => 'salary_breakup', 'employee_id' => $employee->id, 'reference' => $reference, 'proposal' => $proposal, 'effective_from' => $on->toDateString(),
        ]);

        return [
            'content' => $this->page((string) ($company?->legal_name ?: $company?->name ?: ''), $reference ?? 'Salary breakup', 'Salary breakup · '.$name, $body, now()->format('d M Y')),
            'name' => Str::slug('salary-breakup-'.$employee->employee_code.'-'.$on->format('Y-m-d')).'.html',
            'mime' => 'text/html',
        ];
    }

    private function page(string $company, string $number, string $subject, string $body, ?string $date = null): string
    {
        $company = e($company);
        $number = e($number);

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$number}</title><style>body{font-family:Georgia,serif;max-width:720px;margin:40px auto;line-height:1.5;color:#111}header{border-bottom:2px solid #111;margin-bottom:24px;padding-bottom:8px}footer{margin-top:48px;font-size:12px;color:#555}table{border-collapse:collapse}td,th{vertical-align:top}img.signature{display:block;margin:8px 0}@media print{body{margin:0 auto}}</style></head><body><header><strong>{$company}</strong><div style=\"float:right\">{$number} · ".e($date ?? now()->format('d M Y')).'</div></header><h2>'.e($subject)."</h2>{$body}<footer>This letter was generated by PeopleOS and can be verified against reference {$number}.</footer></body></html>";
    }

    /**
     * HROne setup parity (Code series): a letter's number comes from its type's code series (`letter.<type>`), else the
     * letter series, else the built-in {number_prefix}-YYYY-NNNNN, counted under a lock so two letters never share one.
     */
    public function nextNumber(?string $type = null, ?int $companyId = null): string
    {
        return app(NumberSeries::class)->next(self::codeType($type), $companyId);
    }

    private static function codeType(?string $type): string
    {
        return $type !== null && array_key_exists("letter.{$type}", NumberSeries::types()) ? "letter.{$type}" : 'letter';
    }
}
