<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\Signatures;
use App\Domain\Offboarding\Support\TableModels;
use App\Domain\Platform\Models\Tenant;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * SaaS.9: builds one tenant export package, resumably.
 *
 * Contents (deterministic: tables in inventory order, rows by primary key, columns in schema order):
 * - data/{category}/{table}.jsonl and .csv for every exported category (the customer's data: their own encrypted fields
 *   decrypted; secrets, credentials and derived lookup hashes never included; operational tables not included);
 * - files/{table}/{row id}/{name} for every file a row references (employee documents, attachments, certificates,
 *   evidence, report and import files); a referenced file that is missing is listed in the manifest, never invented;
 * - manifest.json: format and schema version, export reference, tenant, as_of, categories, every file with its byte size
 *   and SHA-256, record counts, what was excluded; manifest.sig: its HMAC-SHA256 (Signatures, purpose "export-manifest").
 *
 * The package is a ZIP whose every entry is AES-256 encrypted with a per-export passphrase (kept encrypted, revealed only
 * to an authorised downloader). It is written to the private export disk, outside every tenant prefix.
 *
 * Resumable: after every table the checkpoint (finished tables with their counts and hashes) is saved on the export; a
 * worker that stops is replaced by one that starts after the last finished table and rewrites the one in progress.
 * Memory stays flat: rows are streamed in chunks by primary key, files are streamed.
 */
final class ExportPackager
{
    private const CHUNK = 500;

    /** Children never exported: session payloads and password-reset tokens are credentials, not the customer's data. */
    private const NOT_EXPORTED_CHILDREN = ['sessions', 'password_reset_tokens'];

