<?php

namespace App\Domain\Recognition\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Services\Recognitions;

/**
 * Data import DI-35 ("Badge allocation", HROne Import → Engage; matrix 27 §27.10 #10): badges HR allocates to employees on
 * behalf of the organisation. Each row goes through Recognitions::allocate (owner decision D-GO4 on its recommended
 * behaviour): recognition.manage, the badge allowed, the receiver's conditions and receiving limit, a reason when the badge
 * asks for one — and no giver quota, since the organisation, not a colleague, gives the badge. The receiver gets the
 * notice, certificate, reward points and wall post as for any badge. Rows only add recognitions (a badge is never
 * changed; HR withdraws it on the Recognition page).
 */
final class BadgeAwardImports extends RowImports
{
    public const TYPE = 'badge_awards';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'badge_code' => ['Badge code', true], 'message' => ['Reason', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Badge allocation upload';
    }

    protected function module(): string
    {
        return 'recognition';
    }

    protected function entityType(): string
    {
        return Recognition::class;
    }

    protected function aliases(): array
    {
        return ['badge' => 'badge_code', 'remarks' => 'message', 'citation' => 'message'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => User::query()->find($import->uploaded_by),
            'badges' => Badge::query()->get()->keyBy(fn (Badge $b) => mb_strtolower((string) $b->code))->all(), 'given' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        /** @var ?Badge $badge */
        $badge = $context['badges'][mb_strtolower($r['badge_code'] ?? '')] ?? null;
        if ($badge === null) {
            $errors[] = "Badge code '".($r['badge_code'] ?? '')."' does not exist (Engage → Badges).";
        }
        if ($errors !== [] || $employeeId === null || $badge === null || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $service = app(Recognitions::class);
        $employee = Employee::query()->with(['person', 'user'])->findOrFail($employeeId);
        if (($refusal = $service->refusalForAllocation($badge, $employee, $context['actor'], $r['message'] ?? null)) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        if ($badge->receive_limit > 0) {
            $key = "{$employeeId}|{$badge->id}";
            $context['given'][$key] ??= $service->receivedInPeriod($badge, $employee);
            if ($context['given'][$key] >= $badge->receive_limit) {
                return ['action' => 'error', 'errors' => [$employee->person?->display_name.' would receive the badge '.$badge->name.' more than '.$badge->receive_limit.' time(s) in its period (counting the rows above).'], 'employee_id' => $employeeId];
            }
            $context['given'][$key]++;
        }

        return ['action' => 'create', 'match' => ['badge' => $badge->name], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $badge = Badge::query()->whereRaw('lower(code) = ?', [mb_strtolower($r['badge_code'])])->firstOrFail();
        $recognition = app(Recognitions::class)->allocate($badge, Employee::query()->findOrFail($row->employee_id), $user, $r['message'] ?? null);

        return 'Badge '.$badge->name.' allocated ('.$recognition->reference().')';
    }

    public function format(): array
    {
        $codes = Badge::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['code', 'name']);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The receiver's code.", 'EMP0001'],
                ['Badge code', 'Yes', 'The badge (Engage → Badges); it must be switched on.', (string) ($codes->first()?->code ?? 'STAR')],
                ['Reason', 'No', 'Why the employee receives the badge (needed when the badge asks for a reason); shown to the receiver and on the wall for public badges.', 'Closed the year-end audit early'],
            ],
            'notes' => [
                'Badges are allocated by HR on behalf of the organisation: they do not use your own monthly badge quota, and the badges\' giver rules do not apply.',
                'Each badge\'s receiving limit (e.g. once a month) still applies, counting the rows above in the same file.',
                'The receiver is notified and gets the badge\'s certificate and reward points, as with a badge given on the Recognition page. Badges that need an attachment are given there.',
                'A badge is never changed by an upload; HR can withdraw one on the Recognition page.',
            ],
            'codes' => $codes->mapWithKeys(fn (Badge $b) => [$b->code => $b->name])->all(),
            'codes_title' => 'Badge codes',
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all() ?: ['EMP0001'];
        // Badges anyone may receive (no receiver conditions) and that need no attachment.
        $badges = Badge::query()->where('status', 'active')->where('attachment_required', false)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Badge $b) => (array) $b->receivers === [])->take(2)->pluck('code')->values()->all() ?: ['STAR'];

        return [
            [$codes[0], $badges[0], 'Closed the year-end audit early'],
            [$codes[1] ?? $codes[0], $badges[1] ?? $badges[0], 'Led the office move without a day lost'],
        ];
    }
}
