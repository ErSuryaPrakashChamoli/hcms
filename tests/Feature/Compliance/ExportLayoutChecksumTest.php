<?php

use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 | D7: export-layout checksums hold on every database. MySQL's json column re-sorts object keys on storage, so new
 | layouts are sealed canonically (keys sorted, list order kept); layouts sealed with the original algorithm keep it
 | and are verified exactly as before. One broken only by key order holds only when proven against the layout pack
 | (owner decision B, 2026-10-27): its stored checksum is the original algorithm's checksum of the pack definition,
 | and its content equals that definition. Nothing stored is ever rewritten. Runs on SQLite and, with
 | DB_CONNECTION=mysql, on MySQL; the outcomes are the same on both.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-27 09:00:00');
    $this->layouts = app(ExportLayouts::class);
    $this->pack = collect(require database_path('data/compliance/layouts/in.php'))->keyBy('code');
    $this->sync = fn () => app(TenantContext::class)->bypass(fn () => $this->layouts->sync());
    // A row as the original code sealed it: sha256 of the specification as written, no algorithm recorded.
    $this->originalRow = fn (string $code, array $stored, ?array $sealed = null): int => DB::table('statutory_export_layouts')->insertGetId([
        'code' => $code, 'version' => 1, 'name' => "{$code} (original algorithm)", 'specification' => json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'checksum' => StatutoryExportLayout::checksumFor($code, 1, $sealed ?? $stored, null), 'checksum_algorithm' => null, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // The pack's EPF layout with its keys re-sorted at both levels, as a database that re-orders keys returns it.
    $this->resorted = fn (array $specification): array => collect($specification)->sortKeys()
        ->map(fn ($value, $key) => $key === 'fields' ? array_map(fn (array $field) => collect($field)->sortKeys()->all(), $value) : $value)->all();
    $this->row = fn (string $code) => (array) DB::table('statutory_export_layouts')->where('code', $code)->sole();
});

it('gives one checksum to the same specification in any key order, and another when a list is reordered', function () {
    $spec = $this->pack['EPF_ECR']['specification'];
    $reordered = array_reverse($spec, true);
    $reordered['fields'] = array_map(fn (array $field) => array_reverse($field, true), $spec['fields']);
    $checksum = StatutoryExportLayout::checksumFor('EPF_ECR', 1, $spec);

    expect(StatutoryExportLayout::checksumFor('EPF_ECR', 1, $reordered))->toBe($checksum)
        // The original algorithm is order-sensitive: the defect D7 records.
        ->and(StatutoryExportLayout::checksumFor('EPF_ECR', 1, $reordered, null))->not->toBe(StatutoryExportLayout::checksumFor('EPF_ECR', 1, $spec, null));

    $swapped = $spec;
    [$swapped['fields'][0], $swapped['fields'][1]] = [$spec['fields'][1], $spec['fields'][0]];
    expect(StatutoryExportLayout::checksumFor('EPF_ECR', 1, $swapped))->not->toBe($checksum)
        ->and(StatutoryExportLayout::checksumFor('EPF_ECR', 2, $spec))->not->toBe($checksum)
        ->and(StatutoryExportLayout::checksumFor('ESI_MC', 1, $spec))->not->toBe($checksum)
        ->and(fn () => StatutoryExportLayout::checksumFor('EPF_ECR', 1, $spec, 'md5'))->toThrow(RuntimeException::class, 'Unknown export layout checksum algorithm');
});

it('seals every synced layout canonically and reads each back intact on this database', function () {
    ($this->sync)();

    $layouts = StatutoryExportLayout::query()->get();
    expect($layouts)->toHaveCount($this->pack->count())
        ->and($layouts->every(fn (StatutoryExportLayout $l) => $l->checksum_algorithm === StatutoryExportLayout::CANONICAL && $l->checksumIntact()))->toBeTrue()
        ->and($layouts->map(fn (StatutoryExportLayout $l) => $this->layouts->integrity($l))->unique()->values()->all())->toBe([ExportLayouts::INTACT])
        // The stored checksum is the pack definition's own; the database's key order plays no part.
        ->and($layouts->every(fn (StatutoryExportLayout $l) => $l->checksum === StatutoryExportLayout::checksumFor($l->code, $l->version, $this->pack[$l->code]['specification'])))->toBeTrue();
});

