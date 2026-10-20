<?php

namespace App\Domain\Tax\Jurisdictions\India;

use App\Domain\Tax\Contracts\TaxPresentation;
use App\Domain\Tax\Enums\TaxRegime;

/** SaaS.7 India GST: the rows a GST invoice adds (GSTINs, place of supply with its state code, supply type, SAC). */
final class IndiaGstPresentation implements TaxPresentation
{
    public function regime(): TaxRegime
    {
        return TaxRegime::InGst;
    }

    public function rows(array $taxSnapshot): array
    {
        $meta = $taxSnapshot['determination']['metadata'] ?? [];
        $supply = match ($taxSnapshot['determination']['outcome'] ?? null) {
            'intra_state' => 'Intra-state (CGST + SGST)',
            'intra_union_territory' => 'Intra-union territory (CGST + UTGST)',
            'inter_state' => 'Inter-state (IGST)',
            default => '—',
        };

        return array_values(array_filter([
            ['label' => 'Supplier GSTIN', 'value' => (string) ($meta['supplier_gstin'] ?? '—')],
            ['label' => 'Customer GSTIN', 'value' => (string) ($meta['customer_gstin'] ?? 'Unregistered')],
            ['label' => 'Place of supply', 'value' => isset($meta['place_of_supply_name']) ? "{$meta['place_of_supply_name']} ({$meta['place_of_supply_code']})" : '—'],
            ['label' => 'Supply type', 'value' => $supply],
            isset($taxSnapshot['classification']['sac']) ? ['label' => 'SAC', 'value' => (string) $taxSnapshot['classification']['sac']] : null,
        ]));
    }
}
