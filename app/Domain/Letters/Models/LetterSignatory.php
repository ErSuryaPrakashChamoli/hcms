<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A signing authority for letters: name, designation and a signature image (private disk, never a public URL).
 * Templates name one; otherwise the tenant's default signs. One default per tenant.
 */
#[Fillable(['tenant_id', 'name', 'designation', 'company_id', 'signature_disk', 'signature_path', 'is_default', 'status'])]
class LetterSignatory extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'is_default' => false];

    protected static function booted(): void
    {
        static::saved(function (self $signatory): void {
            if ($signatory->is_default) {
                self::query()->whereKeyNot($signatory->getKey())->where('is_default', true)->get()
                    ->each(fn (self $other) => $other->withAuditReason("{$signatory->name} is now the default signatory")->update(['is_default' => false]));
            }
        });
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditLabel(): string
    {
        return trim("{$this->name} {$this->designation}");
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The default active signatory, or null when the tenant has none. */
    public static function default(): ?self
    {
        return self::query()->where('status', 'active')->where('is_default', true)->orderBy('id')->first();
    }

    /** @return array{bytes: string, mime: string, width: int, height: int}|null */
    public function signatureImage(): ?array
    {
        $disk = $this->signature_disk ?: config('peopleos.documents.disk');
        if (blank($this->signature_path) || ! Storage::disk($disk)->exists($this->signature_path)) {
            return null;
        }
        $bytes = (string) Storage::disk($disk)->get($this->signature_path);
        $size = @getimagesizefromstring($bytes);
        if ($size === false || ! in_array($size['mime'], ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $size['mime'], 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }
}
