<?php

namespace App\Domain\Letters\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\HtmlLetter;
use App\Domain\Letters\Support\LetterVariables;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
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
    public function context(Employee $employee, array $extra = [], ?LetterSignatory $signatory = null): array
    {
        return $this->contexts->build($employee, $extra, $signatory);
    }

    /** Where a tenant's letter files live (uploaded templates, signatures, rendered Word letters). */
    public static function directory(string $kind): string
    {
        return 'tenants/'.app(TenantContext::class)->id()."/letters/{$kind}";
    }

    public function generate(LetterTemplate|string $template, Employee $employee, array $extra = [], ?User $requester = null, ?Model $source = null, ?LetterSignatory $signatory = null): Letter
    {
        $template = $template instanceof LetterTemplate ? $template : LetterTemplate::query()->where('status', 'active')->where(fn ($q) => $q->where('code', strtoupper($template))->orWhere('type', $template))->orderBy('id')->first();
        if ($template === null) {
            throw new RuntimeException('No active letter template of that type.');
        }
        $signatory ??= $template->signatory()->where('status', ActiveStatus::Active)->first();

        $file = null;
        try {
            return DB::transaction(function () use ($template, $employee, $extra, $requester, $source, $signatory, &$file) {
                $number = $this->nextNumber();
                $context = $this->contexts->build($employee, $extra + ['letter' => ['number' => $number, 'date' => now()]], $signatory);
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
                ]);

                $this->audit->record(AuditAction::Create, 'letters', $letter, [], null, actor: $requester, metadata: ['type' => $template->type, 'template' => $template->code, 'format' => $template->format]);
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
        $context = $this->contexts->build($employee, $extra + ['letter' => ['number' => 'PREVIEW', 'date' => now()]], $signatory);
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

            return $letter->refresh();
        });
    }

    /**
     * Phase 14: the issued letter as a download, audited (who downloaded which letter).
     *
     * @return array{content: string, name: string, mime: string}
     */
    public function download(Letter $letter, User $user): array
    {
        if ($letter->status !== 'issued') {
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

    private function page(string $company, string $number, string $subject, string $body, ?string $date = null): string
    {
        $company = e($company);
        $number = e($number);

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$number}</title><style>body{font-family:Georgia,serif;max-width:720px;margin:40px auto;line-height:1.5;color:#111}header{border-bottom:2px solid #111;margin-bottom:24px;padding-bottom:8px}footer{margin-top:48px;font-size:12px;color:#555}table{border-collapse:collapse}td,th{vertical-align:top}img.signature{display:block;margin:8px 0}@media print{body{margin:0 auto}}</style></head><body><header><strong>{$company}</strong><div style=\"float:right\">{$number} · ".e($date ?? now()->format('d M Y')).'</div></header><h2>'.e($subject)."</h2>{$body}<footer>This letter was generated by PeopleOS and can be verified against reference {$number}.</footer></body></html>";
    }

    public function nextNumber(): string
    {
        $prefix = config('peopleos.letters.number_prefix', 'LTR');
        $year = now()->format('Y');
        $last = Letter::query()->where('number', 'like', "{$prefix}-{$year}-%")->orderByDesc('id')->value('number');

        return sprintf('%s-%s-%05d', $prefix, $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }
}
