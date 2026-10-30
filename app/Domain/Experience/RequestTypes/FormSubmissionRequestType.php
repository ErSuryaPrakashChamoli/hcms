<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Forms\FormResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: configuration form submissions (Forms → a published form → "Fill form"). Only holders of form.submit
 * fill forms (on behalf of employees); their submissions are the ones they raised (submitted_by), with or without an
 * employee record of their own.
 */
final class FormSubmissionRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'form';
    }

    public function label(): string
    {
        return 'Form';
    }

    public function description(): string
    {
        return 'Fill one of your organisation’s published forms';
    }

    public function module(): string
    {
        return 'Forms';
    }

    public function icon(): string
    {
        return 'heroicon-o-queue-list';
    }

    public function keywords(): array
    {
        return ['form', 'custom form', 'studio', 'submission'];
    }

    public function permission(): string
    {
        return 'form.submit';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $user->can('form.submit') && FormResource::canAccess() && Form::query()->whereHas('published')->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return FormResource::getUrl('index');
    }

    public function model(): ?string
    {
        return FormSubmission::class;
    }

    protected function resource(): ?string
    {
        return FormResource::class;
    }

    protected function with(): array
    {
        return ['form'];
    }

    public function ownedByEmployee(): bool
    {
        return false;
    }

    public function ownRecords(User $user, ?Employee $employee): ?Builder
    {
        return FormSubmission::query()->with($this->with())->where('form_submissions.submitted_by', $user->getKey());
    }

    public function statusMap(): array
    {
        return [
            'submitted' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Submitted on', 'column' => 'created_at'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['review_note'];
    }

    public function summary(Model $record): array
    {
        /** @var FormSubmission $record */
        return [
            'title' => $this->join($record->form?->name ?? 'Form', 'submission #'.$record->getKey()),
            'detail' => $this->short($record->review_note),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['form.submitted'];
    }

    public function builtInApprovers(): string
    {
        return 'The form\'s reviewers';
    }

    public function approverPermissions(): array
    {
        return ['form.approve'];
    }
}
