<?php

namespace App\Domain\Attendance\Notifications;

use App\Domain\Attendance\Models\AttendanceDevice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** HROne setup parity (TO-8): a biometric device or sync server has sent nothing for longer than its alert window. */
class DeviceSilent extends Notification
{
    public function __construct(private readonly AttendanceDevice $device, private readonly int $hours) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $last = $this->device->last_seen_at?->format('d M Y H:i') ?? 'never';

        return (new MailMessage)
            ->subject("Attendance device {$this->device->name} has stopped sending punches")
            ->line("{$this->device->name} ({$this->device->code}) has sent no punches for more than {$this->hours} hour(s). Last seen: {$last}.")
            ->line('Check the device or the sync agent on the server, then the missing days can be processed again.');
    }
}
