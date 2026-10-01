<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningProgramVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Phase 8 certificates: issued from finalized completions (or recorded as external credentials,
 * unverified until a second person verifies them), stored documents on the private disk with a
 * SHA-256, authorised and audited downloads, revocation with a reason, and expiry. An expired
 * credential is never extended: recertification creates new learning and, on completion, a new
 * certificate that the old one points to.
 */
final class Certificates
{
    public const DISK = 'local';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function issueForCompletion(LearningCompletion $completion, CourseVersion $version, Employee $employee, ?User $actor = null, bool $mandatory = false): LearningCertificate
    {
        $expires = $version->validity_months ? Carbon::parse($completion->completed_at)->addMonths($version->validity_months)->startOfDay() : null;
        $course = Course::query()->findOrFail($version->course_id);

        $certificate = LearningCertificate::query()->create([
            'employee_id' => $employee->id, 'course_id' => $course->id, 'course_version_id' => $version->id,
            'learning_enrolment_id' => $completion->learning_enrolment_id, 'learning_completion_id' => $completion->id,
            'number' => $this->number($course->code, $employee), 'issued_on' => now()->startOfDay(), 'expires_on' => $expires,
            'recertification_due_on' => $mandatory ? $expires : null,
            'score' => $completion->score, 'issuer' => $version->provider?->name ?? config('app.name'), 'verification_status' => 'verified', 'status' => 'valid',
        ]);
        // The previous certificate for the same course (if any) points at its renewal; its own dates stay.
        LearningCertificate::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('course_id', $course->id)
            ->whereKeyNot($certificate->id)->whereNull('renewed_by_certificate_id')->whereIn('status', ['valid', 'expiring', 'expired'])
            ->get()->each(fn (LearningCertificate $old) => $old->update(['renewed_by_certificate_id' => $certificate->id]));

        $this->audit->record(AuditAction::Create, 'learning', $certificate, [], null, actor: $actor, metadata: ['event' => 'certificate_issued', 'course_version_id' => $version->id]);
        LearningEvent::dispatch('learning.certificate.issued', $employee, $certificate, ['course' => $version->title, 'expires_on' => $expires?->toDateString()]);

        return $certificate;
    }

    public function issueForProgram(LearningCompletion $completion, LearningProgramVersion $version, Employee $employee, ?User $actor = null): LearningCertificate
    {
        $program = $version->program()->firstOrFail();
        $anchor = Course::query()->whereIn('id', collect($version->items)->where('type', 'course')->pluck('id'))->value('id');
        if ($anchor === null) {
            throw new RuntimeException('A program certificate needs at least one course item.');
        }
        $certificate = LearningCertificate::query()->create([
            'employee_id' => $employee->id, 'course_id' => $anchor, 'learning_program_version_id' => $version->id, 'learning_completion_id' => $completion->id,
            'number' => $this->number('PRG-'.$program->code, $employee), 'issued_on' => now()->startOfDay(),
            'expires_on' => $version->validity_months ? now()->addMonths($version->validity_months)->startOfDay() : null,
            'issuer' => config('app.name'), 'verification_status' => 'verified', 'status' => 'valid',
        ]);
        $this->audit->record(AuditAction::Create, 'learning', $certificate, [], null, actor: $actor, metadata: ['event' => 'certificate_issued', 'program_version_id' => $version->id]);
        LearningEvent::dispatch('learning.certificate.issued', $employee, $certificate, ['course' => $program->name, 'expires_on' => $certificate->expires_on?->toDateString()]);

        return $certificate;
    }

    /**
     * An external credential (e.g. a vendor certification) the employee or L&D records. It stays
     * unverified until someone other than the holder with learning.certificates verifies it.
     *
     * @param  array{course_id: int, number: string, issuer: string, issued_on: string, expires_on?: ?string, credential_url?: ?string}  $data
     */
    public function recordExternal(Employee $employee, array $data, ?string $contents, ?string $fileName, User $actor): LearningCertificate
    {
        if (! empty($data['expires_on']) && Carbon::parse($data['expires_on'])->lt(Carbon::parse($data['issued_on']))) {
            throw new RuntimeException('A certificate cannot expire before it is issued.');
        }
        $certificate = DB::transaction(function () use ($employee, $data, $actor) {
            $certificate = LearningCertificate::query()->create([
                'employee_id' => $employee->id, 'course_id' => $data['course_id'], 'number' => 'EXT-'.$data['number'],
                'issued_on' => $data['issued_on'], 'expires_on' => $data['expires_on'] ?? null, 'issuer' => $data['issuer'],
                'credential_url' => $data['credential_url'] ?? null, 'is_external' => true, 'verification_status' => 'unverified',
                'status' => ! empty($data['expires_on']) && Carbon::parse($data['expires_on'])->isPast() ? 'expired' : 'valid',
            ]);
            $this->audit->record(AuditAction::Create, 'learning', $certificate, [], null, actor: $actor, metadata: ['event' => 'external_certificate_recorded']);

            return $certificate;
        });
        if ($contents !== null) {
            $this->attachDocument($certificate, $contents, (string) $fileName, $actor);
        }

        return $certificate->refresh();
    }

    public function verify(LearningCertificate $certificate, User $actor, ?string $note = null): LearningCertificate
    {
        if (! $actor->hasPermission('learning.certificates')) {
            throw new RuntimeException('Verifying certificates needs learning.certificates.');
        }
        if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($certificate->employee_id)->value('user_id') === $actor->id) {
            throw new RuntimeException('A certificate holder cannot verify their own certificate.');
        }
        $certificate->withAuditReason($note)->update(['verification_status' => 'verified']);

