<?php

namespace App\Domain\Website\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Website\Enums\EnquiryStatus;
use App\Domain\Website\Models\WebsiteEnquiry;
use Illuminate\Support\Collection;

/**
 * "Talk to sales" submissions from the public website. Visitors are anonymous: the route is rate-limited, a hidden
 * field catches simple bots, and the address is stored only as a keyed hash. Operators work the list in
 * Platform → Public website → Enquiries.
 */
final class Enquiries
{
    public const TEAM_SIZES = ['1-50' => '1–50', '51-200' => '51–200', '201-500' => '201–500', '501-1000' => '501–1,000', '1000+' => 'More than 1,000'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data validated input */
    public function submit(array $data, ?string $ip, ?string $path): WebsiteEnquiry
    {
        return WebsiteEnquiry::query()->create([
            'name' => mb_substr(trim((string) $data['name']), 0, 120),
            'email' => mb_strtolower(trim((string) $data['email'])),
            'company' => filled($data['company'] ?? null) ? mb_substr(trim((string) $data['company']), 0, 160) : null,
            'phone' => filled($data['phone'] ?? null) ? mb_substr(trim((string) $data['phone']), 0, 40) : null,
            'phone_verified_at' => ($data['phone_verified'] ?? false) === true ? now() : null,
            'team_size' => array_key_exists((string) ($data['team_size'] ?? ''), self::TEAM_SIZES) ? (string) $data['team_size'] : null,
            'interest' => filled($data['interest'] ?? null) ? mb_substr(trim((string) $data['interest']), 0, 64) : null,
            'message' => filled($data['message'] ?? null) ? mb_substr(trim((string) $data['message']), 0, 2000) : null,
            'source_path' => $path !== null ? mb_substr($path, 0, 190) : null,
            'status' => EnquiryStatus::New,
            'ip_hash' => $ip !== null ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
        ]);
    }

    public function setStatus(WebsiteEnquiry $enquiry, EnquiryStatus $status, User $actor): void
    {
        $before = $enquiry->status;
        $enquiry->forceFill(['status' => $status, 'handled_by' => $actor->id, 'handled_at' => now()])->save();
        $this->audit->record(AuditAction::StatusChange, 'website', null, [], null, entityLabel: "Website enquiry #{$enquiry->id}", actor: $actor,
            metadata: ['enquiry_id' => $enquiry->id, 'from' => $before?->value, 'to' => $status->value], platform: true);
    }

    /** @return Collection<int, WebsiteEnquiry> */
    public function list(?EnquiryStatus $status = null, int $limit = 100): Collection
    {
        return WebsiteEnquiry::query()->with('handler')
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    public function newCount(): int
    {
        return WebsiteEnquiry::query()->where('status', EnquiryStatus::New->value)->count();
    }
}
