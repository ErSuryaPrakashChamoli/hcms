<?php

namespace App\Domain\Configuration\Overrides\Concerns;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use Carbon\CarbonInterface;

/**
 * A configuration model whose resolvers hand out read-only copies carrying one company's overrides (entity-specific
 * overrides of shared records). The copy keeps its id, so consumers can still reference the shared record, but it can
 * never be saved: the shared record changes only through its own screens.
 */
trait CarriesCompanyOverrides
{
    /** The company whose overrides this copy carries (null = the shared record itself). */
    public ?int $overriddenForCompany = null;

    /** @var list<string> */
    public array $overriddenPaths = [];

    public static function bootCarriesCompanyOverrides(): void
    {
        static::saving(function (self $model): void {
            if ($model->overriddenForCompany !== null) {
                throw new ConfigurationException('This copy carries one company\'s overrides and cannot be saved; change the shared record or the override instead.');
            }
        });
    }

    /** The record as the company uses it on a date: a read-only copy with its overrides, or the record itself. */
    public function forCompany(?int $companyId, CarbonInterface|string|null $on = null): static
    {
        $overrides = app(ConfigurationOverrides::class);
        if ($companyId === null || ! $overrides->supports($this) || ! $overrides->hasAny($this->overrideTypeKey(), (int) $this->getKey())) {
            return $this;
        }
        $values = $overrides->valuesFor($this->overrideTypeKey(), (int) $this->getKey(), $companyId, $on);
        if ($values === []) {
            return $this;
        }
        $copy = clone $this;
        foreach ($values as $column => $value) {
            $copy->setAttribute($column, $value);
        }
        $copy->overriddenForCompany = $companyId;
        $copy->overriddenPaths = array_keys($values);

        return $copy;
    }

    abstract protected function overrideTypeKey(): string;
}