        return $certificate;
    }

    public function revoke(LearningCertificate $certificate, string $reason, User $actor): LearningCertificate
    {
        if (! $actor->hasPermission('learning.certificates')) {
            throw new RuntimeException('Revoking certificates needs learning.certificates.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to revoke a certificate.');
        }

        return DB::transaction(function () use ($certificate, $reason, $actor) {
            $current = LearningCertificate::query()->withoutGlobalScope(AccessScope::class)->whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'revoked') {
                throw new RuntimeException('This certificate is already revoked.');
            }
            $certificate->setRawAttributes($current->getAttributes(), true);
            $certificate->withAuditReason($reason)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason]);
            LearningEvent::dispatch('learning.certificate.revoked', $certificate->employee()->withoutGlobalScope(AccessScope::class)->first(), $certificate, ['number' => $certificate->number]);

            return $certificate;
        });
    }

    /** Store the certificate document on the private disk (never public), with its SHA-256. */
    public function attachDocument(LearningCertificate $certificate, string $contents, string $fileName, ?User $actor = null): LearningCertificate
    {
        if ($certificate->document_path !== null) {
            throw new RuntimeException('A certificate document is never replaced.');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileName)) ?: 'certificate.pdf';
        $path = sprintf('learning/certificates/%d/%d/%s', $certificate->tenant_id, $certificate->id, $name);
        Storage::disk(self::DISK)->put($path, $contents);
        // Write through a fresh copy so unrelated unsaved changes on the caller's instance never ride along.
        $fresh = LearningCertificate::query()->withoutGlobalScope(AccessScope::class)->whereKey($certificate->id)->firstOrFail();
        $fresh->update(['document_path' => $path, 'document_name' => $name, 'document_sha256' => hash('sha256', $contents)]);
        $certificate->setRawAttributes($fresh->getAttributes(), true);
        $this->audit->record(AuditAction::Update, 'learning', $certificate, [], null, actor: $actor, metadata: ['event' => 'certificate_document_attached', 'sha256' => $fresh->document_sha256]);

        return $certificate;
    }

    public function downloadUrl(LearningCertificate $certificate, int $minutes = 10): string
    {
        return URL::temporarySignedRoute('learning.certificates.download', now()->addMinutes($minutes), ['certificate' => $certificate->id]);
    }

    public function recordDownload(LearningCertificate $certificate, User $user): void
    {
        $this->audit->record(AuditAction::Download, 'learning', $certificate, [], null, actor: $user, metadata: ['event' => 'certificate_downloaded', 'sha256' => $certificate->document_sha256]);
    }

    /**
     * Daily: valid → expiring inside the notice window, → expired after the expiry date (the
     * completed enrolment expires too), and recertification learning is created for mandatory
     * certificates inside the lead window. Returns counts.
     *
     * @return array{expiring: int, expired: int, recertification: int}
     */
    public function tick(?CarbonInterface $today = null): array
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $notice = (int) config('peopleos.learning.certificate_expiry_notice_days', 30);
        $result = ['expiring' => 0, 'expired' => 0, 'recertification' => 0];

        LearningCertificate::query()->with(['employee.person', 'course'])->where('status', 'valid')->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->copy()->addDays($notice))->whereDate('expires_on', '>=', $today)
            ->chunkById(500, function ($certificates) use (&$result) {
                foreach ($certificates as $c) {
                    $c->update(['status' => 'expiring']);
                    LearningEvent::dispatch('learning.certificate_expiring', $c->employee, $c, ['course' => $c->course->title, 'expires_on' => $c->expires_on->toDateString()]);
                    $result['expiring']++;
                }
            });

        LearningCertificate::query()->with(['employee.person', 'course'])->whereIn('status', ['valid', 'expiring'])->whereDate('expires_on', '<', $today)
            ->chunkById(500, function ($certificates) use (&$result) {
                foreach ($certificates as $c) {
                    $c->update(['status' => 'expired']);
                    LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($c->learning_enrolment_id)->where('status', 'completed')->get()->each(fn ($e) => $e->update(['status' => 'expired']));
                    LearningEvent::dispatch('learning.certificate_expired', $c->employee, $c, ['course' => $c->course->title, 'expires_on' => $c->expires_on->toDateString()]);
                    $result['expired']++;
                }
            });

        $lead = (int) config('peopleos.learning.recertification_lead_days', 60);
        LearningCertificate::query()->with(['employee', 'course'])->whereNotNull('recertification_due_on')->whereNull('renewed_by_certificate_id')
            ->whereIn('status', ['valid', 'expiring', 'expired'])->whereDate('recertification_due_on', '<=', $today->copy()->addDays($lead))
            ->chunkById(500, function ($certificates) use (&$result) {
                foreach ($certificates as $c) {
                    if (! $c->course?->isPublished() || ! $c->employee?->lifecycle_state?->isEmployed()) {
                        continue;
                    }
                    $open = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $c->employee_id)->where('course_id', $c->course_id)
                        ->whereIn('status', [...LearningEnrolment::OPEN, ...LearningEnrolment::PENDING])->exists();
                    if (! $open) {
                        app(Learning::class)->enrol($c->employee, $c->course, $c->recertification_due_on, null, null, null, true, 'assigned', 'Recertification of '.$c->number);
                        $result['recertification']++;
                    }
                }
            });

        return $result;
    }

    private function number(string $code, Employee $employee): string
    {
        $prefix = config('peopleos.learning.certificate_prefix', 'CERT');
        $base = sprintf('%s-%s-%s-%s', $prefix, $code, now()->format('Ym'), $employee->employee_code);
        $number = $base;
        $i = 1;
        while (LearningCertificate::query()->withoutGlobalScope(AccessScope::class)->where('number', $number)->exists()) {
            $number = $base.'-'.(++$i);
        }

        return $number;
    }
}
