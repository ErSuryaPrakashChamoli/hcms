<?php

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Filament\Pages\AnnouncementsFeed;
use App\Filament\Pages\MyDay;
use App\Filament\Pages\MyTeam;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\ViewArticle;
use App\Filament\Resources\GrievanceCategories\GrievanceCategoryResource;
use App\Filament\Resources\Grievances\GrievanceResource;
use App\Filament\Resources\TicketCategories\TicketCategoryResource;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['servicedesk.request', 'grievance.raise', 'kb.view', 'communication.view', 'leave.apply', 'attendance.regularise', 'performance.team', 'task.view']);
    $this->employee = activeEmployee($this->manager, ['servicedesk.request', 'grievance.raise', 'kb.view', 'communication.view', 'leave.apply', 'attendance.regularise', 'task.view']);
    $this->ticket = app(ServiceDesk::class)->open($this->employee, TicketCategory::query()->where('code', 'PAYROLL')->first(), 'Payslip missing', 'August payslip not visible');
    $this->case = app(Grievances::class)->raise(GrievanceCategory::query()->where('code', 'SAFETY')->first(), $this->employee, 'Wiring', 'Loose cable', 'high', false, $this->employee->user);
    $this->article = Article::create(['title' => 'Leave policy', 'category' => 'leave', 'body' => '# Leave', 'requires_acknowledgement' => true]);
    app(KnowledgeBase::class)->publish($this->article, $this->admin);
    actAsTenant(null);
});

it('renders the HR-side pages', function () {
    $this->get(TicketResource::getUrl('index'))->assertOk()->assertSee('TKT-2026-00001');
    $this->get(TicketResource::getUrl('view', ['record' => $this->ticket]))->assertOk()->assertSee('Payslip missing');
    $this->get(TicketCategoryResource::getUrl('index'))->assertOk()->assertSee('Letters');
    $this->get(GrievanceResource::getUrl('index'))->assertOk()->assertSee('GRV-2026-00001');
    $this->get(GrievanceResource::getUrl('view', ['record' => $this->case]))->assertOk()->assertSee('Loose cable');
    $this->get(GrievanceCategoryResource::getUrl('index'))->assertOk()->assertSee('PoSH');
    $this->get(ArticleResource::getUrl('index'))->assertOk()->assertSee('Leave policy');
    $this->get(ArticleResource::getUrl('create'))->assertOk();
    $this->get(AnnouncementResource::getUrl('index'))->assertOk();
    $this->get(AnnouncementResource::getUrl('create'))->assertOk();
});

it('renders My Day, My Team and the employee-side pages with the right scope', function () {
    $this->actingAs($this->employee->user);
    $this->get(MyDay::getUrl())->assertOk()->assertSee('Needs attention')->assertSee('Check in')->assertSee('Ask HR');
    $this->get(MyTeam::getUrl())->assertForbidden();
    $this->get(TicketResource::getUrl('index'))->assertOk()->assertSee('My requests')->assertSee('TKT-2026-00001');
    $this->get(GrievanceResource::getUrl('index'))->assertOk()->assertSee('GRV-2026-00001');
    $this->get(ArticleResource::getUrl('index'))->assertOk()->assertSee('Knowledge base')->assertSee('Leave policy');
    $this->get(ArticleResource::getUrl('view', ['record' => $this->article]))->assertOk()->assertSee('I have read and understood');
    $this->get(AnnouncementsFeed::getUrl())->assertOk();
    $this->get(TicketCategoryResource::getUrl('index'))->assertForbidden();
    $this->get(AnnouncementResource::getUrl('index'))->assertForbidden();

    $this->actingAs($this->manager->user);
    $this->get(MyTeam::getUrl())->assertOk()->assertSee($this->employee->person->full_name);
    $this->get(GrievanceResource::getUrl('index'))->assertOk()->assertDontSee('GRV-2026-00001'); // not a handler
});

it('drives the portal actions: check in, ask HR, reply, acknowledge an article', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);

    Livewire::test(MyDay::class)->call('punch', 'in')->assertNotified('Checked in');
    expect(AttendancePunch::query()->where('employee_id', $this->employee->id)->count())->toBe(1);

    Livewire::test(ListTickets::class)
        ->callAction('askHr', data: ['ticket_category_id' => TicketCategory::query()->where('code', 'LETTER')->value('id'), 'subject' => 'Address proof', 'description' => 'For the bank', 'priority' => 'normal'])
        ->assertNotified('Request TKT-2026-00002 raised');

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])->callAction('reply', data: ['body' => 'Any update?'])->assertNotified('Reply posted');
    expect(Ticket::query()->find($this->ticket->id)->comments()->count())->toBe(1);

    Livewire::test(ViewArticle::class, ['record' => $this->article->id])->callAction('acknowledge')->assertNotified('Acknowledged');
    expect(app(KnowledgeBase::class)->pendingAcknowledgements($this->employee))->toHaveCount(0);

    $this->actingAs($this->admin);
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])->callAction('resolve', data: ['resolution' => 'Payslip regenerated'])->assertNotified('Resolved');
    expect(Ticket::query()->find($this->ticket->id)->status)->toBe('resolved');
});
