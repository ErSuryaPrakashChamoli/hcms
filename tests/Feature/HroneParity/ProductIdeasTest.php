<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Services\ExportPackager;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Platform\Models\Tenant;
use App\Domain\ProductFeedback\Enums\ProductIdeaStatus;
use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Domain\ProductFeedback\Services\ProductIdeaDirectory;
use App\Domain\ProductFeedback\Services\ProductIdeas;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Pages\PlatformProductIdeasPage;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\ProductIdeas\Pages\ListProductIdeas;
use App\Filament\Resources\ProductIdeas\Pages\ViewProductIdea;
use App\Filament\Resources\ProductIdeas\ProductIdeaResource;
use App\Filament\Support\FormWizard;
use App\Http\Controllers\ParityFileController;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| HROne parity AC-3 (matrix 23 §23.9 #22, HROne's "Product enhancement request"): an organisation's HR suggests an
| improvement to Markedge through a step wizard (opened by ?action=create), follows its ideas, status and Markedge's response;
| Markedge's operators see the ideas across organisations and change the status (audited on both chains), and the sender is
| told. product.feedback gates the organisation side; only platform operators reach the platform page and its service.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant('Acme Ideas');
    $this->other = provisionTenant('Globex Ideas');
    $this->hr = tenantUser($this->tenant, ['product.feedback', 'employee.view', 'employee.create'], ['name' => 'Priya Admin', 'email' => 'priya@acme.test']);
    $this->viewer = tenantUser($this->tenant, ['employee.view', 'employee.create']);
    $this->otherHr = tenantUser($this->other, ['product.feedback']);
    $this->operator = platformAdmin();
    actAsTenant(null);
});

/** @return array<string, mixed> */
function productIdeaData(array $overrides = []): array
{
    return $overrides + [
        'module' => 'Attendance', 'process' => 'Attendance regularisation', 'kind' => 'improvement', 'title' => 'Bulk approve regularisations by team',
        'challenge' => 'Managers approve forty requests one by one every month end.', 'current_workaround' => 'We export them and approve in a spreadsheet.',
        'ideal_solution' => 'Select a team and approve all requests that match in one step.', 'priority' => 'high', 'impact_people' => 120,
    ];
}

function sendProductIdea(Tenant $tenant, User $user, array $overrides = []): ProductIdea
{
    return app(TenantContext::class)->runAs($tenant, fn () => app(ProductIdeas::class)->submit($user, productIdeaData($overrides)));
}

