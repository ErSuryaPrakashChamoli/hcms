<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeMessages;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** HROne parity WO-2 (§23.2.2 #18 "Send email" to one or more employees): one e-mail per employee through EmployeeMessages. */
final class SendEmailOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.send_email';
    }

    public function label(): string
    {
        return 'Send e-mail';
    }

    public function description(): string
    {
        return 'Sends the same message to each employee (work e-mail by default). Employees with no address on file are refused. The message is not kept in the audit trail.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'employee.message';
    }

    public function icon(): string
    {
        return 'heroicon-m-envelope';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return [
            TextInput::make('subject')->required()->maxLength(200),
            Textarea::make('body')->label('Message')->required()->rows(6)->maxLength(10000),
            CheckboxList::make('to')->label('Send to')->options(fn () => auth()->user()?->hasPermission('employee.sensitive.view') ? EmployeeMessages::RECIPIENTS : ['work' => EmployeeMessages::RECIPIENTS['work']])
                ->default(['work'])->required(),
        ];
    }

    public function prepare(array $data, User $actor): array
    {
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($subject === '' || $body === '') {
            throw new RuntimeException('Write a subject and a message.');
        }
        $to = array_values(array_intersect(array_keys(EmployeeMessages::RECIPIENTS), (array) ($data['to'] ?? ['work']))) ?: ['work'];
        if (in_array('personal', $to, true) && ! $actor->hasPermission('employee.sensitive.view')) {
            throw new RuntimeException('Writing to personal e-mails needs access to sensitive personal data.');
        }

        return ['subject' => mb_substr($subject, 0, 200), 'body' => mb_substr($body, 0, 10000), 'to' => $to];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $sent = app(EmployeeMessages::class)->email($record, $actor, $input['subject'], $input['body'], $input['to']);

        return $sent === 1 ? 'E-mail queued' : "{$sent} e-mails queued";
    }
}
