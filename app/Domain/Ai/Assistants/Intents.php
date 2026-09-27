<?php

namespace App\Domain\Ai\Assistants;

/** Tiny keyword intent matcher shared by the assistants. */
final class Intents
{
    /** @param  array<string, array<int, string>>  $intents  intent => keywords/phrases (all lowercase) */
    public static function detect(string $question, array $intents): ?string
    {
        $q = ' '.strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $question)).' ';
        $best = null;
        $bestScore = 0;

        foreach ($intents as $intent => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($q, ' '.$keyword.' ') || str_contains($q, $keyword)) {
                    $score += strlen($keyword) > 4 ? 2 : 1;
                }
            }
            if ($score > $bestScore) {
                $best = $intent;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public static function number(string $question, int $default): int
    {
        return preg_match('/\b(\d{1,3})\b/', $question, $m) ? (int) $m[1] : $default;
    }
}
