<?php

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\CommunicationPreference;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\SurveyResults;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\EmployeeFeedback\EmployeeFeedbackResource;
use App\Filament\Resources\EngagementCampaigns\EngagementCampaignResource;
use App\Filament\Resources\Surveys\Pages\CreateSurvey;
use App\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Filament\Resources\Surveys\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\Surveys\SurveyResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->staff = engagementTeam(5);
    $this->version = openEngagementSurvey($this->actors, ['name' => 'Autumn pulse']);
    $this->admin = tenantUser($this->tenant, ['*']);
});

it('renders the engagement admin pages and creates a survey with its first draft version', function () {
    $this->actingAs($this->admin);
    $this->get(SurveyResource::getUrl('index'))->assertOk()->assertSee('Autumn pulse');
    $this->get(SurveyResource::getUrl('edit', ['record' => $this->version->survey_id]))->assertOk();
    $this->get(AudienceResource::getUrl('index'))->assertOk();
    $this->get(AudienceResource::getUrl('create'))->assertOk();
    $this->get(EngagementCampaignResource::getUrl('index'))->assertOk();
    $this->get(EngagementCampaignResource::getUrl('create'))->assertOk();
    $this->get(EmployeeFeedbackResource::getUrl('index'))->assertOk();
    $this->get(AnnouncementResource::getUrl('index'))->assertOk();
    $this->get(AnnouncementResource::getUrl('create'))->assertOk();

    $this->actingAs($this->actors['preparer']);
    Livewire::test(CreateSurvey::class)->fillForm(['code' => 'WELL', 'name' => 'Wellbeing', 'survey_type' => 'pulse', 'category' => 'wellbeing', 'anonymity_mode' => 'anonymous', 'closes_at' => '2026-10-30 18:00:00'])
        ->call('create')->assertHasNoFormErrors();
    $survey = Survey::query()->where('code', 'WELL')->firstOrFail();
    expect($survey->versions()->first()->status)->toBe('draft');

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $survey, 'pageClass' => EditSurvey::class])
        ->callTableAction('questions', $survey->versions()->first(), ['questions' => [['key' => 'sleep', 'type' => 'rating', 'prompt' => 'How do you sleep?', 'required' => true, 'scale_min' => 1, 'scale_max' => 5]]])
        ->assertNotified('Questions saved');
    expect($survey->versions()->first()->questions()->pluck('key')->all())->toBe(['sleep']);
});

it('shows results to the analyst only after closing and never to an employee without visibility', function () {
    $this->actingAs($this->actors['analyst']);
    $this->get(SurveyResults::getUrl(['version' => $this->version->id]))->assertOk()->assertSee('available once the survey closes');
    foreach ($this->staff as $e) {
        app(SurveyResponses::class)->submit($this->version, $e->user, ['mood' => '4', 'comment' => 'Secret comment from '.$e->id]);
    }
    app(Surveys::class)->close($this->version, $this->actors['preparer']);
    $this->get(SurveyResults::getUrl(['version' => $this->version->id]))->assertOk()->assertSee('Respondents: 5')->assertDontSee('Secret comment');

    $this->actingAs($this->staff[0]->user);
    $this->get(SurveyResults::getUrl(['version' => $this->version->id]))->assertForbidden();
});

it('lets an employee take a survey, give feedback, read and acknowledge communications and set preferences from My HR', function () {
    $employee = $this->staff[0];
    $this->actingAs($employee->user);
    $this->get(MyHr::getUrl(['tab' => 'surveys']))->assertOk()->assertSee('Autumn pulse')->assertSee('Take survey');
    Livewire::test(MyHr::class, ['tab' => 'surveys'])
        ->callAction('takeSurvey', ['answers' => ['mood' => '5', 'tools' => ['wiki']]], ['version' => $this->version->id])
        ->assertNotified('Thank you — your response was recorded.');
    expect(SurveyResponse::query()->count())->toBe(1)->and(SurveyResponse::query()->first()->employee_id)->toBeNull();
    $this->get(MyHr::getUrl(['tab' => 'surveys']))->assertOk()->assertSee('Submitted')->assertDontSee('Take survey');

    Livewire::test(MyHr::class, ['tab' => 'feedback'])->callAction('giveFeedback', ['mode' => 'anonymous', 'category' => 'workplace', 'body' => 'The canteen is too loud'])
        ->assertNotified('Thank you — your feedback was sent.');
    expect(EmployeeFeedback::query()->first()->employee_id)->toBeNull();
    $this->get(MyHr::getUrl(['tab' => 'feedback']))->assertOk()->assertDontSee('The canteen is too loud');

    $comms = app(Communications::class);
    $a = $comms->create(['title' => 'Town hall on Friday', 'body' => 'Join us at **4 pm**.', 'requires_acknowledgement' => true], $this->actors['preparer']);
    $comms->publish($comms->approve($comms->submit($a, $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $this->get(MyHr::getUrl(['tab' => 'communications']))->assertOk()->assertSee('Town hall on Friday')->assertSee('Acknowledge');
    Livewire::test(MyHr::class, ['tab' => 'communications'])->call('acknowledgeAnnouncement', $a->id)->assertNotified('Acknowledged');

    $this->get(MyHr::getUrl(['tab' => 'preferences']))->assertOk()->assertSee('Newsletter')->assertSee('Always delivered');
    Livewire::test(MyHr::class, ['tab' => 'preferences'])->call('setPreference', 'newsletter', 'email', false)->assertNotified('Preference saved');
    expect(CommunicationPreference::query()->where('category', 'newsletter')->first()->email)->toBeFalse();
    Livewire::test(MyHr::class, ['tab' => 'preferences'])->call('setPreference', 'policy', 'email', false)->assertNotified('Not allowed');
    expect(Announcement::query()->count())->toBe(1);
});
