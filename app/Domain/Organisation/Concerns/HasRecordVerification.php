<?php

namespace App\Domain\Organisation\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 6.3: a record that is "configured" until verified against its authoritative document. The
 * verification columns change only through RecordVerifications; editing an identity field of a
 * verified record resets it to unverified (and the audit trail shows why).
 *
 * @mixin Model
 */
trait HasRecordVerification
{
    public const VERIFICATION_COLUMNS = ['verification_status', 'verification_reference', 'verification_evidence_path', 'verification_evidence_sha256', 'verification_submitted_by', 'verification_submitted_at', 'verified_by', 'verified_at', 'verification_notes'];

    public static function bootHasRecordVerification(): void
    {
        static::saving(function (Model $model): void {
            if (! $model->exists) {
                $model->setAttribute('verification_status', 'unverified');

                return;
            }
            if ($model->getOriginal('verification_status') !== 'unverified' && $model->isDirty($model->verificationIdentity())) {
                foreach (self::VERIFICATION_COLUMNS as $column) {
                    $model->setAttribute($column, null);
                }
                $model->setAttribute('verification_status', 'unverified');
            }
        });
    }

    /** @return list<string> fields whose change invalidates a verification */
    abstract public function verificationIdentity(): array;

    public function isRecordVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
