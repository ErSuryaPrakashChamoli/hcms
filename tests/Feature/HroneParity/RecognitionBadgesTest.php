<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Identity\Models\Role;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Services\RecognitionDefaults;
use App\Domain\Recognition\Services\Recognitions;
use App\Filament\Pages\Home;
use App\Filament\Resources\Badges\BadgeResource;
use App\Filament\Resources\Badges\Pages\ManageBadges;
use App\Filament\Resources\Recognitions\Pages\ListRecognitions;
use App\Filament\Resources\Recognitions\RecognitionResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-9c — Engage → Badges and Custom badges: HROne's system badges, the monthly quota, custom badge
 * rules (givers, receivers, nature, frequency, reason, attachment, certificate), withdrawal, visibility, the wall of
 * fame, LinkedIn and certificate settings, and the badges ledger report. */

function hp9cCustom(array $attributes = []): Badge
{
    static $n = 0;
    $n++;

    return Badge::query()->create($attributes + ['code' => "CUSTOM{$n}", 'name' => "Custom {$n}"]);
}

function hp9cBadge(string $code = 'TEAM_PLAYER'): Badge
{
    return Badge::query()->where('code', $code)->firstOrFail();
}

beforeEach(function () {
    $this->travelTo('2026-10-14 06:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->settings = app(SettingsRepository::class);
    $this->recognitions = app(Recognitions::class);
    $this->hr = tenantUser($this->tenant, ['recognition.view', 'recognition.give', 'recognition.manage', 'letter.view', 'letter.issue', 'letter.manage', 'settings.view', 'settings.update']);
    $this->manager = activeEmployee(null, ['recognition.view', 'recognition.give']);
    $this->asha = activeEmployee($this->manager, ['recognition.view', 'recognition.give']);
    $this->ravi = activeEmployee($this->manager, ['recognition.view', 'recognition.give']);
    $this->other = activeEmployee(null, ['recognition.view', 'recognition.give']);
});

it('seeds HROne’s nine system badges, which can be switched off or renamed but not deleted or re-coded', function () {
    expect(Badge::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['Super Woman', 'Making an Impact', 'Making Work Fun', 'Amazing Mentor', 'Great Job', 'Team Player', 'Thank You', 'Going Above and Beyond', 'Out of the Box Thinker'])
        ->and(Badge::query()->where('is_system', false)->count())->toBe(0)
        ->and(app(RecognitionDefaults::class)->seed())->toBe(0);

    $badge = hp9cBadge();
    $badge->update(['name' => 'Team hero', 'status' => 'inactive']);
    expect(fn () => $badge->refresh()->update(['code' => 'X']))->toThrow(RuntimeException::class, 'keeps its code')
        ->and(fn () => $badge->refresh()->delete())->toThrow(RuntimeException::class, 'cannot be deleted')
        ->and(fn () => $this->recognitions->give($badge->refresh(), $this->ravi, $this->asha->user))->toThrow(RuntimeException::class, 'switched off');
});

it('gives a badge, tells the receiver and keeps the ledger append-only; HR may withdraw it once', function () {
    $recognition = $this->recognitions->give(hp9cBadge(), $this->ravi, $this->asha->user, '<b>Thanks</b> for the release!');

    expect($recognition->message)->toBe('Thanks for the release!')->and($recognition->visibility)->toBe('public')
        ->and($recognition->giver_employee_id)->toBe($this->asha->id)->and($recognition->reference())->toBe('BDG-'.str_pad((string) $recognition->id, 6, '0', STR_PAD_LEFT))
        ->and(NotificationDelivery::query()->where('event', 'recognition.received')->where('user_id', $this->ravi->user_id)->value('subject'))->toBe($this->asha->user->name.' gave you the badge Team Player')
        ->and(fn () => $this->recognitions->give(hp9cBadge(), $this->asha, $this->asha->user))->toThrow(RuntimeException::class, 'yourself')
        ->and(fn () => $this->recognitions->give(hp9cBadge(), $this->asha, tenantUser($this->tenant, ['recognition.view'])))->toThrow(RuntimeException::class, 'cannot give badges')
        ->and(fn () => $recognition->update(['message' => 'Edited']))->toThrow(RuntimeException::class, 'never changed')
        ->and(fn () => $recognition->refresh()->delete())->toThrow(RuntimeException::class, 'never deleted');

    expect(fn () => $this->recognitions->withdraw($recognition->refresh(), $this->manager->user, 'No'))->toThrow(RuntimeException::class, 'Only HR')
        ->and(fn () => $this->recognitions->withdraw($recognition, $this->hr, ' '))->toThrow(RuntimeException::class, 'reason');
    $withdrawn = $this->recognitions->withdraw($recognition, $this->hr, 'Given by mistake');
    expect($withdrawn->isWithdrawn())->toBeTrue()->and($withdrawn->withdraw_reason)->toBe('Given by mistake')
        ->and(fn () => $this->recognitions->withdraw($withdrawn, $this->hr, 'Again'))->toThrow(RuntimeException::class, 'already withdrawn');
});

it('limits the badges one person gives per calendar month on the tenant’s clock; withdrawn ones do not count', function () {
    $this->settings->set('engage.badges.per_month', 2);
    $first = $this->recognitions->give(hp9cBadge('GREAT_JOB'), $this->ravi, $this->asha->user);
    $this->recognitions->give(hp9cBadge('THANK_YOU'), $this->other, $this->asha->user);
    expect($this->recognitions->quotaLeft($this->asha->user))->toBe(0)
        ->and(fn () => $this->recognitions->give(hp9cBadge('TEAM_PLAYER'), $this->manager, $this->asha->user))->toThrow(RuntimeException::class, 'given your 2 badges for this month');

    $this->recognitions->withdraw($first, $this->hr, 'Duplicate');
    expect($this->recognitions->quotaLeft($this->asha->user))->toBe(1);
    $this->recognitions->give(hp9cBadge('TEAM_PLAYER'), $this->manager, $this->asha->user);

    // 1 November on the tenant's clock (still 31 October in UTC): a new month.
    $this->travelTo('2026-10-31 19:00:00');
    expect($this->recognitions->quotaLeft($this->asha->user))->toBe(2);

    $this->settings->set('engage.badges.per_month', 0);
    expect($this->recognitions->quotaLeft($this->asha->user))->toBeNull();
});

it('applies custom badge rules: givers, receivers, reason, attachment and receiving frequency', function () {
    $role = Role::query()->where('slug', 'manager')->firstOrFail();
    $leaders = hp9cCustom(['name' => 'Leadership', 'giver_roles' => ['manager']]);
    expect($this->recognitions->refusalForGiver($leaders, $this->asha->user))->toContain('Only some roles');
    $this->asha->user->roles()->attach($role);
    expect($this->recognitions->refusalForGiver($leaders, $this->asha->user->fresh()))->toBeNull();

    $probationOnly = hp9cCustom(['receivers' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']]]);
    expect(fn () => $this->recognitions->give($probationOnly, $this->ravi, $this->other->user))->toThrow(RuntimeException::class, 'cannot be given to');
    forceLifecycle($this->ravi, 'probation');
    $this->recognitions->give($probationOnly, $this->ravi->refresh(), $this->other->user);

    $managersGive = hp9cCustom(['givers' => [['field' => 'employee_id', 'operator' => 'equals', 'value' => (string) $this->manager->id]]]);
    expect($this->recognitions->badgesFor($this->other->user)->pluck('id'))->not->toContain($managersGive->id)
        ->and($this->recognitions->badgesFor($this->manager->user)->pluck('id'))->toContain($managersGive->id);

    $strict = hp9cCustom(['reason_required' => true, 'attachment_required' => true, 'visibility' => 'private', 'receive_limit' => 1, 'receive_period' => 'month', 'receive_period_basis' => 'calendar']);
    expect(fn () => $this->recognitions->give($strict, $this->asha, $this->other->user))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $this->recognitions->give($strict, $this->asha, $this->other->user, 'Great demo'))->toThrow(RuntimeException::class, 'Attach a file')
        ->and(fn () => $this->recognitions->give($strict, $this->asha, $this->other->user, 'Great demo', ['elsewhere/proof.pdf']))->toThrow(RuntimeException::class, 'could not be found');

    $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
    $path = Recognitions::directory().'/proof.pdf';
    $disk->put($path, '%PDF-1.4 proof');
    $given = $this->recognitions->give($strict, $this->asha, $this->other->user, 'Great demo', [$path]);
    expect($given->visibility)->toBe('private')->and($given->attachments)->toBe([$path])
        ->and(fn () => $this->recognitions->give($strict, $this->asha, $this->ravi->user, 'Again', [$path]))->toThrow(RuntimeException::class, 'already received the badge '.$strict->name.' once this month');

    // A rolling month still counts the badge given on 14 October on 1 November; a calendar month does not.
    $this->travelTo('2026-11-01 06:00:00');
    expect($this->recognitions->refusalForGiver($strict, $this->ravi->user))->toBeNull();
    $strict->update(['receive_period_basis' => 'rolling']);
    expect(fn () => $this->recognitions->give($strict->refresh(), $this->asha, $this->ravi->user, 'Again', [$path]))->toThrow(RuntimeException::class, 'in the last month');
    $strict->update(['receive_period_basis' => 'calendar']);
    $this->recognitions->give($strict->refresh(), $this->asha, $this->ravi->user, 'Again', [$path]);

    $disk->delete($path);
});

it('switches custom badges off as a whole', function () {
    $custom = hp9cCustom();
    $this->settings->set('engage.badges.custom_allowed', false);
    expect(fn () => $this->recognitions->give($custom, $this->ravi, $this->asha->user))->toThrow(RuntimeException::class, 'Custom badges are switched off')
        ->and($this->recognitions->badgesFor($this->asha->user)->pluck('id'))->not->toContain($custom->id)->toHaveCount(9);

    $this->actingAs($this->hr);
    Livewire::test(ManageBadges::class)->assertActionHidden('create');
    $this->settings->set('engage.badges.custom_allowed', true);
    Livewire::test(ManageBadges::class)->assertActionVisible('create')
        ->callAction('create', ['name' => 'Customer hero', 'code' => 'customer_hero', 'icon' => 'heroicon-o-heart', 'tone' => 'rose', 'status' => 'active', 'visibility' => 'private',
            'restrict' => true, 'receive_limit' => 2, 'receive_period' => 'quarter', 'receive_period_basis' => 'calendar', 'reason_required' => true])
        ->assertHasNoActionErrors();
    expect(Badge::query()->where('code', 'CUSTOMER_HERO')->first())->toMatchArray(['visibility' => 'private', 'receive_limit' => 2, 'receive_period' => 'quarter', 'reason_required' => true, 'is_system' => false]);
});

it('shows private badges to the receiver, their manager, the giver and HR only', function () {
    $private = $this->recognitions->give(hp9cCustom(['visibility' => 'private']), $this->ravi, $this->asha->user, 'Quiet thanks');
    $public = $this->recognitions->give(hp9cBadge(), $this->ravi, $this->asha->user);
    $visible = fn ($user) => $this->recognitions->visibleTo($user)->pluck('id')->sort()->values()->all();

    expect($visible($this->ravi->user))->toBe([$private->id, $public->id])
        ->and($visible($this->asha->user))->toBe([$private->id, $public->id])
        ->and($visible($this->manager->user))->toBe([$private->id, $public->id])
        ->and($visible($this->hr))->toBe([$private->id, $public->id])
        ->and($visible($this->other->user))->toBe([$public->id])
        ->and($this->other->user->can('view', $private))->toBeFalse()
        ->and($this->other->user->can('view', $public))->toBeTrue()
        ->and($this->hr->can('update', $public))->toBeFalse();

    $this->actingAs($this->other->user);
    Livewire::test(ListRecognitions::class)->assertCanSeeTableRecords([$public])->assertCanNotSeeTableRecords([$private]);
});

it('gives a badge from the Recognition list and shows the wall of fame on Home', function () {
    $this->actingAs($this->asha->user);
    expect(RecognitionResource::getNavigationGroup())->toBe('Me');
    Livewire::test(ListRecognitions::class)->assertActionVisible('giveBadge')
        ->callAction('giveBadge', ['employee_id' => $this->ravi->id, 'badge_id' => hp9cBadge()->id, 'message' => 'Shipped it'])
        ->assertNotified('Team Player given to '.$this->ravi->person->first_name);
    $this->recognitions->give(hp9cBadge('GREAT_JOB'), $this->ravi, $this->other->user);
    $this->recognitions->give(hp9cBadge('THANK_YOU'), $this->other, $this->ravi->user);
    $this->recognitions->give(hp9cCustom(['visibility' => 'private']), $this->manager, $this->ravi->user);

    $fame = $this->recognitions->wallOfFame($this->manager->user);
    expect(array_map(fn ($row) => [$row['employee']->id, $row['count']], $fame))->toBe([[$this->ravi->id, 2], [$this->other->id, 1]])
        ->and($fame[0]['badge']->code)->toBe('GREAT_JOB');

    $this->actingAs($this->manager->user);
    Livewire::test(Home::class)->assertSee('Wall of fame')->assertSee($this->ravi->person->display_name)->assertSee('2 badges');

    $this->actingAs(tenantUser($this->tenant, ['recognition.give']));
    $this->get(BadgeResource::getUrl('index'))->assertForbidden();
});

it('issues the badge’s certificate through Letters and shares public badges on LinkedIn when switched on', function () {
    $template = LetterTemplate::query()->create(['code' => 'BADGE_CERT', 'type' => 'badge_certificate', 'name' => 'Badge certificate', 'format' => LetterTemplate::HTML, 'status' => 'active', 'requires_approval' => false,
        'subject' => 'Certificate – {{ badge.name }}', 'body' => '<p>{{ employee.name }} received {{ badge.name }} from {{ recognition.given_by }} on {{ recognition.date }}.</p>']);
    $badge = hp9cCustom(['name' => 'Star of the month', 'certificate_template_id' => $template->id]);

    $recognition = $this->recognitions->give($badge, $this->ravi, $this->asha->user, 'Brilliant');
    $letter = Letter::query()->where('source_type', $recognition->getMorphClass())->where('source_id', $recognition->id)->firstOrFail();
    expect($letter->status)->toBe('issued')->and($letter->type)->toBe('badge_certificate')
        ->and($letter->subject)->toBe('Certificate – Star of the month')->and($letter->body)->toContain('from '.$this->asha->user->name)
        ->and($recognition->certificate?->id)->toBe($letter->id);

    // The default certificate applies to badges without their own; a template needing approval waits for it.
    $template->update(['requires_approval' => true]);
    $this->settings->set('engage.badges.certificate_template', 'BADGE_CERT');
    $other = $this->recognitions->give(hp9cBadge(), $this->other, $this->asha->user);
    expect($other->certificate()->value('status'))->toBe('pending_approval')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'certificate_templates']))->toHaveKey('BADGE_CERT');

    // LinkedIn: off by default; then only for the receiver of a public badge.
    expect($this->recognitions->linkedInUrl($recognition, $this->ravi->user))->toBeNull();
    $this->settings->set('engage.badges.linkedin_share', true);
    $url = $this->recognitions->linkedInUrl($recognition, $this->ravi->user);
    expect($url)->toStartWith('https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME')->toContain('name=Star+of+the+month')->toContain('certId='.$recognition->reference())
        ->toContain('organizationName='.urlencode($this->tenant->name))
        ->and($this->recognitions->linkedInUrl($recognition, $this->asha->user))->toBeNull()
        ->and($this->recognitions->linkedInUrl($this->recognitions->give(hp9cCustom(['visibility' => 'private']), $this->ravi, $this->other->user), $this->ravi->user))->toBeNull();
});

