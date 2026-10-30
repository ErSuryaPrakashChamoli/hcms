<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\People\Support\ProfileEditSections;
use App\Filament\Pages\MyProfile;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * HROne parity MS-3 (matrix 22 §22.2.12 #5): self-service profile changes as a request type of their own (they were
 * service requests listed under "Helpdesk ticket"; those keep listing there). The form is My profile, where each section
 * the organisation lets the employee change has its "Edit"; it is offered only while at least one section is editable
 * for the person and the employee request lock (kind profile_change) does not hold it.
 */
final class ProfileChangeRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'profile_change';
    }

    public function label(): string
    {
        return 'Profile change';
    }

    public function description(): string
    {
        return 'Update your contact details, family, education, skills or bank account';
    }

    public function module(): string
    {
        return 'Profile';
    }

    public function icon(): string
    {
        return 'heroicon-o-user-circle';
    }

    public function keywords(): array
    {
        return ['profile', 'address', 'phone', 'email', 'bank', 'account', 'family', 'education', 'skill', 'pan', 'change', 'update', 'details'];
    }

    public function permission(): string
    {
        return 'employee.self';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        if (! $this->signedIn($user) || $employee === null || ! MyProfile::canAccess()) {
            return false;
        }
        $changes = app(ProfileChanges::class);

        return $changes->editableSections($user, $employee) !== [] && $changes->blocked($user, $employee) === null;
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        try {
            return MyProfile::getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    public function model(): ?string
    {
        return ProfileChangeRequest::class;
    }

    public function historyVisibleTo(User $user): bool
    {
        return $this->signedIn($user) && MyProfile::canAccess();
    }

    public function recordUrl(User $user, Model $record): ?string
    {
        if (! $this->signedIn($user) || ! MyProfile::canAccess()) {
            return null;
        }
        try {
            return MyProfile::getUrl(['section' => 'history']);
        } catch (Throwable) {
            return null;
        }
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending, 'approved' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected, 'withdrawn' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'decided' => ['label' => 'Decided on', 'column' => 'decided_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['section'];
    }

    public function summary(Model $record): array
    {
        /** @var ProfileChangeRequest $record */
        return ['title' => $this->join('Profile change', ProfileEditSections::label((string) $record->section), $record->reference()), 'detail' => $this->short($record->comment)];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['profile.change_requested'];
    }

    public function builtInApprovers(): string
    {
        return 'HR (people who maintain employee records) in their Approval Center, when the section needs approval; otherwise the change applies at once';
    }

    public function approverPermissions(): array
    {
        return ['employee.update', 'employee.sensitive.update'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'profile_change';
    }

    public function knowledgeCategory(): ?string
    {
        return 'hr_policy';
    }
}
