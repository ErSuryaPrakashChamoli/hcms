<?php

namespace App\Domain\ComplianceReports\Support;

use App\Domain\Organisation\Models\Establishment;
use Carbon\CarbonImmutable;

/**
 * One obligation of the compliance report catalogue (database/data/compliance/reports/in.php): what it is, the law and
 * form behind it, how often it falls due and when, where and when it applies, how firmly that is sourced, and which
 * builder produces it. An obligation without a builder is listed as a checklist item kept outside PeopleOS.
 *
 * The form and legal basis can differ by the establishment's appropriate government and by period (variants): the
 * central rules under the Labour Codes apply from 8 May 2026 where the Central Government is the appropriate
 * government; earlier periods, and state-sphere establishments, follow other rules.
 */
final class ReportDefinition
{
    public const CATEGORIES = [
        'register' => 'Registers',
        'challan' => 'Challans & contributions',
        'return' => 'Returns & statements',
        'payroll' => 'Payroll checks',
        'annual' => 'Annual registers & returns',
        'checklist' => 'Kept outside PeopleOS',
    ];

    /** "register" = maintained continuously: generated for exactly the period asked for. */
    public const FREQUENCIES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'yearly' => 'Yearly', 'register' => 'Maintained continuously', 'event' => 'On the event'];

    /** How firmly an entry is sourced (the catalogue never upgrades a label itself). */
    public const CONFIDENCE = [
        'OFFICIAL_PRIMARY_SOURCE_VERIFIED' => 'Official primary source verified',
        'OFFICIAL_SOURCE_PARTIALLY_VERIFIED' => 'Official source partially verified',
        'SECONDARY_SOURCE_ONLY' => 'Secondary source only',
        'CONFLICTING_SOURCES' => 'Conflicting sources',
        'LEGAL_REVIEW_REQUIRED' => 'Legal review required',
        'UNVERIFIED' => 'Unverified',
    ];

    /** Legacy labels of the first catalogue revision. */
    private const LEGACY_CONFIDENCE = ['official' => 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', 'secondary' => 'SECONDARY_SOURCE_ONLY', 'unverified' => 'UNVERIFIED'];

    /** What PeopleOS produces. None of it is a filing: returns and challans are filed by the employer on the portal. */
    public const OUTPUTS = [
        'register' => 'Statutory register (PeopleOS layout carrying the prescribed particulars)',
        'working_sheet' => 'Working sheet for a return or challan (not a filing)',
        'statement' => 'Statement supporting a payment (not a filing)',
        'check' => 'Internal control (not a statutory obligation)',
        'checklist' => 'Kept outside PeopleOS',
    ];

    /**
     * @param  array<string, mixed>|null  $due
     * @param  list<string>|null  $establishmentTypes
     * @param  array<string, mixed>  $options
     * @param  list<string>  $notes
     * @param  list<array<string, mixed>>  $variants
     * @param  list<array<string, mixed>>  $thresholds
     * @param  list<array{0: string, 1: string}>  $sources
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $category,
        public readonly string $frequency,
        public readonly string $scope,
        public readonly ?string $builder,
        public readonly string $legalBasis,
        public readonly string $form = '',
        public readonly ?array $due = null,
        public readonly string $dueText = '',
        public readonly ?string $requires = null,
        public readonly ?string $state = null,
        public readonly ?string $basis = null,
        public readonly ?array $establishmentTypes = null,
        public readonly ?int $minEmployees = null,
        public readonly array $options = [],
        public readonly array $notes = [],
        public readonly string $source = 'payroll',
        public readonly string $confidence = 'UNVERIFIED',
        public readonly ?string $registration = null,
        public readonly ?string $effectiveFrom = null,
        public readonly ?string $effectiveTo = null,
        public readonly array $variants = [],
        public readonly array $thresholds = [],
        public readonly string $output = 'register',
        public readonly ?string $filing = null,
        public readonly string $obligation = 'statutory',
        public readonly array $sources = [],
        public readonly ?string $verifiedOn = null,
        public readonly ?string $legalReview = null,
        public readonly ?string $sphere = null,
        public readonly string $code = 'wages',
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $key, array $row, ?string $state = null): self
    {
        $thresholds = array_values((array) ($row['thresholds'] ?? []));
        if ($thresholds === [] && isset($row['min_employees'])) {
            $thresholds = [['min' => (int) $row['min_employees'], 'measure' => $row['threshold_measure'] ?? 'employed_any_day', 'basis' => $row['threshold_basis'] ?? null, 'confidence' => $row['threshold_confidence'] ?? null]];
        }

        return new self(
            key: $key,
            title: (string) $row['title'],
            category: (string) ($row['category'] ?? 'register'),
            frequency: (string) ($row['frequency'] ?? 'monthly'),
            scope: (string) ($row['scope'] ?? 'establishment'),
            builder: $row['builder'] ?? null,
            legalBasis: (string) ($row['legal_basis'] ?? ''),
            form: (string) ($row['form'] ?? ''),
            due: $row['due'] ?? null,
            dueText: (string) ($row['due_text'] ?? ''),
            requires: $row['requires'] ?? null,
            state: $state ?? ($row['state'] ?? null),
            basis: $row['basis'] ?? null,
            establishmentTypes: $row['establishment_types'] ?? null,
            minEmployees: isset($row['min_employees']) ? (int) $row['min_employees'] : null,
            options: (array) ($row['options'] ?? []),
            notes: array_values((array) ($row['notes'] ?? [])),
            source: (string) ($row['source'] ?? 'payroll'),
            confidence: self::normaliseConfidence($row['confidence'] ?? null),
            registration: $row['registration'] ?? null,
            effectiveFrom: $row['effective_from'] ?? null,
            effectiveTo: $row['effective_to'] ?? null,
            variants: array_values((array) ($row['variants'] ?? [])),
            thresholds: $thresholds,
            output: (string) ($row['output'] ?? self::defaultOutput((string) ($row['category'] ?? 'register'), $row['builder'] ?? null)),
            filing: $row['filing'] ?? null,
            obligation: (string) ($row['obligation'] ?? 'statutory'),
            sources: array_values((array) ($row['sources'] ?? [])),
            verifiedOn: $row['verified_on'] ?? null,
            legalReview: $row['legal_review'] ?? null,
            sphere: $row['sphere'] ?? null,
            code: (string) ($row['code'] ?? 'wages'),
        );
    }

    public static function normaliseConfidence(?string $value): string
    {
        $value = (string) $value;

        return isset(self::CONFIDENCE[$value]) ? $value : (self::LEGACY_CONFIDENCE[$value] ?? 'UNVERIFIED');
    }

    public function isGenerated(): bool
    {
        return $this->builder !== null;
    }

    public function isPeriodic(): bool
    {
        return isset(ReportPeriod::FREQUENCIES[$this->frequency]);
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? $this->frequency;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function outputLabel(): string
    {
        return self::OUTPUTS[$this->output] ?? $this->output;
    }

    /**
     * The establishment's appropriate government under the Code this obligation falls under. It can differ by Code:
     * Social Security obligations (`code` = ss) read ss_appropriate_government; wages and OSH obligations read
     * appropriate_government. Null when not configured.
     */
    public function appropriateGovernment(?Establishment $establishment, ?string $code = null): ?string
    {
        return ($code ?? $this->code) === 'ss' ? $establishment?->ss_appropriate_government : $establishment?->appropriate_government;
    }

    /** Whether the obligation exists at any time in the period (its own effective window). */
    public function inForceDuring(ReportPeriod $period): bool
    {
        return ($this->effectiveFrom === null || $period->end->toDateString() >= $this->effectiveFrom)
            && ($this->effectiveTo === null || $period->start->toDateString() <= $this->effectiveTo);
    }

    /**
     * The form, legal basis and sourcing that apply to an establishment for a period: the first variant whose
     * appropriate government, states and dates match (dates against the period's end), else the entry's own. A variant for "unknown" matches an establishment
     * whose appropriate government is not configured.
     */
    public function resolveFor(?Establishment $establishment, ReportPeriod $period): ResolvedForm
    {
        foreach ($this->variants as $variant) {
            $sphere = $this->appropriateGovernment($establishment, $variant['code'] ?? $this->code);
            $wanted = $variant['sphere'] ?? null;
            if ($wanted !== null && ! ($wanted === 'unknown' ? $sphere === null : $wanted === $sphere)) {
                continue;
            }
            if (isset($variant['states']) && ! in_array(ReportCatalogue::canonicalState($establishment?->state), (array) $variant['states'], true)) {
                continue;
            }
            if (isset($variant['from']) && $period->end->toDateString() < $variant['from']) {
                continue;
            }
            if (isset($variant['to']) && $period->end->toDateString() > $variant['to']) {
                continue;
            }

            return new ResolvedForm(
                form: (string) ($variant['form'] ?? $this->form),
                legalBasis: (string) ($variant['legal_basis'] ?? $this->legalBasis),
                confidence: self::normaliseConfidence($variant['confidence'] ?? $this->confidence),
                sources: array_values((array) ($variant['sources'] ?? $this->sources)),
                verifiedOn: $variant['verified_on'] ?? $this->verifiedOn,
                legalReview: $variant['legal_review'] ?? $this->legalReview,
                note: $variant['note'] ?? null,
                configurationRequired: ($variant['sphere'] ?? null) === 'unknown' ? ($variant['configuration'] ?? 'Set the establishment\'s appropriate government (Central or State).') : null,
                effectiveFrom: $variant['from'] ?? $this->effectiveFrom,
                effectiveTo: $variant['to'] ?? $this->effectiveTo,
                statutory: $this->obligation === 'statutory',
            );
        }

        return new ResolvedForm($this->form, $this->legalBasis, $this->confidence, $this->sources, $this->verifiedOn, $this->legalReview, effectiveFrom: $this->effectiveFrom, effectiveTo: $this->effectiveTo, statutory: $this->obligation === 'statutory');
    }

    /** The threshold in force at the period end, if any: ['min' => int, 'measure' => string, 'basis' => ?string, 'confidence' => ?string]. */
    public function thresholdFor(ReportPeriod $period): ?array
    {
        $day = $period->end->toDateString();
        foreach ($this->thresholds as $threshold) {
            if ((isset($threshold['from']) && $day < $threshold['from']) || (isset($threshold['to']) && $day > $threshold['to'])) {
                continue;
            }

            return $threshold;
        }

        return null;
    }

    /**
     * The date a period's obligation falls due: months after the period's last month, on a day of that month ("end"
     * for its last day). end_month overrides the rule for periods ending in a given month (e.g. TDS deposited for
     * March by 30 April, the fourth-quarter TDS statement by 31 May).
     */
    public function dueOn(ReportPeriod $period): ?CarbonImmutable
    {
        if ($this->due === null) {
            return null;
        }

        $rule = $this->due['end_month'][$period->end->month] ?? $this->due;
        $month = $period->end->startOfMonth()->addMonths((int) ($rule['months_after'] ?? $this->due['months_after'] ?? 1));
        $day = $rule['day'] ?? $this->due['day'] ?? 'end';

        return $day === 'end' ? $month->endOfMonth()->startOfDay() : $month->day(min((int) $day, $month->daysInMonth));
    }

    public function dueLabel(ReportPeriod $period): string
    {
        $due = $this->dueOn($period);

        return $due !== null ? $due->format('d M Y').($this->dueText !== '' ? ' — '.$this->dueText : '') : ($this->dueText !== '' ? $this->dueText : '—');
    }

    private static function defaultOutput(string $category, ?string $builder): string
    {
        return match (true) {
            $builder === null => 'checklist',
            $category === 'payroll' => 'check',
            $category === 'challan' => 'statement',
            $category === 'return', $category === 'annual' && in_array($builder, ['return_working_sheet', 'employment_return', 'tds_statement', 'posh_annual_report'], true) => 'working_sheet',
            default => 'register',
        };
    }
}
