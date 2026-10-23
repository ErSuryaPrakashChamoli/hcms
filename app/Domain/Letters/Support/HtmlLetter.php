<?php

namespace App\Domain\Letters\Support;

use App\Domain\Notifications\Services\TemplateRenderer;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Renders a template written in the rich-text editor. The editor's variable chips and {{ variables }} typed as text
 * both work. The author's HTML is sanitised first; values are then escaped, and only the block variables (the salary
 * annexure table, the signature image) are inserted as HTML. A block alone in a paragraph replaces the paragraph.
 */
final class HtmlLetter
{
    private const PLACEHOLDER = '\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}';

    /** @param  array<string, mixed>  $context */
    public static function render(string $template, array $context): string
    {
        $html = Str::sanitizeHtml(self::withTypedVariables($template));

        $html = (string) preg_replace_callback('~<p(?:\s[^>]*)?>\s*'.self::PLACEHOLDER.'\s*</p>~', function (array $m) use ($context) {
            $value = Arr::get($context, $m[1]);

            return $value instanceof SalaryAnnexure || $value instanceof SignatureImage ? self::block($value) : $m[0];
        }, $html);

        return (string) preg_replace_callback('~'.self::PLACEHOLDER.'~', function (array $m) use ($context) {
            $value = Arr::get($context, $m[1]);

            return $value instanceof SalaryAnnexure || $value instanceof SignatureImage ? $value->toHtml() : e(TemplateRenderer::format($value));
        }, $html);
    }

    /** The editor's variable chips (<span data-type="mergeTag" data-id="…">) as {{ … }} text. */
    public static function withTypedVariables(string $html): string
    {
        return (string) preg_replace_callback('~<span\b[^>]*data-type="mergeTag"[^>]*>.*?</span>~s', fn (array $m) => preg_match('/data-id="([a-zA-Z0-9_.]+)"/', $m[0], $id) ? '{{ '.$id[1].' }}' : '', $html);
    }

    /** Plain text of rendered HTML, for search and checking. */
    public static function text(string $html): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags((string) preg_replace('~</(p|h[1-6]|li|tr|div)>|<br\s*/?>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5)));
    }

    private static function block(SalaryAnnexure|SignatureImage $value): string
    {
        return $value instanceof SalaryAnnexure ? $value->toHtml() : '<p>'.$value->toHtml().'</p>';
    }
}
