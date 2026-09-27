<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conversation on a ticket; internal notes are hidden from the employee. */
#[Fillable(['tenant_id', 'ticket_id', 'author_id', 'body', 'is_internal', 'attachment_path', 'attachment_name'])]
class TicketComment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return 'Ticket #'.$this->ticket_id.' comment #'.$this->getKey().($this->attachment_name ? ' ('.$this->attachment_name.')' : '');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
