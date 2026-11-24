<?php

namespace App\Domain\Organisation\Resolution;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Resolution\Index\ActorVisibility;
use App\Domain\Organisation\Resolution\Index\Columns;
use App\Domain\Organisation\Resolution\Models\MasterReferenceMapping;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What a person can do with a reference that did not resolve: confirm which record it means (a mapping, for this upload
 * or remembered for every upload), retire a mapping, or create the missing master. Every change is audited; nothing is
 * deleted; a value is never mapped or created silently.
 */
final class ReferenceMappings
{
    public function __construct(private readonly MasterResolver $resolver, private readonly MasterCatalogue $catalogue) {}

    /**
     * Confirm that `$value` (as typed in a file) means record `$recordId`. `$scope` is the upload (required unless the
     * mapping is remembered); `$remember` keeps it for every later upload and needs the right to manage that master.
     * `$companyId` limits the mapping to rows of that company (null = any company the record applies to).
     */
    public function map(User $actor, string $master, string $value, int $recordId, ?int $companyId, ?string $scope, bool $remember = false, ?string $reason = null): MasterReferenceMapping
    {
        $spec = $this->catalogue->for($master);
        $value = trim($value);
        $key = NameKey::key($value);
        if ($key === '' || mb_strlen($value) > 191) {
            throw new ResolutionRefusedException('Give the value as typed in the file (up to 191 characters).');
        }
        if ($remember && $spec->isEmployee()) {
            throw new ResolutionRefusedException('A name is never remembered for a person: names change and repeat. Use the employee code in files.');
        }
        if ($remember && ($spec->permission === null || ! $actor->hasPermission($spec->permission, $companyId))) {
            throw new ResolutionRefusedException("Remembering what a value means for every upload needs the right to manage {$spec->label} records. Map it for this upload only.");
        }
        if (! $remember && ($scope === null || trim($scope) === '')) {
            throw new ResolutionRefusedException('A mapping that is not remembered belongs to one upload; say which.');
        }

        $session = $this->resolver->session($actor, $remember ? null : $scope);
        $index = $session->index($spec);
        $row = $index->find($recordId);
        if ($row === null) {
            throw new ResolutionRefusedException("That is not a {$spec->lower()} you can use.");
        }
        if ($companyId !== null && ! $spec->isEmployee() && ! $index->appliesTo($recordId, $companyId)) {
            throw new ResolutionRefusedException("{$row['name']} does not apply to {$session->companyName($companyId)}.");
        }
        $exact = $session->resolve($spec->key, name: $value, companyId: $companyId);
        if ($exact->status === ResolutionStatus::Matched && $exact->via === Resolution::VIA_NAME && $exact->id !== $recordId) {
            throw new ResolutionRefusedException("'{$value}' is already the exact name of {$exact->record}. Use the code in the file to mean another record.");
        }

        $scope = $remember ? null : $scope;
        $activeKey = MasterReferenceMapping::activeKey($spec->key, $companyId, $key, $scope);
        $reason = $reason ?: "'{$value}' means {$row['name']}".($scope !== null ? " in {$scope}" : ' in every upload');

        return DB::transaction(function () use ($actor, $spec, $value, $key, $recordId, $companyId, $scope, $activeKey, $reason) {
            MasterReferenceMapping::query()->where('active_key', $activeKey)->lockForUpdate()->get()
                ->each(fn (MasterReferenceMapping $old) => $old->withAuditReason("Replaced: {$reason}")->update([
                    'status' => MasterReferenceMapping::RETIRED, 'active_key' => null, 'retired_by' => $actor->getKey(), 'retired_at' => now(),
                ]));

            $mapping = new MasterReferenceMapping([
                'master' => $spec->key, 'company_id' => $companyId, 'value' => $value, 'value_key' => $key, 'record_id' => $recordId,
                'scope' => $scope, 'status' => MasterReferenceMapping::ACTIVE, 'active_key' => $activeKey, 'reason' => mb_substr($reason, 0, 500),
                'created_by' => $actor->getKey(),
            ]);
            $mapping->withAuditReason($reason)->save();

            return $mapping;
        });
    }

    public function retire(User $actor, MasterReferenceMapping $mapping, ?string $reason = null): void
    {
        $spec = $this->catalogue->for($mapping->master);
        if ($mapping->scope === null && ($spec->permission === null || ! $actor->hasPermission($spec->permission, $mapping->company_id))) {
            throw new ResolutionRefusedException("Retiring a remembered mapping needs the right to manage {$spec->label} records.");
        }
        if ($mapping->status !== MasterReferenceMapping::ACTIVE) {
            return;
        }
        $mapping->withAuditReason($reason ?: "No longer means record #{$mapping->record_id}")->update([
            'status' => MasterReferenceMapping::RETIRED, 'active_key' => null, 'retired_by' => $actor->getKey(), 'retired_at' => now(),
        ]);
    }