it('opens "Suggest an improvement" as a step wizard from its URL and sends the idea with its files', function () {
    Filament::getPanel('admin')->boot();
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);

    $this->get(ProductIdeaResource::suggestUrl())->assertOk()->assertSee('Suggest an improvement');
    expect(ProductIdeaResource::suggestUrl())->toContain('action=create');
    // ?action=create is Filament's default action: the page mounts it on load (wire:init).
    Livewire::withQueryParams(['action' => 'create'])->test(ListProductIdeas::class)->assertSet('defaultAction', 'create')
        ->mountAction('create')->assertActionMounted('create');
    // Four steps (what it is about, the challenge, the ideal solution, impact and files), then Review.
    $page = Livewire::test(ListProductIdeas::class)->instance();
    expect(FormWizard::contentSteps(ProductIdeaResource::form(Schema::make($page))))->toBe(4);

    Livewire::test(ListProductIdeas::class)
        ->callAction('create', productIdeaData(['attachments' => [UploadedFile::fake()->createWithContent('Month end screen.pdf', "%PDF-1.4\n%test\n")]]))
        ->assertHasNoActionErrors()->assertNotified();

    $idea = ProductIdea::query()->sole();
    expect($idea->reference)->toBe(ProductIdea::referenceFor($idea->id))->and($idea->reference)->toStartWith('IDEA-')
        ->and($idea->status)->toBe(ProductIdeaStatus::Submitted)->and($idea->kind->value)->toBe('improvement')
        ->and($idea->module)->toBe('Attendance')->and($idea->process)->toBe('Attendance regularisation')
        ->and((int) $idea->submitted_by)->toBe($this->hr->id)->and($idea->submitted_at)->not->toBeNull()->and($idea->status_changed_at)->not->toBeNull()
        ->and($idea->impact_people)->toBe(120)->and($idea->priority)->toBe('high')->and($idea->platform_response)->toBeNull()
        ->and($idea->attachments)->toHaveCount(1)->and($idea->attachments[0])->toStartWith("tenants/{$this->tenant->id}/product-ideas/")
        ->and($idea->attachmentNames())->toBe(['month-end-screen.pdf']);
    Storage::disk('local')->assertExists($idea->attachments[0]);

    $submitted = AuditEvent::query()->where('entity_type', ProductIdea::class)->where('entity_id', (string) $idea->id)->sole();
    expect($submitted->action->value)->toBe('SUBMITTED')->and($submitted->module)->toBe('product_feedback')->and((int) $submitted->tenant_id)->toBe($this->tenant->id)
        ->and((int) $submitted->actor_id)->toBe($this->hr->id);

    // The organisation's list and the idea page; the file opens through a signed, authorised, audited link.
    Livewire::test(ListProductIdeas::class)->assertCanSeeTableRecords([$idea]);
    $this->get(ProductIdeaResource::getUrl('view', ['record' => $idea]))->assertOk()->assertSee($idea->reference)->assertSee('Bulk approve regularisations by team')
        ->assertSee('Submitted')->assertSee('No response yet');
    $this->get(ParityFileController::url('product-idea', $idea, 0))->assertOk();
    expect(AuditEvent::query()->where('entity_id', (string) $idea->id)->where('action', 'ATTACHMENT_DOWNLOADED')->exists())->toBeTrue();
});

it('keeps an organisation\'s ideas from every other organisation', function () {
    $idea = sendProductIdea($this->tenant, $this->hr);
    $theirs = sendProductIdea($this->other, $this->otherHr, ['title' => 'Globex wants payroll previews']);

    actAsTenant($this->other);
    $this->actingAs($this->otherHr);
    expect(ProductIdea::query()->pluck('id')->all())->toBe([$theirs->id]);
    Livewire::test(ListProductIdeas::class)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$idea]);
    $this->get(ProductIdeaResource::getUrl('view', ['record' => $idea->id]))->assertNotFound();
    $this->get(ParityFileController::url('product-idea', $idea, 0))->assertNotFound();
    actAsTenant(null);
    expect(ProductIdea::query()->count())->toBe(0);
});