    /**
     * @param  Closure(array): void  $checkpoint  persists the checkpoint and the heartbeat
     * @return array{path: string, size: int, sha256: string, manifest: array, manifest_sha256: string, signature: string, records: int, files: int}
     */
    public function build(TenantExport $export, Tenant $tenant, string $passphrase, Closure $checkpoint): array
    {
        $work = storage_path('app/offboarding-work/'.$export->reference);
        File::ensureDirectoryExists($work);
        $state = $export->checkpoint ?? [];
        $state['tables'] ??= [];
        $state['files'] ??= [];
        $state['as_of'] ??= now()->toIso8601String();
        // Resuming (possibly on another worker): a finished table counts only if its files are here, byte for byte.
        foreach ($state['tables'] as $table => $result) {
            foreach ($result['files'] as $file) {
                if (! is_file("{$work}/{$file['path']}") || hash_file('sha256', "{$work}/{$file['path']}") !== $file['sha256']) {
                    unset($state['tables'][$table]);

                    continue 2;
                }
            }
        }
        foreach ($state['files'] as $table => $result) {
            foreach ($result['copied'] as $file) {
                if (! is_file("{$work}/{$file['path']}") || hash_file('sha256', "{$work}/{$file['path']}") !== $file['sha256']) {
                    unset($state['files'][$table]);

                    continue 2;
                }
            }
        }

        foreach ($this->plan() as [$category, $table]) {
            if (isset($state['tables'][$table])) {
                continue;
            }
            $state['tables'][$table] = $this->exportTable($tenant, $category, $table, $work, fn () => $checkpoint($state));
            $checkpoint($state);
        }
        foreach (DataInventory::FILES as $table => $columns) {
            $category = DataInventory::categoryOf($table);
            if ($category === null || ! DataInventory::CATEGORIES[$category]['export'] || isset($state['files'][$table])) {
                continue;
            }
            $state['files'][$table] = $this->exportFiles($tenant, $table, $columns, $work);
            $checkpoint($state);
        }

        $manifest = $this->manifest($export, $tenant, $state, $work);
        $signature = Signatures::sign('export-manifest', $manifest);
        File::put("{$work}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put("{$work}/manifest.sig", "hmac-sha256:{$signature}\n");
        File::put("{$work}/README.txt", $this->readme($export, $tenant));

        $zip = $this->zip($work, $passphrase, $export->reference);
        $disk = Storage::disk($export->disk);
        $path = 'offboarding-exports/'.$tenant->getKey().'/'.$export->reference.'.zip';
        $stream = fopen($zip, 'rb');
        try {
            if ($disk->writeStream($path, $stream) === false) {
                throw new RuntimeException('The export package could not be written to the export disk.');
            }
        } finally {
            is_resource($stream) && fclose($stream);
        }
        $result = ['path' => $path, 'size' => (int) filesize($zip), 'sha256' => (string) hash_file('sha256', $zip), 'manifest' => $manifest,
            'manifest_sha256' => hash('sha256', Signatures::canonical($manifest)), 'signature' => $signature,
            'records' => (int) $manifest['totals']['records'], 'files' => (int) $manifest['totals']['files']];
        File::delete($zip);
        File::deleteDirectory($work);

        return $result;
    }

    /** Drops a stopped export's working files (a retry starts from its checkpoint; a cancelled one needs nothing). */
    public function discardWork(TenantExport $export): void
    {
        File::deleteDirectory(storage_path('app/offboarding-work/'.$export->reference));
        File::delete(storage_path('app/offboarding-work/'.$export->reference.'.zip'));
    }

    /** @return list<array{0: string, 1: string}> [category, table] in package order */
    public function plan(): array
    {
        $plan = [];
        foreach (DataInventory::CATEGORIES as $category => $definition) {
            if (! $definition['export']) {
                continue;
            }
            foreach (DataInventory::TABLES[$category] as $table) {
                $plan[] = [$category, $table];
            }
            foreach (DataInventory::childrenOf($category) as $child) {
                if (! in_array($child, self::NOT_EXPORTED_CHILDREN, true)) {
                    $plan[] = [$category, $child];
                }
            }
        }

        return $plan;
    }

    /** The rows of one table that belong to the tenant (directly, or through the child's parent). */
    public static function rowsOf(Tenant $tenant, string $table): Builder
    {
        if (isset(DataInventory::CHILDREN[$table])) {
            $child = DataInventory::CHILDREN[$table];
            $parentIds = DB::table($child['parent'])->where('tenant_id', $tenant->getKey())->select($child['parent_column'] ?? 'id');
            $query = DB::table($table)->whereIn($child['column'], $parentIds);
            if (isset($child['morph'])) {
                $query->where($child['morph'], (new User)->getMorphClass());
            }

            return $query;
        }

        return DB::table($table)->where('tenant_id', $tenant->getKey());
    }

    /** @return array{category: string, records: int, files: list<array{path: string, bytes: int, sha256: string}>} */
    private function exportTable(Tenant $tenant, string $category, string $table, string $work, Closure $heartbeat): array
    {
        File::ensureDirectoryExists("{$work}/data/{$category}");
        $excluded = DataInventory::excludedColumns($table);
        $columns = array_values(array_filter(Schema::getColumnListing($table), fn (string $c) => ! in_array($c, $excluded, true)));
        $encrypted = array_intersect_key(TableModels::encryptedColumns($table), array_flip($columns));
        $jsonPath = "{$work}/data/{$category}/{$table}.jsonl";
        $csvPath = "{$work}/data/{$category}/{$table}.csv";
        $json = fopen($jsonPath, 'wb');
        $csv = fopen($csvPath, 'wb');
        fputcsv($csv, $columns, escape: '');
        $records = 0;
        $query = self::rowsOf($tenant, $table)->select($columns);
        $rows = in_array('id', $columns, true) ? $query->lazyById(self::CHUNK, 'id') : $query->orderBy($columns[0])->orderBy($columns[1] ?? $columns[0])->cursor();
        try {
            foreach ($rows as $row) {
                $row = (array) $row;
                foreach ($encrypted as $column => $cast) {
                    $row[$column] = $this->decrypt($row[$column], $cast);
                }
                fwrite($json, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                fputcsv($csv, array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, array_values($row)), escape: '');
                if (++$records % 1000 === 0) {
                    $heartbeat();
                }
            }
        } finally {
            fclose($json);
            fclose($csv);
        }

        $files = [];
        foreach ([$jsonPath, $csvPath] as $file) {
            $files[] = ['path' => substr($file, strlen($work) + 1), 'bytes' => (int) filesize($file), 'sha256' => (string) hash_file('sha256', $file)];
        }

        return ['category' => $category, 'records' => $records, 'files' => $files];
    }

    /**
     * @param  list<array{path: string, disk_column?: string, disk?: string}>  $columns
     * @return array{copied: list<array{path: string, bytes: int, sha256: string}>, missing: list<string>}
     */
    private function exportFiles(Tenant $tenant, string $table, array $columns, string $work): array
    {
        $copied = [];
        $missing = [];
        $select = array_values(array_unique(array_merge(['id'], array_column($columns, 'path'), array_filter(array_column($columns, 'disk_column')))));
        foreach (self::rowsOf($tenant, $table)->select($select)->lazyById(self::CHUNK, 'id') as $row) {
            foreach ($columns as $column) {
                $source = $row->{$column['path']} ?? null;
                if ($source === null || $source === '') {
                    continue;
                }
                $disk = isset($column['disk_column']) ? (string) ($row->{$column['disk_column']} ?: DataInventory::disk('documents')) : DataInventory::disk($column['disk']);
                $target = "files/{$table}/{$row->id}/".preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $source));
                $in = null;
                try {
                    $in = Storage::disk($disk)->readStream($source);
                } catch (Throwable) {
                    $in = null;
                }
                if (! is_resource($in)) {
                    $missing[] = "{$table}#{$row->id}:{$column['path']}";

                    continue;
                }
                File::ensureDirectoryExists(dirname("{$work}/{$target}"));
                $out = fopen("{$work}/{$target}", 'wb');
                stream_copy_to_stream($in, $out);
                fclose($out);
                fclose($in);
                $copied[] = ['path' => $target, 'bytes' => (int) filesize("{$work}/{$target}"), 'sha256' => (string) hash_file('sha256', "{$work}/{$target}")];
            }
        }

