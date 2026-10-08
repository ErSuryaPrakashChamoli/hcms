<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Services\TaxRules;
use App\Support\Commercial\OperatorChange;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS.7 configuration: the statutory dataset PeopleOS ships (database/data/statutory): the current tax rules and
 * statutory parameters of the launch jurisdictions, researched against official sources, each with its legal
 * reference, URL, source date and verification status. It is data, never code: an operator loads a dataset version
 * (rules arrive pending verification), and a different operator verifies it with a reference, which makes the
 * source-verified values usable from their effective dates. Values the dataset marks pending stay pending until an
 * operator verifies them one by one. A later law is a new dataset version (or an operator's new rule version).
 */
final class StatutoryDataset
{
    public function __construct(private readonly TaxRules $rules, private readonly CommercialConfiguration $configuration, private readonly AuditRecorder $audit) {}

    /** @return list<string> the dataset versions shipped with this release */
    public function versions(): array
    {
        $files = glob(base_path((string) config('peopleos.commercial.statutory_dataset.path')).'/peopleos-statutory-*.json') ?: [];
        $versions = array_map(fn (string $f) => preg_replace('/^.*peopleos-statutory-(.+)\.json$/', '$1', $f), $files);
        sort($versions);

        return $versions;
    }

    /** @return array<string, mixed> */
    public function read(string $version): array
    {
        if (preg_match('/^[0-9]{4}\.[0-9]{2}$/', $version) !== 1 || ! in_array($version, $this->versions(), true)) {
            throw new RuntimeException("No shipped statutory dataset {$version}.");
        }
        $data = json_decode((string) file_get_contents(base_path((string) config('peopleos.commercial.statutory_dataset.path'))."/peopleos-statutory-{$version}.json"), true);
        if (! is_array($data) || ($data['dataset_version'] ?? null) !== $version) {
            throw new RuntimeException("The statutory dataset {$version} is not readable.");
        }

        return $data;
    }

    /** Loads a dataset version (maker). Idempotent: a rule or parameter already loaded is kept. @return array{rules: int, parameters: int} */
    public function load(string $version, string $reason, User $maker): array
    {
        OperatorChange::assert($maker, $reason, 'statutory configuration');
        $data = $this->read($version);

        return DB::transaction(function () use ($version, $data, $reason, $maker) {
            foreach ($data['tax_rules'] as $entry) {
                $this->rules->loadFromDataset($version, $entry, $maker);
            }
            foreach ($data['parameters'] ?? [] as $entry) {
                $this->configuration->loadFromDataset($version, $entry, $maker);
            }
            $counts = ['rules' => count($data['tax_rules']), 'parameters' => count($data['parameters'] ?? [])];
            $this->record(AuditAction::StatutoryDatasetLoaded, $version, $counts, $reason, $maker, ['researched_on' => $data['researched_on'] ?? null]);

            return $counts;
        });
    }

    /**
     * A second operator verifies the dataset: every source-verified rule and parameter it loaded becomes usable from its
     * effective date. Pending values are left for individual verification. @return array{verified: int, pending: int}
     */
    public function activate(string $version, string $reference, User $checker): array
    {
        OperatorChange::assert($checker, $reference, 'statutory configuration');

        return DB::transaction(function () use ($version, $reference, $checker) {
            // Locked first: a concurrent verification waits, then finds nothing pending (each value is verified once).
            $rules = TaxRule::query()->where(['dataset_version' => $version, 'status' => TaxRuleStatus::Review])->orderBy('id')->lockForUpdate()->get();
            $parameters = ConfigurationVersion::query()->where(['dataset_version' => $version, 'status' => ConfigurationVersion::PENDING])->orderBy('id')->lockForUpdate()->get();
            // Values the dataset marks pending stay in review after a verification: they alone are nothing to verify here.
            if ($rules->where('dataset_status', 'source_verified')->isEmpty() && $parameters->isEmpty()) {
                throw new RuntimeException("Nothing of the statutory dataset {$version} waits for verification: it is not loaded, or already verified (values it marks pending are verified one by one).");
            }
            $verified = 0;
            foreach ($rules->where('dataset_status', 'source_verified') as $rule) {
                $this->rules->verify($rule, "{$reference} (statutory dataset {$version})", 'Verified against the dataset\'s official sources', $checker);
                $verified++;
            }
            foreach ($parameters as $parameter) {
                $this->configuration->approveFromDataset($parameter, $checker, "{$reference} (statutory dataset {$version})");
                $verified++;
            }
            $counts = ['verified' => $verified, 'pending' => $rules->where('dataset_status', '<>', 'source_verified')->count()];
            $this->record(AuditAction::StatutoryDatasetActivated, $version, $counts, $reference, $checker, []);

            return $counts;
        });
    }

    /**
     * What a shipped dataset holds and how much of it is loaded, verified or still pending.
     *
     * @return array{loaded: bool, shipped_rules: int, shipped_parameters: int, rules: int, verified: int, pending: int, rejected: int, parameters: int,
     *     parameters_approved: int, researched_on: ?string, pending_items: list<array{key: string, label: string, why: string}>}
     */
    public function status(string $version): array
    {
        $data = $this->read($version);
        $rules = TaxRule::query()->where('dataset_version', $version)->get();
        $parameters = ConfigurationVersion::query()->where('dataset_version', $version)->get();
        $pending = $rules->where('status', TaxRuleStatus::Review)->map(fn (TaxRule $r) => ['key' => (string) $r->dataset_key, 'label' => $r->label(),
            'why' => $r->dataset_status === 'source_verified' ? 'awaiting the second operator\'s verification'
                : (string) ($r->statutory_notes['dataset_verification']['method'] ?? 'pending verification against an official source')])->values()->all();

        return ['loaded' => $rules->isNotEmpty() || $parameters->isNotEmpty(), 'shipped_rules' => count($data['tax_rules']), 'shipped_parameters' => count($data['parameters'] ?? []),
            'rules' => $rules->count(), 'verified' => $rules->where('status', TaxRuleStatus::Verified)->count(), 'pending' => count($pending),
            'rejected' => $rules->where('status', TaxRuleStatus::Rejected)->count(), 'parameters' => $parameters->count(),
            'parameters_approved' => $parameters->where('status', ConfigurationVersion::APPROVED)->count(), 'researched_on' => $data['researched_on'] ?? null, 'pending_items' => $pending];
    }

    /** @param  array<string, mixed>  $counts  @param  array<string, mixed>  $metadata */
    private function record(AuditAction $action, string $version, array $counts, string $reason, User $actor, array $metadata): void
    {
        $this->audit->record($action, 'tax', null, [['field' => 'statutory_dataset', 'before' => null, 'after' => "{$version}: ".json_encode($counts)]], $reason,
            effectiveDate: now()->toDateString(), entityLabel: "Statutory dataset {$version}", actor: $actor,
            metadata: $metadata + ['dataset_version' => $version, 'counts' => $counts, 'correlation_id' => "statutory_dataset:{$version}:".strtolower($action->name)], platform: true);
    }
}