it('gates the organisation side with product.feedback and refuses platform operators sending ideas', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->viewer);
    $this->get(ProductIdeaResource::getUrl('index'))->assertForbidden();
    expect(ProductIdeaResource::canAccess())->toBeFalse()->and(ProductIdeaResource::canCreate())->toBeFalse()
        ->and(fn () => app(ProductIdeas::class)->submit($this->viewer, productIdeaData()))->toThrow(RuntimeException::class, 'You may not send product ideas.');

    $this->actingAs($this->hr);
    expect(ProductIdeaResource::canAccess())->toBeTrue()->and(ProductIdeaResource::canCreate())->toBeTrue()
        ->and(fn () => app(ProductIdeas::class)->submit($this->hr, productIdeaData(['module' => 'Recruitment'])))->toThrow(RuntimeException::class, 'Choose the module the idea is about.')
        ->and(fn () => app(ProductIdeas::class)->submit($this->hr, productIdeaData(['kind' => 'wish'])))->toThrow(RuntimeException::class, 'Say whether PeopleOS lacks the feature')
        ->and(fn () => app(ProductIdeas::class)->submit($this->hr, productIdeaData(['challenge' => 'short'])))->toThrow(RuntimeException::class, 'the challenge you face in a few more words')
        ->and(fn () => app(ProductIdeas::class)->submit($this->hr, productIdeaData(['attachments' => ['elsewhere/secret.pdf']])))->toThrow(RuntimeException::class, 'An attached file was not found.');

    // A Markedge operator has the platform page instead, and never sends an idea in an organisation's name.
    $this->actingAs($this->operator);
    expect(ProductIdeaResource::canAccess())->toBeFalse()->and(ProductIdeaResource::canCreate())->toBeFalse()
        ->and(fn () => app(ProductIdeas::class)->submit($this->operator, productIdeaData()))->toThrow(RuntimeException::class, 'Product ideas are sent by the organisation\'s own people.');
    expect(ProductIdea::query()->count())->toBe(0);

    // Role templates: the account owner and the HR admin hold the key (the super admin through *), nobody else by default.
    expect(config('peopleos.roles.account-owner.permissions'))->toContain('product.feedback')
        ->and(config('peopleos.roles.tenant-hr-admin.permissions'))->toContain('product.feedback')
        ->and(config('peopleos.roles.hr-manager.permissions'))->not->toContain('product.feedback')
        ->and(config('peopleos.roles.employee.permissions'))->not->toContain('product.feedback')
        ->and(Role::query()->where('slug', 'account-owner')->sole()->permissions()->pluck('key')->all())->toContain('product.feedback')
        ->and(Role::query()->where('slug', 'tenant-hr-admin')->sole()->permissions()->pluck('key')->all())->toContain('product.feedback');
});

it('lists the ideas of every organisation for platform operators, with filters, and never for tenant users', function () {
    $idea = sendProductIdea($this->tenant, $this->hr);
    $theirs = sendProductIdea($this->other, $this->otherHr, ['title' => 'Globex wants payroll previews', 'module' => 'Payroll', 'kind' => 'new_feature', 'process' => 'Payroll preview']);

    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    $this->get(PlatformProductIdeasPage::getUrl())->assertForbidden();
    expect(PlatformProductIdeasPage::canAccess())->toBeFalse()
        ->and(fn () => app(ProductIdeaDirectory::class)->list($this->hr))->toThrow(RuntimeException::class, 'Only platform operators can see product ideas.');

    actAsTenant(null);
    $this->actingAs($this->operator);
    $this->get(PlatformProductIdeasPage::getUrl())->assertOk()
        ->assertSee($idea->reference)->assertSee($theirs->reference)->assertSee('Acme Ideas')->assertSee('Globex Ideas')->assertSee('Globex wants payroll previews');

    $page = fn () => Livewire::test(PlatformProductIdeasPage::class);
    $page()->set('tenant', $this->other->id)->assertSee($theirs->reference)->assertDontSee($idea->reference);
    $page()->set('module', 'Attendance')->assertSee($idea->reference)->assertDontSee($theirs->reference);
    $page()->set('kind', 'new_feature')->assertSee($theirs->reference)->assertDontSee($idea->reference);
    $page()->set('search', $idea->reference)->assertSee('Bulk approve regularisations by team')->assertDontSee('Globex wants payroll previews');
    $page()->set('status', 'planned')->assertSee('No product ideas match.');
    expect(app(ProductIdeaDirectory::class)->counts($this->operator))->toBe(['submitted' => 2])
        ->and(app(ProductIdeaDirectory::class)->tenantOptions($this->operator))->toBe([$this->tenant->id => 'Acme Ideas', $this->other->id => 'Globex Ideas']);

    // One idea: the text as written, the organisation and the sender (name and work e-mail).
    $page()->set('idea', $idea->id)->assertSee('Managers approve forty requests one by one every month end.')
        ->assertSee('We export them and approve in a spreadsheet.')->assertSee('Priya Admin')->assertSee('priya@acme.test')->assertSee('Attendance regularisation');
});

