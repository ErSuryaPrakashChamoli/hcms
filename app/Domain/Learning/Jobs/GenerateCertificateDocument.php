<?php

namespace App\Domain\Learning\Jobs;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Services\Certificates;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 8: render the certificate document (HTML, private disk, SHA-256) for an issued certificate.
 * Tenant-bound, unique per certificate, retry-safe: a certificate that already has a document is skipped.
 */
class GenerateCertificateDocument implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public ?int $tenantId;

    public function __construct(public readonly int $certificateId)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return 'certificate-document-'.$this->tenantId.'-'.$this->certificateId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(Certificates $certificates): void
    {
        $certificate = LearningCertificate::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'course', 'courseVersion'])->find($this->certificateId);
        if ($certificate === null || $certificate->document_path !== null || $certificate->status === 'revoked' || $certificate->is_external) {
            return;
        }
        $html = view('learning.certificate', ['certificate' => $certificate])->render();
        $certificates->attachDocument($certificate, $html, 'certificate-'.$certificate->number.'.html');
    }
}
