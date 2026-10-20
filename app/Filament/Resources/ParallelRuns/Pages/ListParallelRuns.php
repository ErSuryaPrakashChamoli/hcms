<?php

namespace App\Filament\Resources\ParallelRuns\Pages;

use App\Domain\Compliance\Services\ParallelPayroll;
use App\Domain\Payroll\Models\PayrollRun;
use App\Filament\Resources\ParallelRuns\ParallelRunResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\StatutoryReturnActions;
use App\Support\Storage\StagedUpload;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

class ListParallelRuns extends PeopleListRecords
{
    protected static string $resource = ParallelRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Import reference values')
                ->visible(fn () => StatutoryReturnActions::user()->hasPermission('compliance.parallel.manage'))
                ->schema([
                    Select::make('payroll_run_id')->label('Finalized payroll run')->required()
                        ->options(fn () => PayrollRun::query()->with(['period', 'company'])->whereIn('status', ['finalized', 'paid'])->orderByDesc('id')->get()->mapWithKeys(fn ($r) => [$r->id => $r->company?->name.' — '.$r->period?->label()])->all()),
                    TextInput::make('source')->label('Reference source (payroll system / portal)')->required()->maxLength(255),
                    FileUpload::make('file')->label('Reference CSV (level,employee_code,scope,component,amount)')->disk(StagedUpload::disk())->directory('parallel-runs/uploads')->required(),
                    Textarea::make('description')->rows(2),
                ])
                ->action(fn (array $data) => StatutoryReturnActions::run(function () use ($data) {
                    app(ParallelPayroll::class)->import(PayrollRun::query()->findOrFail($data['payroll_run_id']), StatutoryReturnActions::user(), (string) StagedUpload::storage()->get($data['file']), $data['source'], $data['description'] ?? null);
                    StagedUpload::storage()->delete($data['file']);
                }, 'Reference values imported')),
        ];
    }
}
