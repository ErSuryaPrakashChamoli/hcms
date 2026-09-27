<?php

namespace App\Domain\Employment\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\FeatureFlags;

/** Blueprint §66: viewing bank / statutory data is itself an audit event. */
final class SensitiveAccessAuditor
{
    /** @var array<string, true> once per scope per employee per request */
    private array $recorded = [];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FeatureFlags $features,
    ) {}

    public function recordView(Employee $employee, string $scope, ?string $purpose = null): void
    {
        if ($this->features->disabled('audit.sensitive_access')) {
            return;
        }

        $key = $employee->getKey().':'.$scope.':'.($purpose ?? '');

        if (isset($this->recorded[$key])) {
            return;
        }

        $this->recorded[$key] = true;

        $this->audit->record(
            action: AuditAction::View,
            module: 'employment',
            entity: $employee,
            reason: $purpose,
            metadata: ['scope' => $scope, 'purpose' => $purpose],
        );
    }
}
