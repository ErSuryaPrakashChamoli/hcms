<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity CA-3: one option of a tenant-extendable vocabulary (OptionLists::LISTS). A row with a built-in
 * code renames or hides that option (built-in codes are never removed: records keep pointing at them); any other code
 * adds an option.
 */
#[Fillable(['tenant_id', 'list_key', 'code', 'label', 'sort_order', 'status'])]
class OptionList extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (OptionList $option): void {
            if (! array_key_exists((string) $option->list_key, OptionLists::LISTS)) {
                throw new RuntimeException('Unknown option list.');
            }
            if (preg_match('/^[a-z0-9_]{1,48}$/', (string) $option->code) !== 1) {
                throw new RuntimeException('An option code uses lower-case letters, digits and underscores.');
            }
        });
        static::deleting(function (OptionList $option): void {
            if (array_key_exists((string) $option->code, OptionLists::builtIn((string) $option->list_key))) {
                throw new RuntimeException('A built-in option cannot be removed; set it inactive to hide it.');
            }
        });
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return (OptionLists::LISTS[$this->list_key]['label'] ?? $this->list_key).': '.$this->label;
    }
}
