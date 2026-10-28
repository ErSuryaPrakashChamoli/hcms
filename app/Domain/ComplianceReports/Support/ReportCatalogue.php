<?php

namespace App\Domain\ComplianceReports\Support;

use App\Domain\ComplianceReports\Contracts\ReportBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The catalogue of Indian HR compliance obligations (database/data/compliance/reports/in.php): the central (all-India)
 * registers, challans and returns, and each state's own (professional tax, labour welfare fund, shops and
 * establishments, factories). A state entry is keyed "{key}:{STATE}" and applies only where the company has an
 * establishment in that state. Builders are wired in peopleos.compliance.report_builders.
 */
final class ReportCatalogue
{
    /** @var Collection<string, ReportDefinition>|null */
    private ?Collection $definitions = null;

    private ?array $data = null;

    /** @return Collection<string, ReportDefinition> */
    public function all(): Collection
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $data = $this->data();
        $definitions = collect();
        foreach ($data['central'] ?? [] as $key => $row) {
            $definitions->put($key, ReportDefinition::fromArray($key, $row));
        }
        foreach ($data['states'] ?? [] as $state => $entry) {
            foreach ($entry['reports'] ?? [] as $key => $row) {
                $definitions->put("{$key}:{$state}", ReportDefinition::fromArray("{$key}:{$state}", $row, $state));
            }
        }

        return $this->definitions = $definitions;
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->all()->get($key);
    }

    /** Older or ISO codes for the same state (the holiday library uses TS for Telangana; compliance rules use TG). */
    private const ALIASES = ['TS' => 'TG', 'CT' => 'CG', 'OR' => 'OD', 'UT' => 'UK', 'DN' => 'DH', 'DD' => 'DH'];

    /** The catalogue's code for a state code as an establishment may carry it. */
    public static function canonicalState(?string $code): ?string
    {
        $code = $code === null ? null : strtoupper(trim($code));

        return $code === null || $code === '' ? null : (self::ALIASES[$code] ?? $code);
    }

    /** @return array<string, string> every state and union territory, code => name */
    public function states(): array
    {
        return collect($this->data()['states'] ?? [])->map(fn (array $entry) => (string) $entry['name'])->all();
    }

    /** @return array<string, mixed> a state's summary (PT, LWF, shops and establishments notes) */
    public function state(string $code): array
    {
        return $this->data()['states'][$code] ?? [];
    }

    public function builder(ReportDefinition $definition): ReportBuilder
    {
        $class = config("peopleos.compliance.report_builders.{$definition->builder}");
        if ($class === null || ! is_subclass_of($class, ReportBuilder::class)) {
            throw new InvalidArgumentException("No report builder is registered for {$definition->builder}.");
        }

        return app($class);
    }

    /** As of when the catalogue was reviewed, and what it is (shown on the page and in every pack's index). */
    public function reviewed(): array
    {
        return (array) ($this->data()['reviewed'] ?? []);
    }

    /** SHA-256 of the catalogue file: recorded on every pack, so a pack names the catalogue revision it followed. */
    public function checksum(): string
    {
        return hash_file('sha256', $this->path());
    }

    private function data(): array
    {
        return $this->data ??= require $this->path();
    }

    private function path(): string
    {
        return database_path('data/compliance/reports/in.php');
    }
}
