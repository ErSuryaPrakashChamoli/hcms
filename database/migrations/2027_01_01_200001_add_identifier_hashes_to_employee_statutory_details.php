<?php

use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-3 (matrix 23 §23.2.3 #8): the hire wizard's returning-employee check compares PAN and Aadhaar reference
 * without decrypting every employee's identifiers. Two lookup hashes on employee_statutory_details — an HMAC (app key) of
 * the normalised value, as statutory_registrations.registration_number_hash does — set by the model on every save and
 * backfilled here from the encrypted values. Never shown, exported or audited (DataInventory::EXCLUDED_COLUMNS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_statutory_details', function (Blueprint $table) {
            $table->string('pan_hash', 64)->nullable()->after('pan');
            $table->string('aadhaar_hash', 64)->nullable()->after('aadhaar_reference');
            $table->index(['tenant_id', 'pan_hash'], 'emp_statutory_tenant_pan_hash_idx');
            $table->index(['tenant_id', 'aadhaar_hash'], 'emp_statutory_tenant_aadhaar_hash_idx');
        });

        DB::table('employee_statutory_details')
            ->where(fn ($q) => $q->whereNotNull('pan')->orWhereNotNull('aadhaar_reference'))
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('employee_statutory_details')->where('id', $row->id)->update([
                        'pan_hash' => EmployeeStatutoryDetail::panHash($this->decrypt($row->pan)),
                        'aadhaar_hash' => EmployeeStatutoryDetail::aadhaarHash($this->decrypt($row->aadhaar_reference)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('employee_statutory_details', function (Blueprint $table) {
            $table->dropIndex('emp_statutory_tenant_pan_hash_idx');
            $table->dropIndex('emp_statutory_tenant_aadhaar_hash_idx');
            $table->dropColumn(['pan_hash', 'aadhaar_hash']);
        });
    }

    /** The `encrypted` cast stores Crypt::encryptString; a value that no longer decrypts (key rotated away) gets no hash. */
    private function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
};
