<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 11.2 — versioned compensation structures and grade ranges. Additive (one unique key is
 | replaced by a wider one; no data is removed).
 |
 | A structure's composition becomes a series of effective-dated, immutable versions. The existing
 | salary_structure_components rows are reused as the version components (no parallel table): each
 | row gains salary_structure_version_id, and every existing structure gets version 1 holding its
 | current rows, in force since 2000-01-01 ("before PeopleOS"), so payroll calculates exactly as before.
 | Approved versions never change; a correction is a new version from a later date.
 |
 | compensation_ranges: minimum / midpoint / maximum per grade (optionally narrowed to a structure,
 | company, job family or designation), effective-dated and approved by a second person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_structure_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_structure_id')->constrained(indexName: 'ssv_structure_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('pay_frequency', 16)->default('monthly');
            // Applicability: one company (null = every company) and a list of grades (null = every grade).
            $table->foreignId('company_id')->nullable()->constrained(indexName: 'ssv_company_fk')->restrictOnDelete();
            $table->json('grade_ids')->nullable();
            $table->text('change_note')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users', indexName: 'ssv_prepared_by_fk')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'ssv_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'version'], 'ssv_version_unique');
            $table->index(['tenant_id', 'salary_structure_id', 'status', 'effective_from'], 'ssv_status_index');
        });

        Schema::table('salary_structure_components', function (Blueprint $table) {
            $table->foreignId('salary_structure_version_id')->nullable()->after('salary_structure_id')->constrained(indexName: 'ssc_version_fk')->restrictOnDelete();
            $table->string('pay_nature', 16)->default('fixed')->after('formula_override');
            $table->string('frequency', 16)->default('monthly')->after('pay_nature');
            // The structure FK keeps its own index once the narrower unique key is replaced below.
            $table->index('salary_structure_id', 'ssc_structure_index');
        });

        // Version 1 of every existing structure: its current composition, in force since 2000-01-01.
        $now = now();
        foreach (DB::table('salary_structures')->orderBy('id')->get() as $structure) {
            $versionId = DB::table('salary_structure_versions')->insertGetId([
                'tenant_id' => $structure->tenant_id, 'salary_structure_id' => $structure->id, 'version' => 1,
                'status' => 'active', 'effective_from' => '2000-01-01', 'currency' => 'INR', 'pay_frequency' => 'monthly',
                'change_note' => 'Version 1: composition carried over when structures became versioned (Phase 11).',
                'approved_at' => $structure->created_at ?? $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('salary_structure_components')->where('salary_structure_id', $structure->id)->update(['salary_structure_version_id' => $versionId]);
        }

        Schema::table('salary_structure_components', function (Blueprint $table) {
            $table->dropUnique('structure_component_unique');
            $table->unique(['salary_structure_version_id', 'salary_component_id'], 'ssc_version_component_unique');
        });

        Schema::create('compensation_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained(indexName: 'comp_ranges_grade_fk')->restrictOnDelete();
            $table->foreignId('salary_structure_id')->nullable()->constrained(indexName: 'comp_ranges_structure_fk')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained(indexName: 'comp_ranges_company_fk')->restrictOnDelete();
            $table->foreignId('job_family_id')->nullable()->constrained(indexName: 'comp_ranges_job_family_fk')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained(indexName: 'comp_ranges_designation_fk')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3);
            $table->string('frequency', 16)->default('annual');
            $table->decimal('minimum', 14, 2);
            $table->decimal('midpoint', 14, 2)->nullable();
            $table->decimal('maximum', 14, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // grade|structure|company|job family|designation|currency|frequency — one definition per key and date.
            $table->string('applicability_key', 191);
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users', indexName: 'comp_ranges_prepared_by_fk')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'comp_ranges_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'grade_id', 'status', 'effective_from'], 'comp_ranges_grade_index');
            $table->index(['tenant_id', 'applicability_key', 'status'], 'comp_ranges_key_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE salary_structure_versions ADD CONSTRAINT ssv_status_check CHECK (status IN ('draft', 'pending_approval', 'scheduled', 'active', 'superseded', 'archived'))");
            DB::statement('ALTER TABLE salary_structure_versions ADD CONSTRAINT ssv_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE salary_structure_versions ADD CONSTRAINT ssv_sod_check CHECK (approved_by IS NULL OR prepared_by IS NULL OR approved_by <> prepared_by)');
            DB::statement('ALTER TABLE compensation_ranges ADD CONSTRAINT comp_ranges_amounts_check CHECK (minimum >= 0 AND maximum >= minimum AND (midpoint IS NULL OR (midpoint >= minimum AND midpoint <= maximum)))');
            DB::statement('ALTER TABLE compensation_ranges ADD CONSTRAINT comp_ranges_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE compensation_ranges ADD CONSTRAINT comp_ranges_status_check CHECK (status IN ('draft', 'pending_approval', 'approved', 'superseded', 'archived'))");
            DB::statement('ALTER TABLE compensation_ranges ADD CONSTRAINT comp_ranges_sod_check CHECK (approved_by IS NULL OR prepared_by IS NULL OR approved_by <> prepared_by)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_ranges');
        // The version foreign key goes first: MySQL let the unique key below serve as its index.
        Schema::table('salary_structure_components', function (Blueprint $table) {
            $table->dropForeign('ssc_version_fk');
        });
        // Rows of later versions would break the restored (structure, component) key: keep version 1 only.
        $later = DB::table('salary_structure_versions')->where('version', '>', 1)->pluck('id');
        DB::table('salary_structure_components')->whereIn('salary_structure_version_id', $later)->delete();
        Schema::table('salary_structure_components', function (Blueprint $table) {
            $table->dropUnique('ssc_version_component_unique');
            $table->unique(['salary_structure_id', 'salary_component_id'], 'structure_component_unique');
        });
        Schema::table('salary_structure_components', function (Blueprint $table) {
            $table->dropIndex('ssc_structure_index');
            $table->dropColumn(['salary_structure_version_id', 'pay_nature', 'frequency']);
        });
        Schema::dropIfExists('salary_structure_versions');
    }
};