it('keeps nulls, Unicode, numbers, booleans and empty objects and lists stable through the database', function () {
    $spec = ['type' => 'delimited', 'separator' => '|', 'zeta' => null, 'title' => 'मराठी ₹ é', 'path' => 'a/b', 'ratio' => 0.1, 'whole' => 1.0, 'big' => 12345678901234, 'negative' => -5, 'flag' => false,
        'empty_list' => [], 'empty_object' => new stdClass, 'nested' => ['b' => ['y' => 2, 'x' => 1], 'a' => [3, 1, 2]], 'fields' => [['name' => 'A', 'required' => true]]];
    $layout = StatutoryExportLayout::query()->create(['code' => 'D7_EDGE', 'version' => 1, 'name' => 'Edge values', 'specification' => $spec]);
    $fresh = $layout->fresh();

    expect($fresh->checksumIntact())->toBeTrue()
        ->and($fresh->checksum)->toBe($layout->checksum)
        ->and($fresh->specification['nested']['a'])->toBe([3, 1, 2])
        ->and($fresh->specification['title'])->toBe('मराठी ₹ é')
        ->and($fresh->specification['zeta'])->toBeNull();
    // Equal values written differently hash alike (1.0 and 1; an empty object and an empty list) ...
    expect(StatutoryExportLayout::checksumFor('D7_EDGE', 1, ['whole' => 1, 'empty_object' => []] + $spec))->toBe($layout->checksum);
    // ... and every change of value does not.
    foreach (['zeta' => '', 'title' => 'मराठी ₹ e', 'ratio' => 0.10000001, 'big' => 12345678901235, 'negative' => 5, 'flag' => 0, 'path' => 'a\\/b', 'empty_list' => [null]] as $key => $value) {
        expect(StatutoryExportLayout::checksumFor('D7_EDGE', 1, [$key => $value] + $spec))->not->toBe($layout->checksum);
    }
});

it('verifies a layout sealed with the original algorithm exactly as before when its stored key order is unchanged', function () {
    // Keys already in the order MySQL keeps (shorter first, then bytewise), so every database returns them as written.
    $spec = ['type' => 'delimited', 'quote' => false, 'fields' => [['name' => 'A', 'pattern' => '/^A$/']], 'header' => true, 'separator' => ','];
    $layout = StatutoryExportLayout::query()->findOrFail(($this->originalRow)('D7_ORIGINAL', $spec));

    expect($layout->sealedWithOriginalAlgorithm())->toBeTrue()
        ->and($layout->checksumIntact())->toBeTrue()
        ->and($this->layouts->integrity($layout))->toBe(ExportLayouts::INTACT)
        // Its seal is the original one, not a canonical replacement.
        ->and($layout->checksum)->not->toBe(StatutoryExportLayout::checksumFor('D7_ORIGINAL', 1, $spec));
});

