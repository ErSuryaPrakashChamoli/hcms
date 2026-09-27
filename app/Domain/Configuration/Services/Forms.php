<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Events\FormSubmitted;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Models\FormVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Form lifecycle (§42): draft -> publish (immutable) -> collect -> validate -> approve. */
final class Forms
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** Ensure the form has an editable draft; a new draft copies the published fields. */
    public function draft(Form $form): FormVersion
    {
        if ($existing = $form->draft()->first()) {
            return $existing;
        }

        $latest = $form->versions()->first();

        return FormVersion::create([
            'form_id' => $form->id,
            'version' => ($latest?->version ?? 0) + 1,
            'fields' => $latest?->fields ?? [],
            'status' => VersionStatus::Draft,
        ]);
    }

    public function publish(Form $form, ?string $reason = null): FormVersion
    {
        $draft = $form->draft()->first() ?? throw new ConfigurationException('Nothing to publish: create a draft first.');

        if (empty($draft->fields)) {
            throw new ConfigurationException('A form needs at least one field before it can be published.');
        }

        return DB::transaction(function () use ($form, $draft, $reason) {
            $form->published()->first()?->withAuditReason($reason)->update(['status' => VersionStatus::Retired, 'retired_at' => now()]);

            $draft->withAuditReason($reason)->update([
                'status' => VersionStatus::Published,
                'published_by' => auth()->id(),
                'published_at' => now(),
            ]);

            $this->audit->record(AuditAction::PolicyPublished, 'configuration', $draft, reason: $reason, metadata: ['form' => $form->key, 'version' => $draft->version]);

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function submit(Form $form, array $data, ?Model $subject = null): FormSubmission
    {
        $version = $form->published()->first() ?? throw new ConfigurationException('This form has no published version.');

        $validated = Validator::make($data, $version->rules())->validate();

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'form_version_id' => $version->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'submitted_by' => auth()->id(),
            'data' => $validated,
            'status' => $form->requires_approval ? 'submitted' : 'approved',
        ]);

        $this->audit->record(AuditAction::Submitted, 'configuration', $submission, metadata: ['form' => $form->key, 'version' => $version->version]);

        FormSubmitted::dispatch($submission);

        return $submission;
    }

    public function review(FormSubmission $submission, bool $approve, ?string $note = null): FormSubmission
    {
        if ($submission->status !== 'submitted') {
            throw new ConfigurationException('Only submitted forms can be reviewed.');
        }

        $submission->withAuditReason($note)->update([
            'status' => $approve ? 'approved' : 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->record($approve ? AuditAction::Approved : AuditAction::Rejected, 'configuration', $submission, reason: $note);

        return $submission;
    }
}
