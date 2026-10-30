<?php

namespace App\Filament\Resources\AppraisalReviews\Pages;

use App\Filament\Resources\AppraisalReviews\AppraisalReviewResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PerformanceRegisters;

/** HROne parity GO-1: Performance review requests — saved views, presets, report and bulk "Remind reviewers" / "Reopen reviews". */
class ListAppraisalReviews extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AppraisalReviewResource::class;

    public static function registerKey(): string
    {
        return 'review_requests';
    }

    /** HROne's "Performance history" views: reviews still to do (per level) and done. */
    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Still to review', 'filters' => ['status' => ['values' => ['pending']]]],
            'self_pending' => ['label' => 'Self reviews to do', 'filters' => ['status' => ['values' => ['pending']], 'type' => ['values' => ['self']]]],
            'manager_pending' => ['label' => 'Manager reviews to do', 'filters' => ['status' => ['values' => ['pending']], 'type' => ['values' => ['manager']]]],
            'approver_pending' => ['label' => 'Approver reviews to do', 'filters' => ['status' => ['values' => ['pending']], 'type' => ['values' => ['approver']]]],
            'submitted' => ['label' => 'Submitted', 'filters' => ['status' => ['values' => ['submitted']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['appraisal_status'];
    }

    public static function registerBulkOperations(): array
    {
        return AppraisalReviewResource::bulkOperations();
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), PerformanceRegisters::actionsMenu()];
    }
}
