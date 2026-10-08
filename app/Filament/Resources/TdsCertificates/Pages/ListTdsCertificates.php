<?php

namespace App\Filament\Resources\TdsCertificates\Pages;

use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\Tds\TdsCertificates;
use App\Domain\Compliance\Services\Tds\TdsLedgers;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\Resources\TdsCertificates\TdsCertificateResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\StatutoryReturnActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;

class ListTdsCertificates extends PeopleListRecords
{
    protected static string $resource = TdsCertificateResource::class;

    protected function getHeaderActions(): array
    {
        $years = FinancialYear::make();
        $schema = fn () => [
            Select::make('legal_entity_id')->label('Legal entity')->required()->options(fn () => LegalEntity::query()->orderBy('legal_name')->pluck('legal_name', 'id')->all()),
            Select::make('financial_year')->required()->options(collect([now()->subYear(), now()])->mapWithKeys(fn ($d) => [$years->label($d) => $years->label($d)])->all()),
        ];
        $visible = fn () => StatutoryReturnActions::user()->hasPermission('compliance.tds.manage');

        return [
            Action::make('verifyLedger')->label('Verify annual ledger')->requiresConfirmation()->visible($visible)->schema($schema())
                ->modalDescription('Checks that all four Form No. 138 statements are acknowledged and match the ledger.')
                ->action(fn (array $data) => StatutoryReturnActions::run(fn () => app(TdsLedgers::class)->verify(LegalEntity::query()->findOrFail($data['legal_entity_id']), $data['financial_year'], StatutoryReturnActions::user()), 'Annual ledger verified')),
            Action::make('generateCertificates')->label('Prepare Form 130')->visible($visible)->schema($schema())
                ->action(fn (array $data) => StatutoryReturnActions::run(fn () => app(TdsCertificates::class)->generate(LegalEntity::query()->findOrFail($data['legal_entity_id']), $data['financial_year'], StatutoryReturnActions::user()), 'Certificates prepared from the verified ledger')),
        ];
    }
}