it('lets an operator change the status with a response, audited on both chains, and tells the person who sent the idea', function () {
    $idea = sendProductIdea($this->tenant, $this->hr);
    $this->actingAs($this->operator);

    Livewire::test(PlatformProductIdeasPage::class)->set('idea', $idea->id)
        ->callAction('changeStatus', ['status' => 'planned', 'platform_response' => 'On the roadmap for the next quarter.'])
        ->assertHasNoActionErrors()->assertNotified("{$idea->reference} is now Planned");

    $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => ProductIdea::query()->findOrFail($idea->id));
    expect($fresh->status)->toBe(ProductIdeaStatus::Planned)->and($fresh->platform_response)->toBe('On the roadmap for the next quarter.')
        ->and($fresh->status_changed_at)->not->toBeNull();

    // The organisation's chain: STATUS_CHANGE with the field changes, by the operator.
    $tenantEvent = AuditEvent::query()->withoutTenancy()->where('entity_type', ProductIdea::class)->where('entity_id', (string) $idea->id)->where('action', 'STATUS_CHANGE')->sole();
    expect((int) $tenantEvent->tenant_id)->toBe($this->tenant->id)->and((int) $tenantEvent->actor_id)->toBe($this->operator->id)
        ->and($tenantEvent->reason)->toBe('Markedge: Submitted → Planned')
        ->and($tenantEvent->fieldChanges()->pluck('field')->all())->toContain('status', 'platform_response');
    // The platform chain: reference, organisation, from → to; never the idea's text.
    $platformEvent = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('module', 'product_feedback')->where('action', 'STATUS_CHANGE')->sole();
    expect((int) $platformEvent->actor_id)->toBe($this->operator->id)->and($platformEvent->metadata)->toMatchArray([
        'subject_tenant_id' => $this->tenant->id, 'reference' => $idea->reference, 'from' => 'submitted', 'to' => 'planned', 'response_changed' => true,
    ])->and(json_encode($platformEvent->metadata))->not->toContain('forty requests');

    // The sender is told in the app and by e-mail; the bell opens the idea.
    $deliveries = app(TenantContext::class)->runAs($this->tenant, fn () => NotificationDelivery::query()->where('event', ProductIdeas::STATUS_EVENT)->get());
    expect($deliveries->pluck('channel')->sort()->values()->all())->toBe(['email', 'in_app'])
        ->and($deliveries->every(fn ($d) => (int) $d->user_id === $this->hr->id && $d->source_type === (new ProductIdea)->getMorphClass() && (int) $d->source_id === $idea->id))->toBeTrue()
        ->and($deliveries->first()->subject)->toBe("Product idea {$idea->reference}: Planned")
        ->and($deliveries->first()->body)->toContain('On the roadmap for the next quarter.')
        ->and($this->hr->notifications()->count())->toBe(1);
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    expect((new NotificationCenter)->linkFor((new ProductIdea)->getMorphClass(), $idea->id))->toBe(ProductIdeaResource::getUrl('view', ['record' => $idea->id]));
    $this->get(ProductIdeaResource::getUrl('view', ['record' => $idea->id]))->assertOk()->assertSee('Planned')->assertSee('On the roadmap for the next quarter.');

    // A response alone is an update too ("Markedge replied"); nothing changing is refused; declining needs a reason.
    actAsTenant(null);
    $ideas = app(ProductIdeas::class);
    $ideas->changeStatus($fresh, $this->operator, 'planned', 'Planned for the January release.');
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => NotificationDelivery::query()->where('event', ProductIdeas::STATUS_EVENT)->latest('id')->first()->subject))
        ->toBe("Product idea {$idea->reference}: Markedge replied")
        ->and(fn () => $ideas->changeStatus($fresh, $this->operator, 'planned', 'Planned for the January release.'))->toThrow(RuntimeException::class, 'Nothing changes')
        ->and(fn () => $ideas->changeStatus($fresh, $this->operator, 'declined', null))->toThrow(RuntimeException::class, 'Say why the idea is declined')
        ->and(fn () => $ideas->changeStatus($fresh, $this->operator, 'submitted', 'Back'))->toThrow(RuntimeException::class, 'Only the organisation submits an idea')
        ->and(fn () => $ideas->changeStatus($fresh, $this->hr, 'released', 'Done'))->toThrow(RuntimeException::class, 'Only platform operators can answer product ideas.');
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => ProductIdea::query()->findOrFail($idea->id)->status))->toBe(ProductIdeaStatus::Planned);
});

