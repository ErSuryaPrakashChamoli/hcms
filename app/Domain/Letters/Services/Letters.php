<?php

namespace App\Domain\Letters\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Notifications\Services\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** The letter factory (§40): render a template for an employee, approve if required, issue as a stored document. */
final class Letters
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly Documents $documents, private readonly CompensationOutput $compensation, private readonly AuditRecorder $audit) {}

    /** @return array<string, mixed> the variables a template may reference */
    public function context(Employee $employee, array $extra = []): array
    {
        $employee->loadMissing(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.company', 'currentPosition.location']);
        $position = $employee->currentPosition ?? $employee->positions()->orderByDesc('effective_from')->with(['designation', 'department', 'company', 'location'])->first();
        $salary = $this->compensation->on($employee, $employee->exit_date ?? now());

        return array_replace_recursive([
            'employee' => [
                'name' => $employee->person?->full_name, 'first_name' => $employee->person?->first_name, 'code' => $employee->employee_code,
                'designation' => $position?->designation?->name, 'department' => $position?->department?->name, 'location' => $position?->location?->name,
                'joining_date' => $employee->joining_date, 'exit_date' => $employee->exit_date, 'gender' => $employee->person?->gender,
                'ctc_annual' => $salary ? number_format($salary->ctcAnnual, 2) : null, 'ctc_monthly' => $salary ? number_format($salary->monthlyCtc(), 2) : null,
                'work_email' => $employee->work_email,
            ],
            'company' => ['name' => $position?->company?->legal_name ?: $position?->company?->name, 'short_name' => $position?->company?->name],
            'today' => now(),
            'letter' => ['date' => now()],
        ], $extra);
    }

    public function generate(LetterTemplate|string $template, Employee $employee, array $extra = [], ?User $requester = null, ?Model $source = null): Letter
    {
        $template = $template instanceof LetterTemplate ? $template : LetterTemplate::query()->where('status', 'active')->where(fn ($q) => $q->where('code', strtoupper($template))->orWhere('type', $template))->orderBy('id')->first();
        if ($template === null) {
            throw new RuntimeException('No active letter template of that type.');
        }

        return DB::transaction(function () use ($template, $employee, $extra, $requester, $source) {
            $number = $this->nextNumber();
            $context = $this->context($employee, $extra + ['letter' => ['number' => $number, 'date' => now()]]);
            $letter = Letter::create([
                'number' => $number,
                'employee_id' => $employee->id,
                'letter_template_id' => $template->id,
                'type' => $template->type,
                'subject' => $this->renderer->render($template->subject, $context),
                'body' => $this->renderer->render($template->body, $context),
                'context' => ['template_version' => $template->version] + collect($context)->except('today')->map(fn ($v) => is_array($v) ? array_map(fn ($x) => $x instanceof \DateTimeInterface ? $x->format('Y-m-d') : $x, $v) : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v))->all(),
                'status' => $template->requires_approval ? 'pending_approval' : 'approved',
                'requested_by' => $requester?->id ?? auth()->id(),
                'approved_by' => $template->requires_approval ? null : ($requester?->id ?? auth()->id()),
                'approved_at' => $template->requires_approval ? null : now(),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);

            $this->audit->record(AuditAction::Create, 'letters', $letter, [], null, actor: $requester, metadata: ['type' => $template->type, 'template' => $template->code]);
            if ($template->requires_approval) {
                ExitEvent::dispatch('letter.requested', $employee, $letter, ['number' => $number, 'type' => config("peopleos.letters.types.{$template->type}")], User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('letter.issue'))->pluck('id')->all());
            }

            return $letter;
        });
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

    /** Freezes the letter as an HTML document in the employee's document store. */
    public function issue(Letter $letter, ?User $issuer = null): Letter
    {
        if ($letter->status !== 'approved') {
            throw new RuntimeException('Only an approved letter can be issued.');
        }

        return DB::transaction(function () use ($letter, $issuer) {
            $employee = $letter->employee()->firstOrFail();
            $html = $this->html($letter);
            $tmp = tempnam(sys_get_temp_dir(), 'letter');
            file_put_contents($tmp, $html);
            $file = new UploadedFile($tmp, Str::slug($letter->number).'.html', 'text/html', null, true);
            $type = DocumentType::query()->firstOrCreate(['code' => 'LETTER_'.strtoupper($letter->type)], ['name' => config("peopleos.letters.types.{$letter->type}", ucfirst($letter->type)).' letter', 'category' => 'company', 'requires_expiry' => false, 'mandatory_for_onboarding' => false]);
            $document = $this->documents->store($employee, $file, $type, $letter->subject, null, now()->toDateString(), 'Issued letter '.$letter->number);
            @unlink($tmp);

            $letter->update(['status' => 'issued', 'issued_by' => $issuer?->id ?? auth()->id(), 'issued_at' => now(), 'document_id' => $document->id]);
            $this->audit->record(AuditAction::Update, 'letters', $letter, [['field' => 'status', 'before' => 'approved', 'after' => 'issued']], null, actor: $issuer, metadata: ['document_id' => $document->id]);
            ExitEvent::dispatch('letter.issued', $employee, $letter, ['number' => $letter->number, 'subject' => $letter->subject], array_filter([$employee->user_id]));

            return $letter->refresh();
        });
    }

    /** Phase 14: the issued letter as a download, audited (who downloaded which letter). */
    public function download(Letter $letter, User $user): string
    {
        if ($letter->status !== 'issued') {
            throw new RuntimeException('Only an issued letter can be downloaded.');
        }
        $this->audit->record(AuditAction::Download, 'letters', $letter, [], null, actor: $user, metadata: ['number' => $letter->number]);

        return $this->html($letter);
    }

    public function html(Letter $letter): string
    {
        $company = e($letter->context['company']['name'] ?? '');
        $body = Str::markdown($letter->body);

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$letter->number}</title><style>body{font-family:Georgia,serif;max-width:720px;margin:40px auto;line-height:1.5;color:#111}header{border-bottom:2px solid #111;margin-bottom:24px;padding-bottom:8px}footer{margin-top:48px;font-size:12px;color:#555}</style></head><body><header><strong>{$company}</strong><div style=\"float:right\">{$letter->number} · ".now()->format('d M Y').'</div></header><h2>'.e($letter->subject)."</h2>{$body}<footer>This letter was generated by PeopleOS and is valid without a signature when verified against reference {$letter->number}.</footer></body></html>";
    }

    public function nextNumber(): string
    {
        $prefix = config('peopleos.letters.number_prefix', 'LTR');
        $year = now()->format('Y');
        $last = Letter::query()->where('number', 'like', "{$prefix}-{$year}-%")->orderByDesc('id')->value('number');

        return sprintf('%s-%s-%05d', $prefix, $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }
}
