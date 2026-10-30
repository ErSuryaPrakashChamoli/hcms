<?php

namespace App\Domain\Feedback\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity P13: the questions of a feedback template or a question bank and the answers given to them. The
 * question types are the survey ones (peopleos.engagement.question_types: single and multiple choice, rating, agreement
 * scale, yes / no, free text, number, date), so a question reads the same wherever it is asked. A question is
 * {key, title, type, options: [{value, label}], scale_max, mandatory, bank_question_id}.
 */
final class FeedbackQuestions
{
    /** @return array<string, string> */
    public static function types(): array
    {
        return (array) config('peopleos.engagement.question_types', []);
    }

    /**
     * Checks and tidies a list of questions (keys q1, q2 … where missing or repeated; choice options as {value, label}).
     *
     * @param  array<int|string, mixed>  $questions
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $questions): array
    {
        $out = [];
        $keys = [];
        foreach (array_values($questions) as $i => $q) {
            $q = (array) $q;
            $title = trim((string) ($q['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Question '.($i + 1).' needs a title.');
            }
            if (mb_strlen($title) > 500) {
                throw new RuntimeException('Question '.($i + 1).' is longer than 500 characters.');
            }
            $type = (string) ($q['type'] ?? '');
            if (! array_key_exists($type, self::types())) {
                throw new RuntimeException("Question \"{$title}\" has an unknown response type.");
            }
            $key = Str::snake(trim((string) ($q['key'] ?? '')));
            if (! preg_match('/^[a-z0-9_]{1,40}$/', $key) || in_array($key, $keys, true)) {
                $key = 'q'.($i + 1);
                while (in_array($key, $keys, true)) {
                    $key .= '_';
                }
            }
            $keys[] = $key;
            $options = null;
            if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
                $options = self::options($q['options'] ?? []);
                if (count($options) < 2 || count($options) > 50) {
                    throw new RuntimeException("Question \"{$title}\" needs between 2 and 50 options.");
                }
            }
            $scale = null;
            if ($type === 'rating') {
                $scale = (int) ($q['scale_max'] ?? 5) ?: 5;
                if ($scale < 2 || $scale > 10) {
                    throw new RuntimeException("Question \"{$title}\": a rating runs from 1 to between 2 and 10.");
                }
            }
            $out[] = ['key' => $key, 'title' => $title, 'type' => $type, 'options' => $options, 'scale_max' => $scale,
                'mandatory' => (bool) ($q['mandatory'] ?? true), 'bank_question_id' => filled($q['bank_question_id'] ?? null) ? (int) $q['bank_question_id'] : null];
        }

        return $out;
    }

    /**
     * Choice options from labels (a TagsInput list) or {value, label} rows.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(mixed $options): array
    {
        $out = [];
        foreach (array_values((array) $options) as $i => $option) {
            $label = trim((string) (is_array($option) ? ($option['label'] ?? $option['value'] ?? '') : $option));
            $value = Str::limit(Str::slug((string) (is_array($option) ? ($option['value'] ?? $label) : $label), '_'), 64, '');
            $value = $value === '' && $label !== '' ? 'option_'.($i + 1) : $value;
            if ($label !== '' && ! isset($out[$value])) {
                $out[$value] = ['value' => $value, 'label' => $label];
            }
        }

        return array_values($out);
    }

    /**
     * Checks answers against the questions they were asked on: mandatory questions answered, choices among the options,
     * ratings on the scale. Unknown keys are dropped.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed> question key => answer
     */
    public static function validate(array $questions, array $answers): array
    {
        $clean = [];
        foreach ($questions as $q) {
            $value = $answers[$q['key']] ?? null;
            $blank = $value === null || $value === '' || $value === [];
            if ($blank) {
                if ($q['mandatory'] ?? false) {
                    throw new RuntimeException("Answer \"{$q['title']}\".");
                }

                continue;
            }
            $clean[$q['key']] = match ($q['type']) {
                'single_choice' => in_array((string) $value, array_column($q['options'] ?? [], 'value'), true) ? (string) $value : throw new RuntimeException("Choose one of the options for \"{$q['title']}\"."),
                'multiple_choice' => self::choices($q, (array) $value),
                'rating' => self::scale($q, $value, (int) ($q['scale_max'] ?? 5)),
                'likert' => self::scale($q, $value, 5),
                'yes_no' => match (true) {
                    in_array($value, [true, 1, '1', 'yes'], true) => 'yes',
                    in_array($value, [false, 0, '0', 'no'], true) => 'no',
                    default => throw new RuntimeException("Answer yes or no to \"{$q['title']}\"."),
                },
                'number' => is_numeric($value) ? $value + 0 : throw new RuntimeException("\"{$q['title']}\" needs a number."),
                'date' => self::date($q, $value),
                default => mb_strlen((string) $value) <= 5000 ? trim((string) $value) : throw new RuntimeException("The answer to \"{$q['title']}\" is longer than 5000 characters."),
            };
        }

        return $clean;
    }

    /** An answer as people read it (option labels, "4 / 5", Yes / No). */
    public static function display(array $question, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        $labels = array_column($question['options'] ?? [], 'label', 'value');

        return match ($question['type']) {
            'single_choice' => $labels[$value] ?? (string) $value,
            'multiple_choice' => implode(', ', array_map(fn ($v) => $labels[$v] ?? (string) $v, (array) $value)),
            'rating' => $value.' / '.($question['scale_max'] ?? 5),
            'likert' => (string) (config('peopleos.engagement.likert_options')[(string) $value] ?? $value),
            'yes_no' => $value === 'yes' ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    /** @return list<string> */
    private static function choices(array $q, array $values): array
    {
        $allowed = array_column($q['options'] ?? [], 'value');
        $values = array_values(array_unique(array_map('strval', $values)));
        if (array_diff($values, $allowed) !== []) {
            throw new RuntimeException("Choose among the options for \"{$q['title']}\".");
        }
        if ($values === [] && ($q['mandatory'] ?? false)) {
            throw new RuntimeException("Answer \"{$q['title']}\".");
        }

        return $values;
    }

    private static function scale(array $q, mixed $value, int $max): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > $max) {
            throw new RuntimeException("\"{$q['title']}\" is rated from 1 to {$max}.");
        }

        return (int) $value;
    }

    private static function date(array $q, mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw new RuntimeException("\"{$q['title']}\" needs a date.");
        }
    }
}