it('lets an operator open an idea\'s files, audited on both chains', function () {
    Storage::disk('local')->put('product-ideas/upload.pdf', "%PDF-1.4\n%test\n");
    $idea = sendProductIdea($this->tenant, $this->hr, ['attachments' => ['product-ideas/upload.pdf'], 'attachment_names' => ['product-ideas/upload.pdf' => 'Payroll screen.pdf']]);
    expect($idea->attachmentNames())->toBe(['payroll-screen.pdf']);
    Storage::disk('local')->assertMissing('product-ideas/upload.pdf');

    $this->actingAs($this->operator);
    Livewire::test(PlatformProductIdeasPage::class)->set('idea', $idea->id)->assertSee('payroll-screen.pdf')
        ->callAction(TestAction::make('downloadAttachment')->arguments(['index' => 0]))->assertFileDownloaded('payroll-screen.pdf');
    $events = AuditEvent::query()->withoutTenancy()->where('module', 'product_feedback')->where('action', 'ATTACHMENT_DOWNLOADED')->get();
    expect($events)->toHaveCount(2)->and($events->pluck('tenant_id')->map(fn ($id) => $id === null ? null : (int) $id)->sort()->values()->all())->toBe([null, $this->tenant->id])
        ->and($events->every(fn ($e) => (int) $e->actor_id === $this->operator->id))->toBeTrue()
        ->and(fn () => app(ProductIdeas::class)->attachmentPath($idea, $this->hr, 0))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => app(ProductIdeas::class)->attachmentPath($idea, $this->operator, 3))->toThrow(RuntimeException::class, 'The file was not found.');
});

it('puts the screens on the Lifecycle desk for those who may send ideas', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    $section = collect(Livewire::test(WorkforceDesk::class)->instance()->deskSections())->firstWhere('label', 'Ideas for PeopleOS');
    expect(collect($section['items'])->pluck('url', 'label')->all())->toBe([
        'Suggest an improvement' => ProductIdeaResource::suggestUrl(), 'Product ideas' => ProductIdeaResource::getUrl('index'),
    ]);

    $this->actingAs($this->viewer);
    expect(collect(Livewire::test(WorkforceDesk::class)->instance()->deskSections())->firstWhere('label', 'Ideas for PeopleOS'))->toBeNull();
});

it('classifies product ideas as the organisation\'s data for export and deletion', function () {
    expect(DataInventory::categoryOf('product_ideas'))->toBe('service_desk')
        ->and(DataInventory::CATEGORIES['service_desk']['export'])->toBeTrue()
        ->and(DataInventory::CATEGORIES['service_desk']['action'])->toBe(DataInventory::DELETE)
        ->and(app(ExportPackager::class)->plan())->toContain(['service_desk', 'product_ideas'])
        ->and(DataInventory::prefixes($this->tenant)['service_desk'])->toContain(['disk' => 'local', 'prefix' => "tenants/{$this->tenant->id}/product-ideas"]);
});

it('shows the idea page for the organisation with the response and files', function () {
    Storage::disk('local')->put('product-ideas/a.pdf', "%PDF-1.4\n%a\n");
    $idea = sendProductIdea($this->tenant, $this->hr, ['attachments' => ['product-ideas/a.pdf'], 'attachment_names' => ['product-ideas/a.pdf' => 'Report.pdf'], 'current_workaround' => null]);
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);
    Livewire::test(ViewProductIdea::class, ['record' => $idea->id])->assertOk()->assertSee('report.pdf')->assertSee('Priya Admin')->assertSee('High');
});
