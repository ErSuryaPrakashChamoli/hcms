<?php

namespace App\Filament\Support\Forms;

/**
 * UX.15 closure (accessibility): Filament's JavaScript date picker wraps its labelled, focusable display input in a
 * <button tabindex="-1">, so assistive technology meets an interactive control nested inside another (axe
 * "nested-interactive"). The wrapper is only a click target and the anchor for the panel, never a tab stop, so it
 * becomes a plain element: the labelled input stays the one focus stop, every keyboard and pointer handler is
 * kept, and a read-only or disabled picker still ignores them (the button's disabled state becomes a data flag).
 * Native pickers are untouched. If Filament's markup ever changes, the original HTML is returned as it was.
 */
trait AccessibleDateTimeTrigger
{
    public function toEmbeddedHtml(): string
    {
        return self::repairTrigger(parent::toEmbeddedHtml());
    }

    public static function repairTrigger(string $html): string
    {
        $repaired = preg_replace_callback('/<button(\s+x-ref="button"[^>]*)>(.*?)<\/button>/s', function (array $m): string {
            $attributes = preg_replace(['/\s+type="button"/', '/\s+tabindex="-1"/', '/\s+aria-label="[^"]*"/', '/\sdisabled(?=\s|$)/'], ['', '', '', ' data-disabled'], $m[1]);
            $attributes = str_replace(
                ['x-on:click="togglePanelVisibility()"', '$el.disabled'],
                ['x-on:click="if (! $el.hasAttribute(\'data-disabled\')) togglePanelVisibility()"', '$el.hasAttribute(\'data-disabled\')'],
                $attributes,
            );

            return '<div'.$attributes.'>'.$m[2].'</div>';
        }, $html, 1);

        return is_string($repaired) ? $repaired : $html;
    }
}