    /**
     * Create a missing master from a value that matched nothing, through the master's own create permission. A
     * company-owned unit (department, location, …) is created in `$companyId`. A tenant-wide master (designation, grade,
     * …) applies to `$companyId` only unless `$allCompanies` (companies created later never join silently). Refused when
     * a record of that name already exists for the company: extend that one instead of creating a duplicate.
     */
    public function create(User $actor, string $master, string $name, ?int $companyId, ?string $code = null, bool $allCompanies = false, ?string $reason = null): Model
    {
        $spec = $this->catalogue->for($master);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (! $spec->creatable) {
            throw new ResolutionRefusedException("{$spec->label} records are created on their own screen, not from an import.");
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new ResolutionRefusedException('Give a name (up to 150 characters).');
        }
        if (! Gate::forUser($actor)->allows('create', $spec->model)) {
            throw new ResolutionRefusedException("You may not add {$spec->label} records.");
        }
        if ($spec->ownerColumn !== null && $companyId === null) {
            throw new ResolutionRefusedException("A {$spec->lower()} belongs to a company: say which.");
        }
        if ($companyId !== null) {
            $visible = ActorVisibility::ids(Company::class, $actor, [$companyId]);
            $exists = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($companyId)->exists();
            if (! $exists || ($visible !== null && ! isset($visible[$companyId]))) {
                throw new ResolutionRefusedException('That company is not one you can add records to.');
            }
        }

        $tenant = (int) app(TenantContext::class)->id();
        $lock = Cache::lock("master-create:{$tenant}:{$spec->key}:".sha1(NameKey::key($name)), 15);

        return $lock->block(10, function () use ($actor, $spec, $name, $companyId, $code, $allCompanies, $reason) {
            // Checked as the system, not as the actor: a record the actor cannot see still makes a new one a duplicate.
            $check = $this->resolver->session(null)->resolve($spec->key, name: $name, companyId: $companyId);
            $taken = in_array($check->status, [ResolutionStatus::Matched, ResolutionStatus::Ambiguous], true)
                || ($check->status === ResolutionStatus::NotApplicable && $spec->ownerColumn === null);
            if ($taken) {
                throw new ResolutionRefusedException($check->status === ResolutionStatus::NotApplicable
                    ? "A {$spec->lower()} named '{$name}' already exists but does not apply to this company. Extend where it applies instead of creating a second one."
                    : "A {$spec->lower()} named '{$name}' already exists".($companyId !== null ? ' for this company' : '').'. Map the value to it instead.');
            }

            $reason = $reason ?: "Created from an import: '{$name}' matched no {$spec->lower()}";

            $created = null;
            // operation() returns its id, not the callback's value: the record comes back through $created.
            app(AuditRecorder::class)->operation('organisation', "Create {$spec->lower()} from an import", function () use ($actor, $spec, $name, $companyId, $code, $allCompanies, $reason, &$created) {
                $created = DB::transaction(function () use ($actor, $spec, $name, $companyId, $code, $allCompanies, $reason) {
                    $record = $this->insert($spec, $name, $companyId, $code, $reason);
                    $type = app(ConfigurationTypes::class)->has($spec->key) ? app(ConfigurationTypes::class)->for($spec->key) : null;
                    if ($spec->ownerColumn === null && $type !== null && $type->share && ($companyId !== null || $allCompanies)) {
                        app(EntityApplicability::class)->apply($record, $allCompanies
                            ? ApplicabilitySelection::all(includeFuture: false)
                            : ApplicabilitySelection::single((int) $companyId), $actor, $reason);
                    }

                    return $record;
                });

                return 1;
            }, $reason, $spec->model);

            return $created;
        });
    }

    private function insert(MasterSpec $spec, string $name, ?int $companyId, ?string $code, string $reason): Model
    {
        /** @var Model $model */
        $model = new ($spec->model);
        $table = $model->getTable();
        $codeColumn = $spec->codeColumn;
        $given = $code !== null && trim($code) !== '';
        if ($given && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\.\/]{0,31}$/', trim($code))) {
            throw new ResolutionRefusedException('A code is up to 32 letters, digits, - _ . or /, starting with a letter or digit.');
        }
        $base = $given ? trim($code) : self::codeFrom($name);

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $candidate = $attempt === 1 ? $base : mb_substr($base, 0, 28).'_'.$attempt;
            if ($codeColumn !== null && $this->codeTaken($model, $codeColumn, $candidate)) {
                if ($given) {
                    throw new ResolutionRefusedException("The code {$candidate} is already in use.");
                }

                continue;
            }
            $attributes = [$spec->nameColumns[0] => $name];
            if ($codeColumn !== null) {
                $attributes[$codeColumn] = $candidate;
            }
            if ($spec->ownerColumn !== null) {
                $attributes[$spec->ownerColumn] = $companyId;
            }
            if (Columns::has($table, 'status')) {
                $attributes['status'] = 'active';
            }
            if (Columns::has($table, 'effective_from')) {
                $attributes['effective_from'] = today()->toDateString();
            }
            $record = $model->newInstance()->forceFill($attributes);
            try {
                // A savepoint, so a code taken between the check and the insert can be retried inside the transaction.
                DB::transaction(fn () => method_exists($record, 'withAuditReason') ? $record->withAuditReason($reason)->save() : $record->save());

                return $record;
            } catch (UniqueConstraintViolationException $e) {
                if ($given) {
                    throw new ResolutionRefusedException("The code {$candidate} is already in use.");
                }
            }
        }

        throw new ResolutionRefusedException('No free code could be found; give one.');
    }

    private function codeTaken(Model $model, string $column, string $code): bool
    {
        $query = $model->newQueryWithoutScope(AccessScope::class);
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return $query->whereRaw('lower('.$model->qualifyColumn($column).') = ?', [mb_strtolower($code)])->exists();
    }

    /** "Sales & Business Development" → SALES_AND_BUSINESS_DEV (letters and digits, at most 20). */
    public static function codeFrom(string $name): string
    {
        $code = Str::upper(Str::of(Str::ascii(str_replace('&', ' and ', $name)))->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->value());
        $code = rtrim(mb_substr($code, 0, 20), '_');

        return $code !== '' ? $code : 'NEW';
    }
}