it('reports the badges ledger to HR and is configured from Setup → Engage', function () {
    $this->recognitions->give(hp9cBadge(), $this->ravi, $this->asha->user, 'Thanks');
    $this->recognitions->give(hp9cCustom(['visibility' => 'private']), $this->asha, $this->ravi->user);

    $registry = app(DatasetRegistry::class);
    expect($registry->has('badges_ledger'))->toBeTrue()
        ->and($registry->get('badges_ledger')->allowedFor($this->hr))->toBeTrue()
        ->and($registry->get('badges_ledger')->allowedFor($this->asha->user))->toBeFalse()
        ->and(Capability::Engagement->permissionPrefixes())->toContain('recognition')
        ->and(config('peopleos.roles.employee.permissions'))->toContain('recognition.view', 'recognition.give')
        ->and(config('peopleos.roles.tenant-hr-admin.permissions'))->toContain('recognition.*');
    $template = app(ReportLibrary::class)->find('badges_ledger_this_month');
    $result = app(ReportRunner::class)->execute('badges_ledger', $template['definition'], $this->hr);
    expect(collect($result->rows)->pluck('badge')->sort()->values()->all())->toHaveCount(2)
        ->and(collect($result->rows)->pluck('visibility')->sort()->values()->all())->toBe(['private', 'public']);

    $catalogue = app(SetupCatalogue::class);
    $changed = $catalogue->save('engage', [SetupCatalogue::field('engage.badges.per_month') => '5', SetupCatalogue::field('engage.badges.linkedin_share') => true], 'Quota', $this->hr);
    expect($changed)->toBe(['engage.badges.per_month', 'engage.badges.linkedin_share'])->and($this->recognitions->perMonth())->toBe(5);
});
