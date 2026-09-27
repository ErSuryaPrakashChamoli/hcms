<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Services\Onboarding;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use RuntimeException;

final class OnboardingTaskActions
{
    /** @return array<int, Action> */
    public static function forTable(): array
    {
        $can = fn (OnboardingTask $record) => $record->status === 'pending' && auth()->user()->can('act', $record);

        return [
            Action::make('complete')->label('Done')->icon('heroicon-m-check')->color('success')
                ->visible($can)
                ->schema(fn (OnboardingTask $record) => array_filter([
                    $record->type === 'document' ? Select::make('document_id')->label('Attach uploaded document')
                        ->options(fn () => $record->plan->employee->documents()->when($record->document_type_id, fn ($q) => $q->where('document_type_id', $record->document_type_id))->pluck('title', 'id')->all())
                        ->required()->helperText('Upload it on the Documents tab first.') : null,
                    Textarea::make('note')->maxLength(255),
                ]))
                ->action(function (OnboardingTask $record, array $data) {
                    try {
                        $evidence = isset($data['document_id']) ? EmployeeDocument::query()->find($data['document_id']) : null;
                        app(Onboarding::class)->complete($record, $data['note'] ?? null, $evidence);
                        Notification::make()->success()->title('Task completed')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                    }
                }),
            Action::make('skip')->label('Skip')->icon('heroicon-m-forward')->color('gray')
                ->visible(fn (OnboardingTask $record) => $record->status === 'pending' && auth()->user()->can('onboarding.manage'))
                ->schema([Textarea::make('note')->required()->maxLength(255)])
                ->action(function (OnboardingTask $record, array $data) {
                    app(Onboarding::class)->skip($record, $data['note']);
                    Notification::make()->success()->title('Task skipped')->send();
                }),
        ];
    }
}
