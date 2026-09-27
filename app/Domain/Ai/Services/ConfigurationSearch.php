<?php

namespace App\Domain\Ai\Services;

/** "What do you want to configure?" (§103): keyword search over the admin configuration map. */
final class ConfigurationSearch
{
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
}
