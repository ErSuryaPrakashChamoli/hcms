<?php

namespace App\Domain\Ai\Services;

/** "What do you want to configure?" (§103): keyword search over the admin configuration map. */
final class ConfigurationSearch
{
    /** Words too general to pick out a setting on their own ("probation period" is about probation; "working hours" is not about every limit counted in hours). */
    private const GENERIC_WORDS = ['period', 'setting', 'settings', 'policy', 'days', 'hours', 'time', 'change', 'rule', 'rules'];

    /** @return array<int, array{label: string, url: string, score: int}> */
    public function search(string $term, int $limit = 8): array
    {
        $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($term)));
        if ($words === []) {
            return [];
        }
        $results = [];
        foreach (config('peopleos.ai.config_search', []) as $entry) {
            $haystack = strtolower($entry['keywords'].' '.$entry['label']);
            $score = 0;
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $score += strlen($word) >= 5 ? 3 : 2;
                } elseif (strlen($word) >= 4 && str_contains($haystack, substr($word, 0, 4))) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $results[] = ['label' => $entry['label'], 'url' => url($entry['url']), 'score' => $score];
            }
        }
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * search() without the weak matches: a four-letter prefix alone (score 1: "peri" inside "experience") only
     * counts when nothing matches better.
     *
     * @return array<int, array{label: string, url: string, score: int}>
     */
    public function relevant(string $term, int $limit = 8): array
    {
        $found = $this->search($term, $limit);
        $best = max(array_column($found, 'score') ?: [0]);

        return array_values(array_filter($found, fn (array $r) => $r['score'] > 1 || $best <= 1));
    }

    /**
     * Tenant setting keys whose name matches the term. Names and keys only, never values.
     *
     * @return list<string>
     */
    public function settings(string $term): array
    {
        $words = array_diff(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($term)), fn ($w) => mb_strlen($w) >= 4), self::GENERIC_WORDS);
        if ($words === []) {
            return [];
        }

        return array_values(array_filter(array_keys(config('peopleos.settings', [])),
            fn (string $key) => collect($words)->contains(fn (string $w) => str_contains(str_replace(['.', '_'], ' ', $key), $w))));
    }

    /** "employee.probation.default_months" → "Employee · probation · default months". */
    public static function settingLabel(string $key): string
    {
        return ucfirst(implode(' · ', array_map(fn ($s) => str_replace('_', ' ', $s), explode('.', $key))));
    }

    /** Words of setting keys that read differently in a name: abbreviations in capitals, run-together words apart. */
    private const SETTING_WORDS = [
        'ai' => 'AI', 'api' => 'API', 'bgv' => 'BGV', 'ctc' => 'CTC', 'esi' => 'ESI', 'ess' => 'ESS', 'hr' => 'HR', 'hra' => 'HRA',
        'id' => 'ID', 'ids' => 'IDs', 'ifsc' => 'IFSC', 'ip' => 'IP', 'it' => 'IT', 'kpi' => 'KPI', 'kra' => 'KRA', 'lop' => 'LOP',
        'lta' => 'LTA', 'lwf' => 'LWF', 'mfa' => 'MFA', 'nps' => 'NPS', 'pan' => 'PAN', 'pf' => 'PF', 'pt' => 'PT', 'sla' => 'SLA',
        'sso' => 'SSO', 'tds' => 'TDS', 'uom' => 'UOM', 'url' => 'URL', 'linkedin' => 'LinkedIn', 'max' => 'maximum',
        'min' => 'minimum', 'form12bb' => 'Form 12BB', 'form16' => 'Form 16', 'kb' => 'knowledge base', 'servicedesk' => 'service desk',
        'myspace' => 'My space', 'pct' => 'percent', 'checkin' => 'check-in',
    ];

    /**
     * A setting's name for people, split into what it belongs to and what it is:
     * "payroll.tax.form12bb_visibility" → ['group' => 'Payroll · Tax', 'label' => 'Form 12BB visibility']. Within a
     * place that already says it ($within = "payroll"), the group leaves that out: 'Tax'.
     *
     * @return array{group: string, label: string}
     */
    public static function settingParts(string $key, ?string $within = null): array
    {
        $words = fn (string $segment) => ucfirst(implode(' ', array_map(fn (string $w) => self::SETTING_WORDS[$w] ?? $w, explode('_', $segment))));
        $segments = explode('.', $key);
        $last = array_pop($segments);
        if ($within !== null && ($segments[0] ?? null) === $within) {
            array_shift($segments);
        }

        return ['group' => $segments === [] ? 'General' : implode(' · ', array_map($words, $segments)), 'label' => $words($last)];
    }
}
