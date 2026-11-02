<?php

namespace App\Domain\ProductFeedback\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Experience\Services\ModuleCatalogue;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\PlatformOperator;
use App\Domain\ProductFeedback\Enums\ProductIdeaKind;
use App\Domain\ProductFeedback\Enums\ProductIdeaStatus;
use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Domain\ProductFeedback\Policies\ProductIdeaPolicy;
use App\Support\Storage\FileSafety;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne parity AC-3 (matrix 23 §23.9 #22): product ideas — an organisation tells Markedge what PeopleOS should do better.
 *
 * - submit(): someone in the organisation with product.feedback sends an idea (module and process, new feature or
 *   improvement, title, the challenge, how it is managed today, the ideal solution, people affected, priority, up to five
 *   supporting files checked by FileSafety and kept under tenants/{id}/product-ideas). Audited SUBMITTED on the
 *   organisation's chain. Platform operators do not send ideas: they answer them.
 * - changeStatus(): a Markedge operator (PlatformOperator, never a tenant user) sets the status and Markedge's response,
 *   inside the organisation's tenant: audited STATUS_CHANGE on the organisation's chain (with the field changes) and on the
 *   platform chain (reference, tenant, from → to, never the idea text); the person who sent the idea is told in the app and
 *   by e-mail (Notifier, event product.idea_status_changed).
 * - attachmentPath(): an operator opens a file the organisation attached (audited on both chains).
 *
 * Modules are PeopleOS's own module list: the navigation groups of ModuleCatalogue::SPACES, by space, plus "Other".
 */
final class ProductIdeas
{
    public const MAX_FILES = 5;

    /** Where the form's uploads wait until the idea is sent (documents disk); submit() moves them under the tenant. */
    public const UPLOAD_AREA = 'product-ideas/';

    public const STATUS_EVENT = 'product.idea_status_changed';

    public const OTHER_MODULE = 'Other';

    private const TEXT_MAX = 5000;

    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit, private readonly Notifier $notifier) {}

    /**
     * The modules an idea can be about, grouped by space in the navigation's order: [space label => [module => label]].
     *
     * @return array<string, array<string, string>>
     */
    public static function moduleOptions(): array
    {
        $grouped = [];
        foreach (ModuleCatalogue::SPACES as $group => $space) {
            $grouped[$space][$group] = self::moduleLabel($group);
        }
        $out = [];
        foreach (ModuleCatalogue::SPACE_ORDER as $space) {
            if (isset($grouped[$space])) {
                $out[$space === 'You' ? 'You (self-service)' : $space] = $grouped[$space];
            }
        }
        $out['Other'] = [self::OTHER_MODULE => self::moduleLabel(self::OTHER_MODULE)];

        return $out;
    }

    /** @return list<string> every module key */
    public static function modules(): array
    {
        return [...array_keys(ModuleCatalogue::SPACES), self::OTHER_MODULE];
    }

    public static function moduleLabel(?string $module): string
    {
        return match ($module) {
            null, '' => '—',
            'Me' => 'Me (my own pages)',
            self::OTHER_MODULE => 'Something else / not sure',
            default => $module,
        };
    }

    /**
     * The organisation's person sends an idea.
     *
     * @param  array<string, mixed>  $data  module, process, kind, title, challenge, current_workaround, ideal_solution,
     *                                      impact_people, priority, attachments (paths in UPLOAD_AREA), attachment_names
     */
    public function submit(User $actor, array $data): ProductIdea
    {
        $tenantId = $this->tenants->id();
        if ($actor->getAttribute('tenant_id') === null || $tenantId === null || (int) $actor->getAttribute('tenant_id') !== $tenantId) {
            throw new RuntimeException('Product ideas are sent by the organisation\'s own people.');
        }
        if (! $actor->hasPermission(ProductIdeaPolicy::PERMISSION)) {
            throw new RuntimeException('You may not send product ideas.');
        }

        $module = (string) ($data['module'] ?? '');
        if (! in_array($module, self::modules(), true)) {
            throw new RuntimeException('Choose the module the idea is about.');
        }
        $kind = ProductIdeaKind::tryFrom((string) ($data['kind'] ?? '')) ?? throw new RuntimeException('Say whether PeopleOS lacks the feature or should improve one.');
        $priority = filled($data['priority'] ?? null) ? (string) $data['priority'] : null;
        if ($priority !== null && ! array_key_exists($priority, ProductIdea::PRIORITIES)) {
            throw new RuntimeException('Choose how much the issue affects your work.');
        }
        $people = filled($data['impact_people'] ?? null) ? $data['impact_people'] : null;
        if ($people !== null && (! is_numeric($people) || (int) $people != $people || (int) $people < 0 || (int) $people > 10_000_000)) {
            throw new RuntimeException('People affected is a whole number.');
        }

        $values = [
            'module' => $module, 'kind' => $kind->value,
            'process' => $this->text($data['process'] ?? null, 'the process', 3, 150),
            'title' => $this->text($data['title'] ?? null, 'a short title', 5, 150),
            'challenge' => $this->text($data['challenge'] ?? null, 'the challenge you face', 10, self::TEXT_MAX),
            'current_workaround' => $this->text($data['current_workaround'] ?? null, 'how you manage it today', 0, self::TEXT_MAX),
            'ideal_solution' => $this->text($data['ideal_solution'] ?? null, 'the ideal solution', 10, self::TEXT_MAX),
            'impact_people' => $people === null ? null : (int) $people, 'priority' => $priority,
        ];

        $uploads = array_values(array_filter((array) ($data['attachments'] ?? []), 'is_string'));
        $files = $this->storeFiles($tenantId, $uploads, (array) ($data['attachment_names'] ?? []));

        try {
            return DB::transaction(function () use ($values, $files, $actor) {
                $idea = (new ProductIdea)->fill($values + [
                    'attachments' => $files === [] ? null : $files, 'status' => ProductIdeaStatus::Submitted->value,
                    'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'status_changed_at' => now(),
                ]);
                // Audited once below, with the reference (derived from the id, unique across organisations) and the actor; the
                // tenant hooks still run (the tenant is stamped and checked).
                $idea->withoutAuditing()->save();
                $idea->forceFill(['reference' => ProductIdea::referenceFor((int) $idea->getKey())])->save();
                $this->audit->record(AuditAction::Submitted, 'product_feedback', $idea, $idea->auditDiff([], $idea->getAttributes()), 'Product idea sent to Markedge', actor: $actor);

                return ProductIdea::query()->findOrFail($idea->getKey());
            });
        } catch (Throwable $e) {
            foreach ($files as $path) {
                $this->disk()->delete($path);
            }
            throw $e;
        }
    }

    /** A Markedge operator sets the idea's status and Markedge's response (shown to the organisation as written). */
    public function changeStatus(ProductIdea $idea, ?User $actor, ProductIdeaStatus|string $status, ?string $response): ProductIdea
    {
        $actor = PlatformOperator::assert($actor, 'answer product ideas');
        $status = $status instanceof ProductIdeaStatus ? $status : (ProductIdeaStatus::tryFrom($status) ?? throw new RuntimeException('Choose a status.'));
        if ($status === ProductIdeaStatus::Submitted) {
            throw new RuntimeException('Only the organisation submits an idea; move it to "Under review" instead.');
        }
        $response = filled($response) ? trim((string) $response) : null;
        if ($response !== null && Str::length($response) > self::TEXT_MAX) {
            throw new RuntimeException('The response is limited to 5,000 characters.');
        }
        if ($status === ProductIdeaStatus::Declined && $response === null) {
            throw new RuntimeException('Say why the idea is declined: the organisation reads the response.');
        }
        $tenant = Tenant::query()->find($idea->getAttribute('tenant_id')) ?? throw new RuntimeException('The organisation of this idea no longer exists.');

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($idea, $actor, $status, $response, $tenant) {
            $locked = ProductIdea::query()->whereKey($idea->getKey())->lockForUpdate()->first() ?? throw new RuntimeException('The idea was not found.');
            $from = $locked->status;
            $statusChanged = $from !== $status;
            $responseChanged = $response !== $locked->platform_response;
            if (! $statusChanged && ! $responseChanged) {
                throw new RuntimeException('Nothing changes: choose another status or change the response.');
            }

            $reason = 'Markedge: '.$from->getLabel().' → '.$status->getLabel();
            $before = $locked->getAttributes();
            $locked->withoutAuditing()->fill(['status' => $status->value, 'platform_response' => $response, 'status_changed_at' => $statusChanged ? now() : $locked->status_changed_at])->save();
            // The organisation's chain, with the field changes and the operator who made them.
            $this->audit->record(AuditAction::StatusChange, 'product_feedback', $locked, $locked->auditDiff($before, $locked->getAttributes()), $reason, actor: $actor);

            $reference = (string) $locked->reference;
            $this->audit->record(AuditAction::StatusChange, 'product_feedback', null, [], $reason, entityLabel: "Product idea {$reference} ({$tenant->name})", actor: $actor,
                metadata: ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug, 'reference' => $reference, 'from' => $from->value, 'to' => $status->value,
                    'response_changed' => $responseChanged], platform: true);

            $this->tell($locked, $statusChanged);

            return ProductIdea::query()->findOrFail($locked->getKey());
        }));
    }

    /**
     * A Markedge operator opens one of the idea's files: the disk and path to stream (audited on both chains).
     *
     * @return array{disk: string, path: string, name: string}
     */
    public function attachmentPath(ProductIdea $idea, ?User $actor, int $index): array
    {
        $actor = PlatformOperator::assert($actor, 'open the files of product ideas');
        $path = array_values((array) ($idea->attachments ?? []))[$index] ?? null;
        if (! is_string($path) || ! $this->disk()->exists($path)) {
            throw new RuntimeException('The file was not found.');
        }
        $tenant = Tenant::query()->find($idea->getAttribute('tenant_id')) ?? throw new RuntimeException('The organisation of this idea no longer exists.');
        $metadata = ['type' => 'product-idea', 'file' => basename($path), 'subject_tenant_id' => $tenant->getKey(), 'reference' => (string) $idea->reference];
        $this->tenants->runAs($tenant, fn () => $this->audit->record(AuditAction::AttachmentDownloaded, 'product_feedback', $idea, [], 'Opened by Markedge', actor: $actor, metadata: $metadata));
        $this->audit->record(AuditAction::AttachmentDownloaded, 'product_feedback', null, [], 'Product idea file', entityLabel: 'Product idea '.$idea->reference." ({$tenant->name})",
            actor: $actor, metadata: $metadata, platform: true);

        return ['disk' => self::diskName(), 'path' => $path, 'name' => basename($path)];
    }

    public static function diskName(): string
    {
        return (string) config('peopleos.documents.disk', 'local');
    }

    /** The person who sent the idea hears about a new status or response (in the app and by e-mail). */
    private function tell(ProductIdea $idea, bool $statusChanged): void
    {
        $submitter = $idea->submitter()->first();
        if ($submitter === null || $submitter->status === UserStatus::Suspended) {
            return;
        }
        $status = $idea->status->getLabel();
        $subject = $statusChanged ? "Product idea {$idea->reference}: {$status}" : "Product idea {$idea->reference}: Markedge replied";
        $body = "Markedge has updated your idea \"{$idea->title}\". Status: {$status}."
            .(filled($idea->platform_response) ? "\n\nMarkedge's response: ".$idea->platform_response : '')
            ."\n\nSee it under Product ideas.";
        rescue(fn () => $this->notifier->send([$submitter], ['in_app', 'email'], $subject, $body, self::STATUS_EVENT, $idea), report: true);
    }

    private function text(mixed $value, string $what, int $min, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            if ($min > 0) {
                throw new RuntimeException("Describe {$what}.");
            }

            return null;
        }
        if (Str::length($text) < $min) {
            throw new RuntimeException("Describe {$what} in a few more words.");
        }
        if (Str::length($text) > $max) {
            throw new RuntimeException('Keep '.$what.' to '.number_format($max).' characters.');
        }

        return $text;
    }

    /**
     * Checks every upload (in the upload area, on the document allowlist, within the size limit, not active content), then
     * moves each to tenants/{tenant}/product-ideas/{ulid}/{its name}.
     *
     * @param  list<string>  $uploads
     * @param  array<string, string>  $names  upload path => original name
     * @return list<string>
     */
    private function storeFiles(int $tenantId, array $uploads, array $names): array
    {
        if (count($uploads) > self::MAX_FILES) {
            throw new RuntimeException('Attach at most '.self::MAX_FILES.' files.');
        }
        $disk = $this->disk();
        $checked = [];
        foreach ($uploads as $upload) {
            $source = ltrim(str_replace(['\\', '..'], ['/', ''], $upload), '/');
            if (! str_starts_with($source, self::UPLOAD_AREA) || ! $disk->exists($source)) {
                throw new RuntimeException('An attached file was not found. Attach it again.');
            }
            $original = (string) ($names[$upload] ?? basename($source));
            $extension = FileSafety::assertAllowed($original, (int) $disk->size($source), FileSafety::sniff((string) $disk->get($source)));
            $checked[$source] = (Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file').'.'.$extension;
        }
        $files = [];
        foreach ($checked as $source => $name) {
            $path = "tenants/{$tenantId}/product-ideas/".Str::lower((string) Str::ulid()).'/'.$name;
            $disk->move($source, $path);
            $files[] = $path;
        }

        return $files;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }
}
