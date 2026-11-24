<?php

namespace App\Domain\Organisation\Resolution;

/**
 * What a session resolved, one entry per distinct (master, company, typed value): the status, the record it resolved to,
 * the candidates, how often it occurred and where (worksheet, row, column; the first few). This is the review's table
 * ("Supplied name | Resolved record | Entity | Status"). Masters are few, so most columns give tens of distinct entries;
 * people are the exception (a large file may name hundreds of managers), which is why the review is handed forReview().
 */
final class ResolutionReport
{
    /** Where each entry remembers it was seen (the rest are counted). */
    public const OCCURRENCES = 20;

    /** At most this many distinct entries are kept; later distinct values are counted in `overflow`. */
    public const ENTRIES = 5000;

    /** What the review keeps of each kind (forReview); a file with more is corrected and uploaded again, not clicked through. */
    public const REVIEW_ATTENTION = 500;

    public const REVIEW_MATCHED = 200;

    public const REVIEW_OCCURRENCES = 3;

    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    private int $overflow = 0;

    /** @var array{attention: int, attention_count: int, matched: int, matched_count: int} review only: what forReview() left out */
    private array $hidden = ['attention' => 0, 'attention_count' => 0, 'matched' => 0, 'matched_count' => 0];

    public function __construct(public readonly ?string $scope = null) {}

    public function record(Resolution $r, ?string $value, ?string $name, ?string $code, ?string $company, ?string $sheet, ?int $row, ?string $column): void
    {
        if ($r->status === ResolutionStatus::Blank) {
            return;
        }
        $key = self::key($r->master, $r->companyId, $value, $name, $code);
        if (! isset($this->entries[$key])) {
            if (count($this->entries) >= self::ENTRIES) {
                $this->overflow++;

                return;
            }
            $this->entries[$key] = [
                'key' => $key, 'master' => $r->master, 'master_label' => $r->masterLabel, 'company_id' => $r->companyId, 'company' => $company,
                'value' => $value, 'name' => $name, 'code' => $code, 'supplied' => $r->supplied,
                'status' => $r->status->value, 'id' => $r->id, 'via' => $r->via, 'record' => $r->record, 'message' => $r->error(), 'warnings' => $r->warnings,
                'candidates' => array_map(fn (Candidate $c) => $c->toArray(), $r->candidates), 'count' => 0, 'at' => [],
            ];
        }
        $this->entries[$key]['count']++;
        if (($sheet !== null || $row !== null) && count($this->entries[$key]['at']) < self::OCCURRENCES) {
            $this->entries[$key]['at'][] = ['sheet' => $sheet, 'row' => $row, 'column' => $column];
        }
    }

    /** The key of an entry: the same reference typed the same way (spacing and case aside) for the same company. */
    public static function key(string $master, ?int $companyId, ?string $value, ?string $name, ?string $code): string
    {
        return sha1(implode("\x1F", [$master, $companyId ?? '', NameKey::key($value), NameKey::key($name), NameKey::key($code)]));
    }

    /** @return list<array<string, mixed>> */
    public function entries(ResolutionStatus ...$statuses): array
    {
        $values = array_map(fn (ResolutionStatus $s) => $s->value, $statuses);

        return array_values(array_filter($this->entries, fn (array $e) => $values === [] || in_array($e['status'], $values, true)));
    }

    /** @return list<array<string, mixed>> entries a row cannot use as they are */
    public function unresolved(): array
    {
        return array_values(array_filter($this->entries, fn (array $e) => ! ResolutionStatus::from($e['status'])->ok()));
    }

    /** @return list<array<string, mixed>> entries a person can settle in the review (suggested, unmatched, ambiguous) */
    public function reviewable(): array
    {
        return array_values(array_filter($this->entries, fn (array $e) => ResolutionStatus::from($e['status'])->reviewable()));
    }

    /** @return array<string, int> status => occurrences */
    public function totals(): array
    {
        $totals = [];
        foreach ($this->entries as $e) {
            $totals[$e['status']] = ($totals[$e['status']] ?? 0) + $e['count'];
        }

        return $totals;
    }

    /** @return array<string, int> via (code | name | mapping | planned) => occurrences of matched references */
    public function matchedVia(): array
    {
        $via = [];
        foreach ($this->entries as $e) {
            if ($e['via'] !== null) {
                $via[$e['via']] = ($via[$e['via']] ?? 0) + $e['count'];
            }
        }

        return $via;
    }

    public function isClean(): bool
    {
        return $this->unresolved() === [];
    }

    /** Several sessions (one per worksheet) into one review. */
    public function merge(self $other): self
    {
        foreach ($other->entries as $key => $entry) {
            if (! isset($this->entries[$key])) {
                $this->entries[$key] = $entry;

                continue;
            }
            $this->entries[$key]['count'] += $entry['count'];
            $this->entries[$key]['at'] = array_slice([...$this->entries[$key]['at'], ...$entry['at']], 0, self::OCCURRENCES);
        }
        $this->overflow += $other->overflow;
        foreach ($other->hidden as $k => $n) {
            $this->hidden[$k] += $n;
        }

        return $this;
    }

    /**
     * The report as the review screen holds it (it travels with every click): everything that needs attention and
     * everything settled by mapping or creation, up to REVIEW_ATTENTION; plain matches up to REVIEW_MATCHED; the first
     * few places of each. What is left out is counted in `hidden`, so the totals stay true.
     */
    public function forReview(): self
    {
        $review = new self($this->scope);
        $review->overflow = $this->overflow;
        $review->hidden = $this->hidden;
        $kept = ['attention' => 0, 'matched' => 0];
        foreach ($this->entries as $key => $entry) {
            $kind = ResolutionStatus::from($entry['status'])->ok() && ! in_array($entry['via'] ?? null, ['mapping', 'created'], true) ? 'matched' : 'attention';
            if ($kept[$kind]++ >= ($kind === 'matched' ? self::REVIEW_MATCHED : self::REVIEW_ATTENTION)) {
                $review->hidden[$kind]++;
                $review->hidden[$kind.'_count'] += (int) $entry['count'];

                continue;
            }
            $review->entries[$key] = ['at' => array_slice($entry['at'], 0, self::REVIEW_OCCURRENCES)] + $entry;
        }

        return $review;
    }

    /** @return array{scope: ?string, entries: list<array<string, mixed>>, overflow: int, hidden: array<string, int>} */
    public function toArray(): array
    {
        return ['scope' => $this->scope, 'entries' => array_values($this->entries), 'overflow' => $this->overflow, 'hidden' => $this->hidden];
    }

    /** @param  array{scope?: ?string, entries?: list<array<string, mixed>>, overflow?: int, hidden?: array<string, int>}  $data */
    public static function fromArray(array $data): self
    {
        $report = new self($data['scope'] ?? null);
        foreach ($data['entries'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['key'], $entry['master'], $entry['status']) && ResolutionStatus::tryFrom((string) $entry['status']) !== null) {
                $report->entries[(string) $entry['key']] = $entry;
            }
        }
        $report->overflow = (int) ($data['overflow'] ?? 0);
        foreach (array_keys($report->hidden) as $k) {
            $report->hidden[$k] = (int) ($data['hidden'][$k] ?? 0);
        }

        return $report;
    }
}