it('proves an original-algorithm layout broken only by key order against the layout pack, verifies it and rewrites nothing', function () {
    $definition = $this->pack['EPF_ECR'];
    $id = ($this->originalRow)('EPF_ECR', ($this->resorted)($definition['specification']), $definition['specification']);
    $sealed = fn () => array_intersect_key(($this->row)('EPF_ECR'), array_flip(['checksum', 'checksum_algorithm', 'specification']));
    $before = $sealed();

    ($this->sync)(); // the pack still matches the stored seal, so sync accepts the row
    $layout = StatutoryExportLayout::query()->findOrFail($id);
    expect($layout->checksumIntact())->toBeFalse() // the checksum does not reproduce from the stored key order ...
        ->and($this->layouts->integrity($layout))->toBe(ExportLayouts::INTACT_AGAINST_PACK) // ... but it is the pack definition's seal
        ->and($layout->sealHolds())->toBeTrue()
        ->and(StatutoryExportLayout::query()->where('code', '!=', 'EPF_ECR')->get()->every(fn (StatutoryExportLayout $l) => $l->checksumIntact()))->toBeTrue();

    // Proving the seal is not verifying the format: the maker-checker review with official evidence still applies.
    [$maker, $checker] = [platformAdmin(), platformAdmin()];
    expect($layout->isVerified())->toBeFalse();
    $this->layouts->submit($layout, $maker, 'https://www.epfindia.gov.in/ecr.pdf', 'ECR file format', '2026-10-20', '%PDF spec', 'ecr.pdf');
    $this->layouts->verify($layout->refresh(), $checker, 'Matches the help file');

    expect($layout->refresh()->status)->toBe('verified')
        ->and($layout->isVerified())->toBeTrue()
        ->and($sealed())->toBe($before); // checksum, algorithm and stored content untouched
});

it('never proves an original-algorithm layout whose content, pack definition or pack entry does not match', function () {
    $pack = $this->pack['ESI_MC']['specification'];
    $changed = $pack;
    $changed['fields'][0]['name'] = 'Changed';
    [$maker, $checker] = [platformAdmin(), platformAdmin()];

    $cases = [
        // Sealed from the pack, but the stored content was changed afterwards.
        'tampered content' => ['ESI_MC', ($this->resorted)($changed), $pack],
        // Sealed from a definition other than the pack's (the pack changed, or the row came from elsewhere).
        'other definition' => ['ESI_MC', ($this->resorted)($changed), $changed],
        // No definition of this code and version in the pack: nothing to prove it against.
        'no pack entry' => ['D7_NOPACK', ($this->resorted)($pack), $pack],
    ];
    foreach ($cases as $case => [$code, $stored, $sealedFrom]) {
        DB::table('statutory_export_layouts')->where('code', $code)->delete();
        $layout = StatutoryExportLayout::query()->findOrFail(($this->originalRow)($code, $stored, $sealedFrom));
        $before = ($this->row)($code);

        expect($layout->checksumIntact())->toBeFalse($case)
            ->and($this->layouts->integrity($layout))->toBe(ExportLayouts::MISMATCH, $case)
            ->and($layout->sealHolds())->toBeFalse($case);

        $this->layouts->submit($layout, $maker, 'https://www.esic.gov.in/mc.pdf', 'MC file format', '2026-10-20', '%PDF spec', 'mc.pdf');
        expect(fn () => $this->layouts->verify($layout->refresh(), $checker, 'Looks right'))->toThrow(RuntimeException::class, 'no intact specification evidence');

        DB::table('statutory_export_layouts')->where('id', $layout->id)->update(['status' => 'verified']);
        expect($layout->refresh()->isVerified())->toBeFalse($case)
            ->and(array_intersect_key(($this->row)($code), array_flip(['checksum', 'checksum_algorithm', 'specification'])))
            ->toBe(array_intersect_key($before, array_flip(['checksum', 'checksum_algorithm', 'specification'])));
    }
});

it('reports a changed specification, a corrupted checksum or an unknown algorithm as a mismatch', function () {
    ($this->sync)();
    $original = ($this->row)('PT_RETURN');
    $set = fn (array $values) => DB::table('statutory_export_layouts')->where('code', 'PT_RETURN')->update($values);
    $state = fn () => [StatutoryExportLayout::query()->where('code', 'PT_RETURN')->sole()->checksumIntact(), $this->layouts->integrity(StatutoryExportLayout::query()->where('code', 'PT_RETURN')->sole())];
    $changed = json_decode($original['specification'], true);
    $changed['fields'][0]['name'] = 'Changed';

    $set(['specification' => json_encode($changed)]);
    expect($state())->toBe([false, ExportLayouts::MISMATCH]);

    $set(['specification' => $original['specification'], 'checksum' => str_repeat('0', 64)]);
    expect($state())->toBe([false, ExportLayouts::MISMATCH]);

    $set(['checksum' => $original['checksum'], 'checksum_algorithm' => 'md5']);
    expect($state())->toBe([false, ExportLayouts::MISMATCH]);

    // An original-algorithm seal whose content no longer equals the pack is tampering, not key order.
    $set(['checksum_algorithm' => null, 'checksum' => StatutoryExportLayout::checksumFor('PT_RETURN', 1, $this->pack['PT_RETURN']['specification'], null), 'specification' => json_encode(($this->resorted)($changed))]);
    expect($state())->toBe([false, ExportLayouts::MISMATCH]);

    $set(['checksum_algorithm' => $original['checksum_algorithm'], 'checksum' => $original['checksum'], 'specification' => $original['specification']]);
    expect($state())->toBe([true, ExportLayouts::INTACT]);
});

