<?php

namespace App\Filament\Support\Review;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Stringable;

/**
 * What confirming a wizard does, shown on its Review step under the list of every detail entered (owner, 2026-11-26:
 * "beautify and arrange it to make it more readable and understanding"). One card in the step kit's language instead of
 * run-on sentences and "Label: value" lines:
 *
 * - a header: what the card is ("What happens when you apply"), the outcome in one sentence, and the status said once as
 *   a pill (ready, read first, cannot be made yet);
 * - a body, in the order the caller builds it: problems as a toned list, before → after as two columns of chips, facts as
 *   ruled label / value rows (like the review list above it), figures as tiles, the people it reaches as a short list,
 *   per-employee lines as a table;
 * - a footer of one-line notes, each with an icon for what it says (checked, kept, refused, how it runs, what comes next),
 *   in place of a paragraph of small print.
 *
 * Rendered by resources/views/filament/shell/outcome.blade.php. Htmlable, so `Text::make(fn () => Outcome::make()->…)`
 * shows it as it is. It decides nothing: callers pass what their own services worked out. Every text is escaped; only a
 * value given as Htmlable is shown as HTML.
 */
final class Outcome implements Htmlable, Stringable
{
    /** Tones, as the step kit names them. */
    public const TONES = ['idle', 'clear', 'warning', 'blocked', 'pending', 'na'];

    /** Note kinds and their icons. */
    public const NOTES = [
        'check' => 'heroicon-m-shield-check',
        'keep' => 'heroicon-m-clock',
        'refuse' => 'heroicon-m-no-symbol',
        'run' => 'heroicon-m-bolt',
        'next' => 'heroicon-m-arrow-right-circle',
        'mail' => 'heroicon-m-envelope',
        'warning' => 'heroicon-m-exclamation-triangle',
        'money' => 'heroicon-m-banknotes',
        'info' => 'heroicon-m-information-circle',
    ];

    private ?string $headline = null;

    private ?string $sub = null;

    /** @var array{tone: string, label: string}|null */
    private ?array $status = null;

    private ?string $icon = null;

    /** @var list<array<string, mixed>> */
    private array $blocks = [];

    /** @var list<array{kind: string, icon: string, text: string, lead: ?string}> */
    private array $notes = [];

    /** @var array<string, string> */
    private array $attributes = [];

    private function __construct(private string $eyebrow) {}

    public static function make(string $eyebrow = 'What happens when you confirm'): self
    {
        return new self($eyebrow);
    }

    /** The outcome in one sentence, at the top of the card. */
    public function headline(?string $text, ?string $sub = null): self
    {
        $this->headline = filled($text) ? $text : null;
        $this->sub = filled($sub) ? $sub : null;

        return $this;
    }

