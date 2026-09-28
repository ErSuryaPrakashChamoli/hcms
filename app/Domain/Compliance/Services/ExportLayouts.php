<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Phase 6.2 export-layout registry: publish (insert-only), maker-checker verification against the
 * authority's upload specification, and structural validation of generated files. A correct
 * calculation does not make an export acceptable; only a verified layout and a file that passes it do.
 */
final class ExportLayouts
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** The layout version to use for new exports: newest that is not rejected or superseded. */
    public function current(string $code): ?StatutoryExportLayout
    {
        return StatutoryExportLayout::query()->where('code', $code)->whereNotIn('status', ['rejected', 'superseded'])->orderByDesc('version')->first();
    }

    /** Header fields a return records for its export layout at generation time. @return array<string, mixed> */
    public function headerFor(string $code): array
    {
        $layout = $this->current($code);

        return ['format_code' => $code, 'format_version' => $layout ? 'v'.$layout->version : null, 'format_verification_status' => $layout?->status, 'export_layout_id' => $layout?->getKey()];
    }

    public function forReturn(object $return): ?StatutoryExportLayout
    {
        return $return->export_layout_id ? StatutoryExportLayout::query()->find($return->export_layout_id) : ($return->format_code ? $this->current($return->format_code) : null);
    }

    /** File-name prefix: an export from a layout that is not verified is marked as such. */
    public function prefixFor(object $return): string
    {
        return $this->forReturn($return)?->isVerified() ? '' : 'UNVERIFIED-FORMAT_';
    }

    /** @param  list<string|int|float|null>  $row */
    public static function csvRow(array $row): string
    {
        return implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row));
    }

    /** Insert layouts from database/data/compliance/layouts/*.php; a changed existing version is refused. */
    public function sync(): Collection
    {
        $synced = collect();

        foreach (glob(database_path('data/compliance/layouts/*.php')) ?: [] as $file) {
            foreach (require $file as $definition) {
                $checksum = StatutoryExportLayout::checksumFor($definition['code'], (int) $definition['version'], $definition['specification']);
                $layout = StatutoryExportLayout::query()->where('code', $definition['code'])->where('version', $definition['version'])->first();

                if ($layout !== null && ! hash_equals($layout->checksum, $checksum)) {
                    throw new RuntimeException("The layout pack changes {$layout->label()}, which is immutable. Publish it as version ".($layout->version + 1).'.');
                }

                $synced->push($layout ?? StatutoryExportLayout::query()->create(collect($definition)->only(['code', 'version', 'name', 'authority', 'specification', 'notes'])->all() + ['status' => 'draft']));
            }
        }

        return $synced;
    }

    public function publishVersion(StatutoryExportLayout $of, array $specification, string $reason, User $actor): StatutoryExportLayout
    {
        $this->platformAdmin($actor);
        if (blank(trim($reason))) {
            throw new RuntimeException('A new layout version needs a reason.');
        }
        $this->assertSpecification($specification);

        $layout = StatutoryExportLayout::query()->create([
            'code' => $of->code, 'version' => (int) StatutoryExportLayout::query()->where('code', $of->code)->max('version') + 1,
            'name' => $of->name, 'authority' => $of->authority, 'specification' => $specification, 'notes' => "New version of {$of->label()}: {$reason}",
        ]);
        $this->auditLayout($layout, AuditAction::Create, $reason, $actor);

        return $layout;
    }

    /** Maker: attach the authority's specification (stored copy) and submit for review. */
    public function submit(StatutoryExportLayout $layout, User $actor, string $sourceUrl, string $sourceTitle, CarbonInterface|string $retrievedAt, string $evidence, string $filename, ?string $notes = null): StatutoryExportLayout
    {
        $this->platformAdmin($actor);
        if (! in_array($layout->status, ['draft', 'review'], true)) {
            throw new RuntimeException("A {$layout->status} layout cannot be submitted.");
        }
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
        $official = str_starts_with(strtolower($sourceUrl), 'https://') && collect(config('peopleos.compliance.authoritative_domains.IN', []))->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d));
        if (! $official) {
            throw new RuntimeException("{$sourceUrl} is not an official source. Use the authority's own upload specification.");
        }
        if ($evidence === '' || blank($sourceTitle)) {
            throw new RuntimeException('Attach the authority\'s upload specification and its title.');
        }

        $sha = hash('sha256', $evidence);
        $path = "compliance-evidence/layouts/{$layout->getKey()}/{$sha}-".(preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename)) ?: 'specification');
        Storage::disk('local')->put($path, $evidence);

        $layout->update(['status' => 'review', 'source_url' => $sourceUrl, 'source_title' => $sourceTitle, 'retrieved_at' => Carbon::parse($retrievedAt)->toDateString(), 'evidence_path' => $path, 'evidence_sha256' => $sha, 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'submission_notes' => $notes]);
        $this->auditLayout($layout, AuditAction::ExportLayoutSubmitted, $notes ?? "Specification {$sourceTitle}", $actor);

        return $layout;
    }

    /** Checker: a different platform administrator confirms the layout matches the specification. */
    public function verify(StatutoryExportLayout $layout, User $verifier, string $notes): StatutoryExportLayout
    {
        $this->platformAdmin($verifier);
        if ($layout->status !== 'review') {
            throw new RuntimeException('Only a layout in review can be verified.');
        }
        if ((int) $layout->submitted_by === (int) $verifier->getKey()) {
            throw new RuntimeException('The person who submitted the layout cannot verify it.');
        }
        if (blank($notes)) {
            throw new RuntimeException('Verification notes are required.');
        }
        if (! $layout->checksumIntact() || $layout->evidence_sha256 === null) {
            throw new RuntimeException('The layout has no intact specification evidence.');
        }

        return DB::transaction(function () use ($layout, $verifier, $notes) {
            $layout->update(['status' => 'verified', 'verified_by' => $verifier->getKey(), 'verified_at' => now(), 'verification_notes' => $notes]);
            StatutoryExportLayout::query()->where('code', $layout->code)->where('version', '<', $layout->version)->where('status', 'verified')->get()
                ->each(fn (StatutoryExportLayout $old) => $old->update(['status' => 'superseded', 'superseded_by_id' => $layout->getKey()]));
            $this->auditLayout($layout, AuditAction::ExportLayoutVerified, $notes, $verifier);

            return $layout;
        });
    }

    public function reject(StatutoryExportLayout $layout, User $actor, string $reason): StatutoryExportLayout
    {
        $this->platformAdmin($actor);
        if (! in_array($layout->status, ['draft', 'review'], true) || blank($reason)) {
            throw new RuntimeException('Only a draft or in-review layout can be rejected, with a reason.');
        }
        $layout->update(['status' => 'rejected', 'verification_notes' => $reason]);
        $this->auditLayout($layout, AuditAction::ExportLayoutRejected, $reason, $actor);

        return $layout;
    }

    /**
     * Structural validation of a generated file against a layout: encoding, line endings, header,
     * field count and order, required fields, value patterns.
     *
     * @return list<string> issues (empty = valid)
     */
    public function validate(StatutoryExportLayout $layout, string $content): array
    {
        $spec = (array) $layout->specification;
        $fields = (array) ($spec['fields'] ?? []);
        $issues = [];

        if (($spec['encoding'] ?? 'UTF-8') === 'ASCII' ? ! mb_check_encoding($content, 'ASCII') : ! mb_check_encoding($content, 'UTF-8')) {
            $issues[] = 'File is not '.($spec['encoding'] ?? 'UTF-8').' encoded.';
        }
        if (($spec['line_ending'] ?? "\n") === "\n" && str_contains($content, "\r")) {
            $issues[] = 'File contains carriage returns; the layout uses LF line endings.';
        }

        $lines = $content === '' ? [] : explode("\n", rtrim($content, "\n"));
        if (($spec['header'] ?? false) === true) {
            $header = $this->split(array_shift($lines) ?? '', $spec);
            if ($header !== array_column($fields, 'name')) {
                $issues[] = 'Header row does not match the layout field names and order.';
            }
        }

        foreach ($lines as $i => $line) {
            $row = $this->split($line, $spec);
            $number = $i + 1;
            if (count($row) !== count($fields)) {
                $issues[] = "Row {$number}: ".count($row).' field(s), layout expects '.count($fields).'.';

                continue;
            }
            foreach ($fields as $index => $field) {
                $value = $row[$index];
                if (($field['required'] ?? false) && $value === '') {
                    $issues[] = "Row {$number}: {$field['name']} is required.";
                } elseif ($value !== '' && isset($field['pattern']) && ! preg_match($field['pattern'], $value)) {
                    $issues[] = "Row {$number}: {$field['name']} value does not match the layout format.";
                }
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function split(string $line, array $spec): array
    {
        if (($spec['quote'] ?? false) === true) {
            return array_map(fn ($v) => (string) $v, str_getcsv($line, $spec['separator'] ?? ',', '"', ''));
        }

        return explode($spec['separator'] ?? ',', $line);
    }

    private function assertSpecification(array $specification): void
    {
        if (($specification['type'] ?? null) !== 'delimited' || blank($specification['separator'] ?? null) || empty($specification['fields'])) {
            throw new RuntimeException('A layout specification needs type "delimited", a separator and fields.');
        }
        foreach ($specification['fields'] as $field) {
            if (blank($field['name'] ?? null) || (isset($field['pattern']) && @preg_match($field['pattern'], '') === false)) {
                throw new RuntimeException('Every field needs a name and, if given, a valid pattern.');
            }
        }
    }

    private function platformAdmin(User $user): void
    {
        if (! $user->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators maintain export layouts.');
        }
    }

    private function auditLayout(StatutoryExportLayout $layout, AuditAction $action, ?string $reason, ?User $actor): void
    {
        $this->audit->record(action: $action, module: 'compliance', entity: $layout, changes: [['field' => 'status', 'before' => null, 'after' => $layout->status, 'sensitive' => false]], reason: $reason, metadata: ['layout' => $layout->label(), 'checksum' => $layout->checksum], entityLabel: $layout->label(), actor: $actor);
    }
}
