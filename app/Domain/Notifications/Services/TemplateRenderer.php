<?php

namespace App\Domain\Notifications\Services;

use Illuminate\Support\Arr;

/** Renders {{ dotted.path }} placeholders from a context array. Unknown paths render empty. */
final class TemplateRenderer
{
    /** @param  array<string, mixed>  $context */
    public function render(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', fn (array $m) => self::format(Arr::get($context, $m[1])), $template);
    }

    /** One value as text, as a placeholder prints it. */
    public static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof \DateTimeInterface => $value->format('d M Y'),
            $value instanceof \BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', array_map('strval', Arr::flatten($value))),
            default => (string) $value,
        };
    }
}
