<?php

namespace App\Domain\Letters\Support;

use Stringable;

/**
 * The salary annexure a letter prints for {{ salary.annexure }}: earnings, employer contributions, gross and CTC,
 * monthly and yearly. Rows are kind heading | line | total | note; amounts are already rounded (null = not monthly).
 */
final class SalaryAnnexure implements Stringable
{
    /** @param  list<array{kind: string, label: string, monthly: ?float, annual: ?float}>  $rows */
    public function __construct(public readonly string $currency, public readonly array $rows) {}

    public static function money(?float $amount): string
    {
        return $amount === null ? '—' : number_format($amount, 2);
    }

    public function toHtml(): string
    {
        $cell = 'border:1px solid #999;padding:4px 8px';
        $html = '<table class="salary-annexure" style="border-collapse:collapse;width:100%;margin:8px 0">'
            .'<thead><tr><th style="'.$cell.';text-align:left">Component</th><th style="'.$cell.';text-align:right">Monthly ('.e($this->currency).')</th><th style="'.$cell.';text-align:right">Yearly ('.e($this->currency).')</th></tr></thead><tbody>';
        foreach ($this->rows as $row) {
            if ($row['kind'] === 'heading' || $row['kind'] === 'note') {
                $html .= $row['kind'] === 'heading'
                    ? '<tr><td colspan="3" style="'.$cell.';background:#f2f2f2"><strong>'.e($row['label']).'</strong></td></tr>'
                    : '<tr><td colspan="3" style="'.$cell.';font-size:12px;color:#555">'.e($row['label']).'</td></tr>';

                continue;
            }
            [$open, $close] = $row['kind'] === 'total' ? ['<strong>', '</strong>'] : ['', ''];
            $html .= '<tr><td style="'.$cell.'">'.$open.e($row['label']).$close.'</td>'
                .'<td style="'.$cell.';text-align:right">'.$open.self::money($row['monthly']).$close.'</td>'
                .'<td style="'.$cell.';text-align:right">'.$open.self::money($row['annual']).$close.'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    /** @return list<array{kind: string, label: string, monthly: ?float, annual: ?float}> */
    public function toArray(): array
    {
        return $this->rows;
    }

    /** Plain text, for places that cannot hold a table (a subject line, a text preview). */
    public function __toString(): string
    {
        return collect($this->rows)->map(fn (array $row) => in_array($row['kind'], ['heading', 'note'], true)
            ? $row['label']
            : "{$row['label']}: ".self::money($row['monthly']).' monthly, '.self::money($row['annual']).' yearly')->implode('; ');
    }
}
