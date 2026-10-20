<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Communication\Services\CommunicationDelivery;
use App\Domain\Communication\Services\CommunicationPreferences;
use App\Domain\Communication\Services\CommunicationProcessor;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Notifications\Channels\Channel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Department;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->comms = app(Communications::class);
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->ops = Department::factory()->create(['name' => 'Ops']);
    $this->salesStaff = engagementTeam(3, $this->sales);
    $this->opsStaff = engagementTeam(2, $this->ops);
    $this->approved = function (array $data, ?string $key = null) {
        $draft = $this->comms->create($data + ['body' => 'Body text'], $this->actors['preparer'], $key);

        return $this->comms->approve($this->comms->submit($draft, $this->actors['preparer']), null, $this->actors['approver']);
    };
});

it('runs draft → review → approved → published with separation of duties, frozen content and an idempotent create', function () {
    $draft = $this->comms->create(['title' => 'Benefits window', 'body' => 'Enrol by Friday', 'type' => 'announcement'], $this->actors['preparer'], 'key-1');
    expect($this->comms->create(['title' => 'Other', 'body' => 'x'], $this->actors['preparer'], 'key-1')->id)->toBe($draft->id);
    $submitted = $this->comms->submit($draft, $this->actors['preparer']);
    expect(fn () => $submitted->update(['body' => 'changed']))->toThrow(RuntimeException::class)
        ->and(fn () => $this->comms->approve($submitted, null, $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);
    $both = tenantUser($this->tenant, ['communication.manage', 'communication.approve']);
    $mine = $this->comms->submit($this->comms->create(['title' => 'Mine', 'body' => 'x'], $both), $both);
    expect(fn () => $this->comms->approve($mine, null, $both))->toThrow(EngagementRuleViolation::class, 'prepared it cannot approve');

    $published = $this->comms->publish($this->comms->approve($submitted, 'ok', $this->actors['approver']), $this->actors['preparer']);
    expect($published->status)->toBe('published')->and($published->recipients_count)->toBe(5)->and($published->operation_id)->not->toBeNull()
        ->and(AuditEvent::query()->where('entity_id', (string) $published->id)->pluck('action')->map->value->all())->toContain('ANNOUNCEMENT_CREATED', 'ANNOUNCEMENT_APPROVED', 'ANNOUNCEMENT_PUBLISHED', 'AUDIENCE_USED')
        ->and(AuditEvent::query()->where('operation_id', $published->operation_id)->where('action', 'BULK_OPERATION')->exists())->toBeTrue();
    // Publishing again changes nothing.
    $this->comms->publish($published->refresh(), $this->actors['preparer']);
    expect(CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(5);
});

it('targets a structured audience in SQL and refuses criteria outside the preparer scope', function () {
    $a = $this->comms->publish(($this->approved)(['title' => 'Sales kick-off', 'audience_criteria' => ['department_ids' => [$this->sales->id]]]), $this->actors['preparer']);
    expect(CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $a->id)->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect($this->salesStaff)->pluck('id')->sort()->values()->all())
        ->and($this->comms->feedFor($this->opsStaff[0])->pluck('id')->all())->toBe([]);

    $scoped = tenantUser($this->tenant, ['communication.manage']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => $this->ops->id]);
    app(AccessScopes::class)->forget();
    $draft = $this->comms->create(['title' => 'Sneaky', 'body' => 'x', 'audience_criteria' => ['department_ids' => [$this->sales->id]]], $scoped);
    expect(fn () => $this->comms->submit($draft, $scoped))->toThrow(EngagementRuleViolation::class, 'outside your scope');
    // Empty criteria = everyone employed inside the preparer's scope — never the whole tenant.
    $ok = $this->comms->submit($this->comms->create(['title' => 'Ops only', 'body' => 'x'], $scoped), $scoped);
    $ok = $this->comms->publish($this->comms->approve($ok, null, $this->actors['approver']), $this->actors['approver']);
    expect($ok->recipients_count)->toBe(2);
});

it('delivers through the Notifier with preferences for optional types, never for mandatory ones, without the body', function () {
    Mail::fake();
    $optOut = $this->salesStaff[0];
    app(CommunicationPreferences::class)->set($optOut, 'newsletter', false, false, $optOut->user);
    $newsletter = $this->comms->publish(($this->approved)(['title' => 'October newsletter', 'type' => 'newsletter', 'body' => 'Secret body text']), $this->actors['preparer']);
    $policy = $this->comms->publish(($this->approved)(['title' => 'New travel policy', 'type' => 'policy', 'body' => 'Secret body text']), $this->actors['preparer']);

    $statuses = fn ($a) => CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $a->id)->pluck('status', 'employee_id');
    expect($statuses($newsletter)[$optOut->id])->toBe('skipped')
        ->and(CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $newsletter->id)->where('employee_id', $optOut->id)->value('skipped_reason'))->toBe('preference')
        ->and($statuses($policy)[$optOut->id])->toBe('sent')
        ->and($statuses($policy)->unique()->values()->all())->toBe(['sent']);
    expect(NotificationDelivery::query()->where('source_id', $newsletter->id)->pluck('body')->implode(' '))->not->toContain('Secret body text')
        ->and($this->comms->stats($newsletter))->toMatchArray(['audience' => 5, 'sent' => 4, 'skipped' => 1, 'failed' => 0]);
    // A mandatory type cannot be switched off.
    expect(fn () => app(CommunicationPreferences::class)->set($optOut, 'policy', false, false, $optOut->user))->toThrow(EngagementRuleViolation::class, 'mandatory')
        ->and(fn () => app(CommunicationPreferences::class)->set($optOut, 'newsletter', true, true, $this->actors['preparer']))->toThrow(EngagementRuleViolation::class, 'own')
        ->and(AuditEvent::query()->where('action', 'COMMUNICATION_PREFERENCE_CHANGED')->count())->toBe(1);
});