    /** The status, said once: a pill in the header in its tone (clear, warning, blocked, pending, idle). */
    public function status(string $tone, string $label): self
    {
        $this->status = ['tone' => self::tone($tone), 'label' => $label];

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** A data attribute on the card (tests and scripts find the card by it). */
    public function data(string $name, string $value = ''): self
    {
        $this->attributes['data-'.$name] = $value;

        return $this;
    }

    /**
     * Problems, or anything to read first: a toned box with a title, a line under it and one bullet per line.
     *
     * @param  iterable<string|Stringable>  $lines
     */
    public function problems(string $tone, string $title, iterable $lines = [], ?string $sub = null): self
    {
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), is_array($lines) ? $lines : iterator_to_array($lines, false)), 'filled'));
        $this->blocks[] = ['type' => 'problems', 'tone' => self::tone($tone), 'title' => $title, 'sub' => $sub, 'lines' => $lines];

        return $this;
    }

    /** Before → after as two columns (a list becomes chips; chip(…) sets a chip's tone: added, removed, …). */
    public function compare(string $beforeLabel, mixed $before, string $afterLabel, mixed $after): self
    {
        $this->blocks[] = ['type' => 'compare', 'before' => ['label' => $beforeLabel, 'value' => self::value($before)], 'after' => ['label' => $afterLabel, 'value' => self::value($after)]];

        return $this;
    }

    /**
     * One ruled row: the label, then the value (text, a list shown as chips, or Htmlable), with an optional quiet line
     * under the value. An empty value reads "None" (or $empty).
     */
    public function fact(string $label, mixed $value, ?string $hint = null, ?string $empty = null): self
    {
        return $this->row(['label' => $label, 'value' => self::value($value, $empty), 'hint' => filled($hint) ? $hint : null]);
    }

    /**
     * Several rows at once, in order.
     *
     * @param  array<string, mixed>  $facts  label => value
     */
    public function facts(array $facts): self
    {
        foreach ($facts as $label => $value) {
            $this->fact((string) $label, $value);
        }

        return $this;
    }

    /** One row that changes: before (struck through) → after. */
    public function change(string $label, ?string $before, ?string $after, ?string $hint = null): self
    {
        return $this->row(['label' => $label, 'value' => ['kind' => 'change', 'before' => filled($before) ? $before : null, 'after' => filled($after) ? $after : null], 'hint' => filled($hint) ? $hint : null]);
    }

    /**
     * Headline figures as tiles; $key is the label of the tile that matters most (shown on indigo).
     *
     * @param  array<string, string|int|float|null>  $figures  label => figure
     */
    public function figures(array $figures, ?string $key = null): self
    {
        $this->blocks[] = ['type' => 'figures', 'key' => $key, 'figures' => array_map(fn ($v) => $v === null || $v === '' ? '—' : (string) $v, $figures)];

        return $this;
    }

    /**
     * The people it reaches: one line each (a name, and what it does for them, optionally before → after), then
     * "and N more".
     *
     * @param  list<string|array{name: string, detail?: ?string, before?: ?string, after?: ?string}>  $people
     */
    public function people(string $title, array $people, int $more = 0): self
    {
        $this->blocks[] = ['type' => 'people', 'title' => $title, 'more' => max(0, $more), 'people' => array_map(fn ($p) => is_array($p)
            ? ['name' => (string) ($p['name'] ?? ''), 'detail' => $p['detail'] ?? null, 'before' => $p['before'] ?? null, 'after' => $p['after'] ?? null]
            : ['name' => (string) $p, 'detail' => null, 'before' => null, 'after' => null], array_values($people))];

        return $this;
    }

    /**
     * Lines per employee (or per item) as a table; columns named in $numeric are right-aligned figures.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $numeric  column indexes
     */
    public function table(string $title, array $headers, array $rows, int $more = 0, array $numeric = []): self
    {
        $this->blocks[] = ['type' => 'table', 'title' => $title, 'headers' => $headers, 'rows' => array_map(fn ($r) => array_map(fn ($c) => $c === null ? '' : (string) $c, array_values($r)), $rows),
            'more' => max(0, $more), 'numeric' => $numeric];

        return $this;
    }

    /** A short paragraph inside the body, for a sentence that is part of the outcome itself. */
    public function text(string|Htmlable $text): self
    {
        $this->blocks[] = ['type' => 'text', 'value' => self::value($text)];

        return $this;
    }

    /**
     * Anything else made to fit the card (a before → after preview, a route of approvers), shown as it is.
     */
    public function html(Htmlable $html): self
    {
        $this->blocks[] = ['type' => 'html', 'html' => $html];

        return $this;
    }

    /** One line of small print in the footer, with an icon for what it says (see NOTES) and an optional bold lead-in. */
    public function note(string|Stringable|null $text, string $kind = 'info', ?string $lead = null): self
    {
        $text = trim((string) $text);
        if ($text !== '') {
            $this->notes[] = ['kind' => $kind, 'icon' => self::NOTES[$kind] ?? self::NOTES['info'], 'text' => $text, 'lead' => $lead];
        }

        return $this;
    }

    /** @param  iterable<string|Stringable|null>  $texts */
    public function notes(iterable $texts, string $kind = 'info'): self
    {
        foreach ($texts as $text) {
            $this->note($text, $kind);
        }

        return $this;
    }

    /** A chip with a tone (added: "+ name" in green; removed: struck through in red; info; warning; neutral). */
    public static function chip(string $label, string $tone = 'neutral'): array
    {
        return ['label' => $label, 'tone' => $tone];
    }

    /**
     * A list of chips with one tone.
     *
     * @param  iterable<string>  $labels
     * @return list<array{label: string, tone: string}>
     */
    public static function chips(iterable $labels, string $tone = 'neutral'): array
    {
        $chips = [];
        foreach ($labels as $label) {
            if (filled($label)) {
                $chips[] = self::chip((string) $label, $tone);
            }
        }

        return $chips;
    }

    /** Whether anything was added to the body (blocks or notes) beyond the header. */
    public function isEmpty(): bool
    {
        return $this->blocks === [] && $this->notes === [];
    }

    public function toHtml(): string
    {
        return view('filament.shell.outcome', ['outcome' => [
            'eyebrow' => $this->eyebrow, 'headline' => $this->headline, 'sub' => $this->sub, 'status' => $this->status,
            'icon' => $this->icon, 'blocks' => $this->blocks, 'notes' => $this->notes, 'attributes' => $this->attributes,
        ]])->render();
    }

    public function toHtmlString(): HtmlString
    {
        return new HtmlString($this->toHtml());
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }

    /** @param  array<string, mixed>  $row */
    private function row(array $row): self
    {
        $last = array_key_last($this->blocks);
        if ($last !== null && $this->blocks[$last]['type'] === 'facts') {
            $this->blocks[$last]['rows'][] = $row;
        } else {
            $this->blocks[] = ['type' => 'facts', 'rows' => [$row]];
        }

        return $this;
    }

    private static function tone(string $tone): string
    {
        return in_array($tone, self::TONES, true) ? $tone : 'idle';
    }

    /**
     * A value as the view shows it: text, chips, HTML, or "None".
     *
     * @return array<string, mixed>
     */
    private static function value(mixed $value, ?string $empty = null): array
    {
        if ($value instanceof Htmlable) {
            return ['kind' => 'html', 'html' => $value];
        }
        if (is_array($value)) {
            $chips = [];
            foreach ($value as $item) {
                if (is_array($item) && filled($item['label'] ?? null)) {
                    $chips[] = ['label' => (string) $item['label'], 'tone' => (string) ($item['tone'] ?? 'neutral')];
                } elseif (is_scalar($item) || $item instanceof Stringable) {
                    if (filled((string) $item)) {
                        $chips[] = ['label' => (string) $item, 'tone' => 'neutral'];
                    }
                }
            }

            return $chips === [] ? ['kind' => 'empty', 'text' => $empty ?? 'None'] : ['kind' => 'chips', 'chips' => $chips];
        }
        if (is_bool($value)) {
            return ['kind' => 'text', 'text' => $value ? 'Yes' : 'No'];
        }
        $text = trim((string) ($value ?? ''));

        return $text === '' || $text === '—' ? ['kind' => 'empty', 'text' => $empty ?? 'None'] : ['kind' => 'text', 'text' => $text];
    }
}
