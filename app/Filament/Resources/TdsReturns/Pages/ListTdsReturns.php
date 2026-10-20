<?php

namespace App\Filament\Resources\TdsReturns\Pages;

use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\Tds\TdsQuarterlyReturns;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\Resources\TdsReturns\TdsReturnResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\StatutoryReturnActions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class ListTdsReturns extends PeopleListRecords
{
    protected static string $resource = TdsReturnResource::class;

    protected function getHeaderActions(): array
    {
        $years = FinancialYear::make();
        $options = collect([now()->subYear(), now()])->mapWithKeys(fn ($d) => [$years->label($d) => $years->label($d)])->all();

        return [
            Action::make('generate')->label('Generate Form 138 statement')
                ->visible(fn () => StatutoryReturnActions::user()->hasPermission('compliance.returns.generate'))
                ->schema([
                    Select::make('legal_entity_id')->label('Legal entity (deductor)')->required()->options(fn () => LegalEntity::query()->orderBy('legal_name')->pluck('legal_name', 'id')->all()),
                    Select::make('financial_year')->required()->options($options)->default($years->label(now())),
                    Select::make('quarter')->required()->options([1 => 'Q1 (Apr–Jun)', 2 => 'Q2 (Jul–Sep)', 3 => 'Q3 (Oct–Dec)', 4 => 'Q4 (Jan–Mar)']),
                    Repeater::make('challans')->label('Tax deposited (challans / BIN)')->columns(4)->schema([
                        TextInput::make('bsr_code')->required()->maxLength(16),
                        DatePicker::make('deposit_date')->required(),
                        TextInput::make('challan_serial')->required()->maxLength(16),
                        TextInput::make('amount')->numeric()->required(),
                    ])->defaultItems(0),
                    Textarea::make('reason')->rows(2),
                ])
                ->action(fn (array $data) => StatutoryReturnActions::run(fn () => app(TdsQuarterlyReturns::class)->generate(
                    LegalEntity::query()->findOrFail($data['legal_entity_id']), $data['financial_year'], (int) $data['quarter'], StatutoryReturnActions::user(), array_values($data['challans'] ?? []), $data['reason'] ?? null,
                ), 'Statement generated from the annual ledger')),
        ];
    }
}
