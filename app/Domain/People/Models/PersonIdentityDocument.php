<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Geography\Countries;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity MS-4 (matrix 22 §22.2.2 #3, #4), widened 2026-11-27: a government ID of the person — a passport, driving
 * licence, voter ID, national ID, residence or work permit, visa, or a country's own ID (catalogue()). Highly sensitive (blueprint
 * §80): the number is encrypted at rest, hidden from serialisation and masked in the audit trail; lists show only the last
 * four characters (`number_last4`). Written only through ChangeIdentityDocumentAction (employee.sensitive.update, the CA-4
 * linked document); read on the Employee 360 with `viewSensitive`, and by the employee only as PRV-1 allows (MyProfile).
 */
#[Fillable(['tenant_id', 'person_id', 'kind', 'number', 'issued_on', 'expires_on', 'issuing_country', 'place_of_issue'])]
#[Hidden(['number'])]
class PersonIdentityDocument extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            $number = $document->getAttributes()['number'] ?? null;
            if ($document->isDirty('number') && $number !== null) {
                $document->number_last4 = mb_substr((string) $document->number, -4);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'number' => 'encrypted',
            'issued_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** @return array<string, string> kind => label, in catalogue order (peopleos.people.identity_documents) */
    public static function kinds(): array
    {
        return array_map(fn (array $d) => (string) $d['label'], self::catalogue());
    }

    /**
     * The government IDs a person may have on file (owner, 2026-11-27): India's, those any country issues, and each
     * country's own; see peopleos.people.identity_documents.
     *
     * @return array<string, array{label: string, country?: ?string, expires?: bool, pattern?: string, country_patterns?: array<string, string>, hint?: string}>
     */
    public static function catalogue(): array
    {
        return (array) config('peopleos.people.identity_documents', []);
    }

    /**
     * The kinds for a picker, grouped: "India", "Any country", then each country by name.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedKinds(): array
    {
        $groups = [];
        foreach (self::catalogue() as $kind => $details) {
            $country = $details['country'] ?? null;
            $group = $country === null ? 'Any country' : Countries::name($country);
            $groups[$group][$kind] = (string) $details['label'];
        }
        $india = Countries::name('IN');
        $first = array_intersect_key($groups, array_flip([$india, 'Any country']));
        $rest = array_diff_key($groups, $first);
        ksort($rest);

        return [...$first, ...$rest];
    }

    /** The country a kind of ID belongs to (null: issued by any country). */
    public static function countryOf(?string $kind): ?string
    {
        return self::catalogue()[$kind]['country'] ?? null;
    }

    /** Whether the kind of ID has an expiry date worth a reminder. */
    public static function expires(?string $kind): bool
    {
        return (bool) (self::catalogue()[$kind]['expires'] ?? false);
    }

    /** How the number is written, for the form (null when the format is not fixed). */
    public static function hint(?string $kind): ?string
    {
        return self::catalogue()[$kind]['hint'] ?? null;
    }

    /**
     * Why a number is not valid for its kind, or null. Checked in capitals without spaces, only where the format is fixed
     * (and, for a passport, only for the issuing country whose format is known).
     */
    public static function numberProblem(?string $kind, ?string $number, ?string $issuingCountry = null): ?string
    {
        $details = self::catalogue()[$kind] ?? null;
        if ($details === null || blank($number)) {
            return null;
        }
        $pattern = $details['country_patterns'][mb_strtoupper((string) $issuingCountry)] ?? $details['pattern'] ?? null;
        $value = mb_strtoupper((string) preg_replace('/\s+/', '', (string) $number));
        if ($pattern === null || preg_match($pattern, $value) === 1) {
            return null;
        }

        return 'This is not a valid '.$details['label'].' number'.(filled($details['hint'] ?? null) ? ' ('.$details['hint'].').' : '.');
    }

    public function kindLabel(): string
    {
        return (string) (self::kinds()[$this->kind] ?? ucfirst(str_replace('_', ' ', (string) $this->kind)));
    }

    public function maskedNumber(): string
    {
        return '••••'.($this->getAttributes()['number_last4'] ?? '');
    }

    public function auditLabel(): string
    {
        return $this->kindLabel().' '.$this->maskedNumber();
    }

    /** @return list<string> */
    public function auditSensitiveAttributes(): array
    {
        return ['number'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'number_last4'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