        return ['copied' => $copied, 'missing' => $missing];
    }

    private function decrypt(mixed $value, string $cast): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            $plain = Crypt::decryptString((string) $value);
        } catch (Throwable) {
            return '[not decryptable]';
        }

        return $cast === 'encrypted' ? $plain : json_decode($plain, true);
    }

    /** @param  array{tables: array, files: array, as_of: string}  $state */
    private function manifest(TenantExport $export, Tenant $tenant, array $state, string $work): array
    {
        $categories = [];
        $files = [];
        $records = 0;
        foreach ($state['tables'] as $table => $result) {
            $categories[$result['category']]['key'] = $result['category'];
            $categories[$result['category']]['label'] = DataInventory::CATEGORIES[$result['category']]['label'];
            $categories[$result['category']]['tables'][] = ['table' => $table, 'records' => $result['records']];
            $records += $result['records'];
            foreach ($result['files'] as $file) {
                $files[] = $file + ['records' => $result['records']];
            }
        }
        $missing = [];
        foreach ($state['files'] as $result) {
            array_push($files, ...$result['copied']);
            array_push($missing, ...$result['missing']);
        }
        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));
        $attachments = count(array_filter($files, fn (array $f) => str_starts_with($f['path'], 'files/')));

        return [
            'format' => 'peopleos-tenant-export',
            'schema_version' => 1,
            'export' => ['reference' => $export->reference, 'purpose' => $export->purpose, 'as_of' => $state['as_of'], 'generated_at' => now()->toIso8601String()],
            'tenant' => ['id' => $tenant->getKey(), 'slug' => $tenant->slug, 'name' => $tenant->name, 'country_code' => $tenant->country_code],
            'application' => ['name' => (string) config('peopleos.name', 'PeopleOS'), 'schema' => (string) DB::table('migrations')->orderByDesc('id')->value('migration')],
            'categories' => array_values($categories),
            'files' => $files,
            'missing_files' => $missing,
            'totals' => ['records' => $records, 'files' => $attachments, 'bytes' => array_sum(array_column($files, 'bytes'))],
            'excluded' => [
                'columns' => DataInventory::SECRET_COLUMNS,
                'derived_columns' => DataInventory::EXCLUDED_COLUMNS,
                'categories' => array_keys(array_filter(DataInventory::CATEGORIES, fn (array $c) => ! $c['export'])),
                'children' => self::NOT_EXPORTED_CHILDREN,
                'note' => 'Credentials, secrets, derived lookup hashes and PeopleOS operational state are never exported.',
            ],
        ];
    }

    private function readme(TenantExport $export, Tenant $tenant): string
    {
        return "PeopleOS data export {$export->reference} of {$tenant->name}\n\n"
            ."data/<category>/<table>.jsonl  one JSON object per row (the same rows as .csv)\n"
            ."files/<table>/<row id>/        files the rows reference (documents, attachments, certificates, evidence)\n"
            ."manifest.json                  every file with its SHA-256 and record counts; check them with sha256sum\n"
            ."manifest.sig                   HMAC-SHA256 of the manifest by PeopleOS (verifiable by Markedge)\n";
    }

    private function zip(string $work, string $passphrase, string $reference): string
    {
        $zipPath = dirname($work)."/{$reference}.zip";
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('The export package could not be created.');
        }
        $entries = [];
        foreach (File::allFiles($work) as $file) {
            $entries[] = substr($file->getPathname(), strlen($work) + 1);
        }
        sort($entries);
        foreach ($entries as $entry) {
            $zip->addFile("{$work}/{$entry}", $entry);
            if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $passphrase)) {
                throw new RuntimeException('The export package could not be encrypted.');
            }
        }
        if (! $zip->close()) {
            throw new RuntimeException('The export package could not be written.');
        }

        return $zipPath;
    }
}
