<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: Attendance correction → Attendance regularisation (Regularisations::request). It runs at
 * submission. Review stays in Attendance (reviewer recorded, never one's own). The case tracks it.
 */
final class RegularisationAction extends BaseDomainAction
{
    protected array $sources = ['web', 'hr'];

    public function __construct(private readonly Regularisations $regularisations) {}

    public function label(): string
    {
        return 'Attendance correction (Attendance regularisation)';
    }

    public function timing(): string
    {
        return 'on_submit';
    }

    public function fields(?Employee $employee): array
    {
        return [
            'date' => ['label' => 'Date', 'type' => 'date', 'required' => true],
            'type' => ['label' => 'Correction', 'type' => 'dropdown', 'required' => true, 'options' => config('peopleos.attendance.regularisation_types', [])],
            'in_time' => ['label' => 'Actual in time (HH:MM)', 'type' => 'text'],
            'out_time' => ['label' => 'Actual out time (HH:MM)', 'type' => 'text'],
            'correction_reason' => ['label' => 'Reason', 'type' => 'textarea', 'required' => true],
        ];
    }

    public function authorizeRequest(User $requester, Employee $employee, string $source): void
    {
        parent::authorizeRequest($requester, $employee, $source);
        if ($source === 'web' && ! $requester->hasPermission('attendance.regularise')) {
            throw new ServiceDeskRuleViolation('This needs attendance.regularise.');
        }
    }

    public function executorPermission(): ?string
    {
        return null;
    }

    public function validate(Employee $employee, array $data): array
    {
        return $this->check($data, [
            'date' => ['required', 'date', 'before_or_equal:today'], 'type' => ['required', 'in:'.implode(',', array_keys(config('peopleos.attendance.regularisation_types', [])))],
            'in_time' => ['nullable', 'date_format:H:i'], 'out_time' => ['nullable', 'date_format:H:i'], 'correction_reason' => ['required', 'string', 'max:1000'],
        ]);
    }

    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model
    {
        $c = $this->validate($employee, $data);
        $in = filled($c['in_time'] ?? null) ? $c['date'].' '.$c['in_time'] : null;
        $out = filled($c['out_time'] ?? null) ? $c['date'].' '.$c['out_time'] : null;

        return $this->regularisations->request($employee, $c['date'], $c['type'], $c['correction_reason'], $in, $out, $actor);
    }
}
