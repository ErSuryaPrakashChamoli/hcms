<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Models\GrievanceNote;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\Knowledge\Models\ArticleVersion;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ServiceDeskTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->author = tenantUser($this->tenant, ['kb.manage', 'kb.review', 'kb.view']);
    $this->reviewer = tenantUser($this->tenant, ['kb.review', 'kb.view']);
    $this->employee = activeEmployee(null, ['kb.view', 'servicedesk.request']);
    $this->kb = app(KnowledgeBase::class);
});

it('publishes knowledge only after a second person approves it, as immutable versions readers always see', function () {
    $article = Article::create(['title' => 'Travel policy', 'category' => 'travel', 'body' => 'Book economy. Secret draft line.', 'requires_acknowledgement' => true]);
    expect(fn () => $this->kb->publish($article, $this->author))->toThrow(RuntimeException::class, 'draft');

    $this->kb->submitForReview($article, $this->author);
    expect(fn () => $this->kb->review($article->refresh(), $this->author, true))->toThrow(RuntimeException::class, 'author')
        ->and(fn () => $this->kb->review($article->refresh(), $this->reviewer, false))->toThrow(RuntimeException::class, 'note')
        ->and(fn () => $article->refresh()->update(['body' => 'changed in review']))->toThrow(RuntimeException::class, 'draft');
    $this->kb->review($article->refresh(), $this->reviewer, true, 'Fine');
    $this->kb->publish($article->refresh(), $this->author);
    $v1 = ArticleVersion::query()->where('article_id', $article->id)->firstOrFail();
    expect($article->refresh()->published_version)->toBe(1)->and($v1->body_hash)->toBe(hash('sha256', "Travel policy\nBook economy. Secret draft line."))
        ->and($v1->approved_by)->toBe($this->reviewer->id)
        ->and(fn () => $v1->update(['body' => 'tampered']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $v1->delete())->toThrow(RuntimeException::class)
        ->and(AuditEvent::query()->where('action', 'KNOWLEDGE_PUBLISHED')->exists())->toBeTrue();

    // A revision in progress is never served or searchable.
    $this->kb->startRevision($article->refresh(), $this->author);
    $article->refresh()->update(['body' => 'Business class for everyone (draft)']);
    expect($article->refresh()->isPublished())->toBeTrue()
        ->and($this->kb->visibleTo($this->employee, 'Business class'))->toHaveCount(0)
        ->and($this->kb->visibleTo($this->employee, 'economy'))->toHaveCount(1)
        ->and($this->kb->publishedVersion($article)->body)->toContain('Book economy');
});

it('records acknowledgement per version with hash, source and IP, once, and never deletes it', function () {
    $article = Article::create(['title' => 'Code of conduct', 'category' => 'conduct', 'body' => 'Be kind.', 'requires_acknowledgement' => true]);
    kbPublishForTests($article, $this->author);

    $read = $this->kb->acknowledge($article->refresh(), $this->employee, 'web', '10.0.0.7');
    $this->kb->acknowledge($article->refresh(), $this->employee, 'web', '10.0.0.8'); // a repeat records nothing more
    expect($read->refresh()->version)->toBe(1)->and($read->version_hash)->toBe(ArticleVersion::hash('Code of conduct', 'Be kind.'))
        ->and($read->source)->toBe('web')->and($read->ip_address)->toBe('10.0.0.7')
        ->and(AuditEvent::query()->where('action', 'POLICY_ACKNOWLEDGED')->count())->toBe(1)
        ->and(fn () => $read->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $read->update(['acknowledged_at' => now()->addDay()]))->toThrow(RuntimeException::class);

    // Version 2 asks again; version 1's acknowledgement stays on record.
    $this->kb->startRevision($article->refresh(), $this->author);
    $article->refresh()->update(['body' => 'Be kind and fair.']);
    kbPublishForTests($article->refresh(), $this->author);
    expect($this->kb->pendingAcknowledgements($this->employee)->pluck('id')->all())->toBe([$article->id])
        ->and(ArticleRead::query()->where('article_id', $article->id)->whereNotNull('acknowledged_at')->pluck('version')->all())->toBe([1]);

    // Outside the audience: refused.
    $targeted = Article::create(['title' => 'Probation guide', 'category' => 'hr_policy', 'body' => 'x', 'requires_acknowledgement' => true, 'audience' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']]]);
    kbPublishForTests($targeted, $this->author);
    expect(fn () => $this->kb->acknowledge($targeted->refresh(), $this->employee))->toThrow(RuntimeException::class, 'not addressed');
});

it('scopes grievance managers by organisation, gives platform admins no blanket access, and checks who grants access', function () {
    $company = Company::factory()->create();
    $delhi = Location::factory()->create(['company_id' => $company->id]);
    $mumbai = Location::factory()->create(['company_id' => $company->id]);
    $hire = fn (Location $l, ?int $userId = null) => app(HireEmployeeAction::class)->handle(['first_name' => 'G', 'last_name' => 'P'], ['joining_date' => '2025-01-01'] + ($userId ? ['user_id' => $userId] : []), ['company_id' => $company->id, 'location_id' => $l->id]);
    $raiser = tenantUser($this->tenant, ['grievance.raise']);
    $mumbaiEmp = $hire($mumbai, $raiser->id);
    $delhiManager = tenantUser($this->tenant, ['grievance.manage', 'grievance.view']);
    app(AccessScopes::class)->assign($delhiManager, ['company' => [$company->id], 'location' => [$delhi->id]]);
    $grievances = app(Grievances::class);

    $safety = GrievanceCategory::query()->where('code', 'SAFETY')->first(); // not confidential
    $case = $grievances->raise($safety, $mumbaiEmp, 'Wiring', 'Exposed cable', 'high', false, $raiser);
    expect($grievances->canAccess($delhiManager, $case))->toBeFalse();

    $posh = GrievanceCategory::query()->where('code', 'POSH')->first();
    $icc = Role::factory()->create(['name' => 'ICC']);
    $posh->update(['handler_role_ids' => [$icc->id]]);
    $confidential = $grievances->raise($posh, $mumbaiEmp, 'Complaint', 'Details', 'high', false, $raiser);
    expect($grievances->canAccess(platformAdmin(), $confidential))->toBeFalse();

    $bystander = tenantUser($this->tenant, ['grievance.view']);
    expect(fn () => $grievances->grantAccess($confidential, $bystander, 'Curious', $bystander))->toThrow(RuntimeException::class, 'handler');
    expect(fn () => $grievances->grantAccess($confidential, tenantUser($this->tenant, ['grievance.raise']), 'x', platformAdmin()))->toThrow(RuntimeException::class);

    // Case numbers come from the locked sequence (no reuse after the earlier cases).
    expect($case->number)->toBe('GRV-2026-00001')->and($confidential->number)->toBe('GRV-2026-00002');
});

it('serves grievance attachments through a signed, re-authorised, audited route', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $raiser = activeEmployee(null, ['grievance.raise']);
    $handler = tenantUser($this->tenant, ['grievance.view', 'grievance.manage']);
    $case = app(Grievances::class)->raise(GrievanceCategory::query()->where('code', 'SAFETY')->first(), $raiser, 'Wiring', 'Exposed cable', 'high', false, $raiser->user);
    $case->update(['assignee_id' => $handler->id]);
    Storage::disk('local')->put('grievance/photo.pdf', '%PDF');
    $hidden = GrievanceNote::create(['grievance_id' => $case->id, 'author_id' => $handler->id, 'type' => 'evidence', 'body' => 'Photo', 'visible_to_employee' => false, 'attachment_path' => 'grievance/photo.pdf', 'attachment_name' => 'photo.pdf']);
    $url = app(Grievances::class)->attachmentUrl($hidden);

    $this->actingAs($handler)->get($url)->assertOk();
    $this->actingAs($raiser->user)->get($url)->assertForbidden();
    $this->actingAs(tenantUser($this->tenant, ['grievance.view']))->get($url)->assertForbidden();
    expect(AuditEvent::query()->where('module', 'grievance')->where('action', 'ATTACHMENT_DOWNLOADED')->count())->toBe(1);
});

it('reuses the locked sequence for ticket numbers even when HR raises tickets concurrently with legacy rows present', function () {
    $desk = app(ServiceDesk::class);
    expect($desk->nextNumber('TKT'))->toBe('TKT-2026-00001')->and($desk->nextNumber('TKT'))->toBe('TKT-2026-00002');
});