it('records a failed delivery as failed, retries it a bounded number of times, and never claims delivery', function () {
    config(['peopleos.notifications.channels.email.driver' => FailingTestChannel::class, 'peopleos.notifications.channels.in_app.driver' => FailingTestChannel::class]);
    $a = $this->comms->publish(($this->approved)(['title' => 'Will fail']), $this->actors['preparer']);
    $rows = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $a->id)->get();
    expect($rows->pluck('status')->unique()->all())->toBe(['failed'])->and($rows->pluck('attempts')->unique()->all())->toBe([1])
        ->and($rows->first()->error)->toContain('provider down')
        ->and(collect(config('peopleos.communication.recipient_statuses'))->keys()->all())->not->toContain('delivered');
    app(CommunicationDelivery::class)->deliver($a->refresh());
    app(CommunicationDelivery::class)->deliver($a->refresh());
    app(CommunicationDelivery::class)->deliver($a->refresh());
    expect(CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $a->id)->pluck('attempts')->unique()->all())->toBe([3])
        ->and(app(CommunicationDelivery::class)->pending($a))->toBe(0);
});

it('publishes scheduled announcements on their date through the processor, supersedes old versions and cancels with a reason', function () {
    $later = $this->comms->publish(($this->approved)(['title' => 'Holiday list', 'publish_at' => '2026-10-07 08:00:00']), $this->actors['preparer']);
    expect($later->status)->toBe('scheduled')->and(app(CommunicationProcessor::class)->run()['released'])->toBe(0);
    $this->travelTo('2026-10-07 09:00:00');
    expect(app(CommunicationProcessor::class)->run()['released'])->toBe(1)->and($later->refresh()->status)->toBe('published')
        ->and(app(CommunicationProcessor::class)->run()['released'])->toBe(0);

    $v2 = $this->comms->newVersion($later->refresh(), $this->actors['preparer']);
    expect($v2->version)->toBe(2)->and($v2->supersedes_id)->toBe($later->id)->and($v2->status)->toBe('draft');
    $this->comms->update($v2, ['title' => 'Holiday list (corrected)', 'body' => 'Fixed date'], $this->actors['preparer']);
    $this->comms->publish($this->comms->approve($this->comms->submit($v2->refresh(), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    expect($later->refresh()->status)->toBe('archived')->and($v2->refresh()->status)->toBe('published')
        ->and($this->comms->feedFor($this->salesStaff[0])->pluck('title')->all())->toBe(['Holiday list (corrected)']);

    $doomed = ($this->approved)(['title' => 'Wrong one']);
    expect(fn () => $this->comms->cancel($doomed, '', $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);
    expect($this->comms->cancel($doomed, 'Duplicate', $this->actors['preparer'])->status)->toBe('cancelled');
});

it('keeps attachments private: signed link, audience check, fingerprint, audited download', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $draft = $this->comms->create(['title' => 'Forms', 'body' => 'See attached'], $this->actors['preparer']);
    $this->comms->attach($draft, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'), null, $this->actors['preparer']);
    expect(fn () => $this->comms->attach($draft, UploadedFile::fake()->create('evil.exe', 20), null, $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);
    $a = $this->comms->publish($this->comms->approve($this->comms->submit($draft->refresh(), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    expect($a->attachment_sha256)->toHaveLength(64)->and(str_starts_with($a->attachment_path, "tenants/{$this->tenant->id}/communication/"))->toBeTrue();

    $url = $this->comms->attachmentUrl($a);
    $this->actingAs($this->salesStaff[0]->user)->get($url)->assertOk();
    expect(AuditEvent::query()->where('action', 'ATTACHMENT_DOWNLOADED')->where('module', 'communication')->count())->toBe(1);
    $outsider = tenantUser($this->tenant, ['communication.view']);
    $this->actingAs($outsider)->get($url)->assertForbidden();
    $this->actingAs($this->salesStaff[0]->user)->get(route('announcements.attachment', ['announcement' => $a->id]))->assertForbidden();
});

final class FailingTestChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        throw new RuntimeException('provider down');
    }
}
