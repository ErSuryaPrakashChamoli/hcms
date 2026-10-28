<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Feature/Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | D7 on MySQL: the json column re-sorts the keys of every stored layout, yet each one verifies, because new layouts
 | are sealed canonically; and two checkers verifying the same layout at the same moment record one verification.
 | Same opt-in as the other suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency").
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-10-05 09:00:00');
    if (! ($GLOBALS['peopleos_concurrency_rules'] ?? false)) {
        syncComplianceRules();
        $GLOBALS['peopleos_concurrency_rules'] = true;
    }
    Storage::fake('local');
    $this->layouts = app(ExportLayouts::class);
});

it('verifies every layout on MySQL although the json column re-sorts its keys', function () {
    $pack = collect(require database_path('data/compliance/layouts/in.php'))->keyBy('code');
    $stored = DB::table('statutory_export_layouts')->where('code', 'EPF_ECR')->where('version', 1)->value('specification');

    expect(array_keys(json_decode((string) $stored, true)))->not->toBe(array_keys($pack['EPF_ECR']['specification'])); // MySQL re-ordered them
    foreach (StatutoryExportLayout::query()->whereIn('code', $pack->keys())->where('version', 1)->get() as $layout) {
        expect($layout->checksum_algorithm)->toBe(StatutoryExportLayout::CANONICAL)
            ->and($layout->checksumIntact())->toBeTrue()
            ->and($this->layouts->integrity($layout))->toBe(ExportLayouts::INTACT);
    }
});

it('records one verification when two checkers verify the same layout at the same moment', function () {
    [$maker, $first, $second] = [platformAdmin(), platformAdmin(), platformAdmin()];
    $layout = app(TenantContext::class)->bypass(fn () => $this->layouts->publishVersion($this->layouts->current('ESI_MC'), $this->layouts->current('ESI_MC')->specification, 'Race '.uniqid(), $maker));
    $this->layouts->submit($layout, $maker, 'https://www.esic.gov.in/spec.pdf', 'Monthly contribution file format', '2026-10-01', '%PDF spec', 'spec.pdf');

    $results = race([
        fn () => $this->layouts->verify(StatutoryExportLayout::query()->findOrFail($layout->id), $first, 'First checker'),
        fn () => $this->layouts->verify(StatutoryExportLayout::query()->findOrFail($layout->id), $second, 'Second checker'),
    ], 0.4, ['eloquent.updating: '.StatutoryExportLayout::class]);

    expect(collect($results)->filter(fn (string $r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn (string $r) => $r === 'ok')->first())->toContain('Only a layout in review can be verified')
        ->and(app(TenantContext::class)->bypass(fn () => AuditEvent::query()->where('action', AuditAction::ExportLayoutVerified->value)->where('entity_id', $layout->id)->where('entity_type', $layout->getMorphClass())->count()))->toBe(1)
        ->and($layout->refresh()->isVerified())->toBeTrue();
});