it('checks integrity repeatedly without writing anything', function () {
    ($this->sync)();
    $snapshot = fn () => DB::table('statutory_export_layouts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $before = $snapshot();
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        $writes += (int) preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql);
    });

    foreach (range(1, 3) as $_) {
        foreach (StatutoryExportLayout::query()->get() as $layout) {
            expect($layout->checksumIntact())->toBeTrue()
                ->and($this->layouts->integrity($layout))->toBe(ExportLayouts::INTACT)
                ->and($layout->isVerified())->toBeFalse();
        }
    }
    $this->artisan('peopleos:compliance:layout-checksums')->assertExitCode(0);

    expect($writes)->toBe(0)->and($snapshot())->toBe($before);
});

it('never lets a sealed layout change its algorithm or its checksum', function () {
    ($this->sync)();
    $layout = StatutoryExportLayout::query()->where('code', 'EPF_ECR')->sole();

    expect(fn () => $layout->forceFill(['checksum_algorithm' => null])->save())->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $layout->fresh()->forceFill(['checksum' => str_repeat('a', 64)])->save())->toThrow(RuntimeException::class, 'immutable')
        ->and($layout->fresh()->checksumIntact())->toBeTrue();
});

it('reports each layout\'s algorithm and integrity, failing only on a real mismatch', function () {
    ($this->originalRow)('EPF_ECR', ($this->resorted)($this->pack['EPF_ECR']['specification']), $this->pack['EPF_ECR']['specification']);
    ($this->sync)();

    $this->artisan('peopleos:compliance:layout-checksums')
        ->expectsOutputToContain('Integrity: intact_against_pack 1, intact '.($this->pack->count() - 1))
        ->expectsOutputToContain('Nothing was rewritten')
        ->assertExitCode(0);

    DB::table('statutory_export_layouts')->where('code', 'PT_RETURN')->update(['checksum' => str_repeat('0', 64)]);
    $this->artisan('peopleos:compliance:layout-checksums')->expectsOutputToContain('mismatch 1')->assertExitCode(1);
});

it('reads a row whose algorithm was not retrieved as sealed with the original algorithm, failing closed for a canonical seal', function () {
    ($this->sync)();
    $columns = ['id', 'code', 'version', 'name', 'specification', 'checksum', 'status'];

    // A database before migration 2026_11_09_100004 has no algorithm column: its rows were sealed with the original
    // algorithm and still read without an error. A canonical row read that way does not pass.
    $canonical = StatutoryExportLayout::query()->select($columns)->where('code', 'EPF_ECR')->sole();
    expect($canonical->checksumAlgorithm())->toBeNull()
        ->and($canonical->checksumIntact())->toBeFalse()
        ->and($canonical->isVerified())->toBeFalse();

    $spec = ['type' => 'delimited', 'quote' => false, 'fields' => [['name' => 'A', 'pattern' => '/^A$/']], 'header' => true, 'separator' => ','];
    $original = StatutoryExportLayout::query()->select($columns)->findOrFail(($this->originalRow)('D7_ORIGINAL', $spec));
    expect($original->sealedWithOriginalAlgorithm())->toBeTrue()->and($original->checksumIntact())->toBeTrue();
});
