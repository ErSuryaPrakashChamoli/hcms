<?php

use App\Domain\ComplianceReports\Contracts\ReportBuilder;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportDefinition;

/*
 | The catalogue's data, checked however it is maintained (decision C8): every label is one of the six and is backed
 | by what it claims, every date range runs forwards, thresholds never overlap, every builder and state resolves, and
 | the catalogue holds no rule data (rates and ceilings live in versioned compliance rules only).
 */

function catalogueRows(): array
{
    $data = require database_path('data/compliance/reports/in.php');
    $rows = [];
    foreach ($data['central'] as $key => $row) {
        $rows["central:{$key}"] = $row;
    }
    foreach ($data['states'] as $state => $entry) {
        foreach ($entry['reports'] ?? [] as $key => $row) {
            $rows["{$state}:{$key}"] = $row;
        }
    }

    return $rows;
}

it('labels every entry, variant and threshold with one of the six confidence labels, backed by what it claims', function () {
    $official = fn (array $sources) => collect($sources)->contains(fn ($s) => str_starts_with((string) ($s[1] ?? ''), 'https://')
        && preg_match('/(^|\.)(gov\.in|nic\.in)$/', (string) parse_url((string) $s[1], PHP_URL_HOST)));
    $problems = [];
    foreach (catalogueRows() as $key => $row) {
        $label = $row['confidence'] ?? null;
        if ($label === null) {
            ($row['obligation'] ?? 'statutory') === 'internal_control' || $problems[] = "{$key}: a statutory entry without a label";

            continue;
        }
        array_key_exists($label, ReportDefinition::CONFIDENCE) || $problems[] = "{$key}: unknown label {$label}";
        // A label that names a source must cite one; with nothing cited the honest label is UNVERIFIED.
        if (in_array($label, ['OFFICIAL_PRIMARY_SOURCE_VERIFIED', 'OFFICIAL_SOURCE_PARTIALLY_VERIFIED', 'SECONDARY_SOURCE_ONLY', 'CONFLICTING_SOURCES'], true) && empty($row['sources'])) {
            $problems[] = "{$key}: {$label} without a source";
        }
        // Primary only on an official host reached over HTTPS (or a gazette copy whose signature validated).
        if ($label === 'OFFICIAL_PRIMARY_SOURCE_VERIFIED' && ! $official($row['sources'] ?? []) && ! preg_grep('/valid Government of India Press signature/', array_column($row['sources'] ?? [], 0))) {
            $problems[] = "{$key}: primary without an official HTTPS source";
        }
        foreach ($row['sources'] ?? [] as $source) {
            (is_array($source) && count($source) >= 2 && filter_var($source[1], FILTER_VALIDATE_URL)) || $problems[] = "{$key}: a source is not a [title, url] pair";
        }
        foreach ($row['variants'] ?? [] as $i => $variant) {
            isset($variant['confidence']) && ! array_key_exists($variant['confidence'], ReportDefinition::CONFIDENCE) && $problems[] = "{$key} variant {$i}: unknown label";
        }
        $thresholds = array_values((array) ($row['thresholds'] ?? []));
        if ($thresholds === [] && isset($row['min_employees'])) {
            $thresholds = [['min' => $row['min_employees'], 'confidence' => $row['threshold_confidence'] ?? null]];
        }
        foreach ($thresholds as $t) {
            isset($t['confidence']) && ! array_key_exists($t['confidence'], ReportDefinition::CONFIDENCE) && $problems[] = "{$key}: threshold with an unknown label";
        }
    }

    expect($problems)->toBe([]);
});

it('keeps every date range forwards, thresholds without overlaps, and nothing verified after the review date', function () {
    $reviewed = app(ReportCatalogue::class)->reviewed()['on'];
    $problems = [];
    foreach (catalogueRows() as $key => $row) {
        isset($row['effective_from'], $row['effective_to']) && $row['effective_from'] > $row['effective_to'] && $problems[] = "{$key}: effective_from after effective_to";
        isset($row['verified_on']) && $row['verified_on'] > $reviewed && $problems[] = "{$key}: verified after the catalogue review date";
        foreach ($row['variants'] ?? [] as $i => $v) {
            isset($v['from'], $v['to']) && $v['from'] > $v['to'] && $problems[] = "{$key} variant {$i}: from after to";
        }
        $thresholds = array_values((array) ($row['thresholds'] ?? []));
        foreach ($thresholds as $i => $t) {
            isset($t['from'], $t['to']) && $t['from'] > $t['to'] && $problems[] = "{$key} threshold {$i}: from after to";
            foreach (array_slice($thresholds, $i + 1, null, true) as $j => $u) {
                ($t['from'] ?? '0000-00-00') <= ($u['to'] ?? '9999-12-31') && ($u['from'] ?? '0000-00-00') <= ($t['to'] ?? '9999-12-31') && $problems[] = "{$key}: thresholds {$i} and {$j} overlap";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('resolves every builder and state, and holds no rule data', function () {
    $data = require database_path('data/compliance/reports/in.php');
    expect(array_keys($data['states']))->toEqualCanonicalizing(array_keys(config('peopleos.compliance.states')));

    foreach (catalogueRows() as $key => $row) {
        if (isset($row['builder'])) {
            expect(is_subclass_of((string) config("peopleos.compliance.report_builders.{$row['builder']}"), ReportBuilder::class))->toBeTrue("{$key}: builder {$row['builder']}");
        }
        // Rates, ceilings and rule status belong to versioned compliance rules, activated only by RuleVerifications.
        expect(array_intersect(array_keys($row), ['parameters', 'rate', 'rates', 'ceiling', 'slabs', 'status']))->toBe([], $key);
    }
});
