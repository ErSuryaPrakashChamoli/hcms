<?php

use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Models\QuestionBank;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Filament\Resources\FeedbackTemplates\Pages\ManageFeedbackTemplates;
use App\Filament\Resources\QuestionBanks\Pages\ManageQuestionBanks;
use App\Filament\Support\PolicySettingsSchema;
use Livewire\Livewire;

/* HROne setup parity P13 — Forms & templates → Feedback templates: question banks, templates bound to processes, the library. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->templates = app(FeedbackTemplates::class);
});

it('checks the questions of templates and banks and tidies them', function () {
    expect(fn () => FeedbackQuestions::normalise([['title' => 'Pick one', 'type' => 'single_choice', 'options' => ['Only']]]))->toThrow(RuntimeException::class, 'between 2 and 50 options')
        ->and(fn () => FeedbackQuestions::normalise([['title' => 'Rate', 'type' => 'rating', 'scale_max' => 11]]))->toThrow(RuntimeException::class, 'between 2 and 10')
        ->and(fn () => FeedbackQuestions::normalise([['title' => 'Draw', 'type' => 'sketch']]))->toThrow(RuntimeException::class, 'unknown response type')
        ->and(fn () => FeedbackQuestions::normalise([['title' => ' ', 'type' => 'text']]))->toThrow(RuntimeException::class, 'needs a title')
        ->and(fn () => $this->templates->saveTemplate(null, ['code' => 'x1', 'name' => 'Empty', 'process' => 'confirmation', 'questions' => []], null, $this->hr))->toThrow(RuntimeException::class, 'at least one question')
        ->and(fn () => $this->templates->saveTemplate(null, ['code' => 'x2', 'name' => 'Odd', 'process' => 'appraisal', 'questions' => [['title' => 'A', 'type' => 'text']]], null, $this->hr))->toThrow(RuntimeException::class, 'Unknown process');

    $template = $this->templates->saveTemplate(null, ['code' => 'conf-1', 'name' => 'Confirmation', 'process' => 'confirmation', 'questions' => [
        ['title' => 'Quality of work', 'type' => 'rating', 'scale_max' => 4],
        ['key' => 'quality_of_work', 'title' => 'Recommendation', 'type' => 'single_choice', 'options' => ['Confirm', 'Extend the probation', 'Confirm']],
        ['key' => 'quality_of_work', 'title' => 'Comments', 'type' => 'text', 'mandatory' => false],
    ]], 'Set up', $this->hr);

    expect($template->code)->toBe('CONF-1')->and($template->module)->toBe('workforce')
        ->and(array_column($template->questions, 'key'))->toBe(['q1', 'quality_of_work', 'q3'])
        ->and($template->questions[1]['options'])->toBe([['value' => 'confirm', 'label' => 'Confirm'], ['value' => 'extend_the_probation', 'label' => 'Extend the probation']])
        ->and($template->questions[0]['scale_max'])->toBe(4)->and($template->questions[2]['mandatory'])->toBeFalse();

    // Answers are checked against the questions: mandatory ones answered, choices among the options, ratings on the scale.
    expect(fn () => FeedbackQuestions::validate($template->questions, ['q1' => 3]))->toThrow(RuntimeException::class, 'Answer "Recommendation"')
        ->and(fn () => FeedbackQuestions::validate($template->questions, ['q1' => 5, 'quality_of_work' => 'confirm']))->toThrow(RuntimeException::class, 'rated from 1 to 4')
        ->and(fn () => FeedbackQuestions::validate($template->questions, ['q1' => 3, 'quality_of_work' => 'promote']))->toThrow(RuntimeException::class, 'Choose one of the options')
        ->and(FeedbackQuestions::validate($template->questions, ['q1' => '3', 'quality_of_work' => 'confirm', 'extra' => 'dropped']))->toBe(['q1' => 3, 'quality_of_work' => 'confirm'])
        ->and(FeedbackQuestions::display($template->questions[1], 'extend_the_probation'))->toBe('Extend the probation');
});

it('adds templates from the library without overwriting the organisation\'s own, and carries banks and templates in blueprints', function () {
    expect(FeedbackTemplates::library())->toHaveKeys(['EXIT_EMPLOYEE', 'EXIT_MANAGER', 'EXIT_HR', 'CONFIRMATION', 'PROBATION_SELF', 'INDUCTION_EVAL'])
        ->and(fn () => $this->templates->addFromLibrary(['NOPE'], $this->hr))->toThrow(RuntimeException::class, 'Choose templates from the library');

    expect($this->templates->addFromLibrary(['EXIT_MANAGER', 'INDUCTION_EVAL'], $this->hr))->toBe(2)
        ->and(QuestionBank::query()->pluck('code')->sort()->values()->all())->toBe(['EXIT', 'INDUCTION', 'PROBATION'])
        ->and(QuestionBank::query()->where('code', 'EXIT')->first()->questions()->count())->toBe(5);

    FeedbackTemplate::query()->where('code', 'EXIT_MANAGER')->first()->update(['name' => 'Our manager exit form']);
    expect($this->templates->addFromLibrary(['EXIT_MANAGER', 'CONFIRMATION'], $this->hr))->toBe(1)
        ->and(FeedbackTemplate::query()->where('code', 'EXIT_MANAGER')->value('name'))->toBe('Our manager exit form')
        ->and(FeedbackTemplate::query()->where('code', 'CONFIRMATION')->value('process'))->toBe('confirmation');

    // The library is also a configuration pack, and blueprints carry banks and templates to another tenant.
    expect(app(Blueprints::class)->packs())->toHaveKey(FeedbackTemplates::PACK);
    $export = app(Blueprints::class)->export();
    expect(collect($export['feedback_templates'])->pluck('code')->all())->toContain('EXIT_MANAGER', 'CONFIRMATION')
        ->and(collect($export['question_banks'])->pluck('code')->all())->toBe(['EXIT', 'INDUCTION', 'PROBATION']);

    $other = provisionTenant('Other');
    actAsTenant($other);
    $this->actingAs(tenantUser($other, ['*']));
    $counts = app(Blueprints::class)->import($export, 'Copied');
    expect($counts['feedback_templates'])->toBe(3)->and($counts['question_banks'])->toBe(3)
        ->and(FeedbackTemplate::query()->where('code', 'EXIT_MANAGER')->value('name'))->toBe('Our manager exit form');
    expect(app(Blueprints::class)->applyPack(FeedbackTemplates::PACK)['feedback_templates'])->toBe(6)
        ->and(FeedbackTemplate::query()->count())->toBe(6);
});

it('offers active templates of the field\'s process to the policies and the Setup centre', function () {
    $this->templates->addFromLibrary(['EXIT_MANAGER', 'EXIT_HR', 'CONFIRMATION'], $this->hr);
    $manager = FeedbackTemplate::query()->where('code', 'EXIT_MANAGER')->first();
    FeedbackTemplate::query()->where('code', 'EXIT_HR')->first()->update(['status' => 'inactive']);
    $policyOptions = fn (array $field) => (new ReflectionMethod(PolicySettingsSchema::class, 'options'))->invoke(null, $field);

    expect($policyOptions(['options_from' => 'feedback_templates', 'process' => 'exit_interview_manager']))->toBe([$manager->id => $manager->name])
        ->and($policyOptions(['options_from' => 'feedback_templates', 'process' => 'exit_interview_hr']))->toBe([])
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'feedback_templates']))->toHaveCount(2);

    $fields = collect(config('peopleos.policies.types.exit.fields'))->firstWhere('key', 'interviews')['fields'];
    expect(collect($fields)->pluck('key')->all())->toBe(['interview_employee_template_id', 'interview_manager', 'interview_manager_template_id', 'interview_hr', 'interview_hr_template_id', 'interview_hr_role'])
        ->and(collect(PolicySettingsSchema::steps('exit'))->pluck('label')->all())->toContain('Exit interviews')
        ->and(collect(PolicySettingsSchema::steps('confirmation'))->pluck('label')->all())->toContain('Feedback');
});

it('lets only feedback managers set up banks and templates; viewers only look', function () {
    $viewer = tenantUser($this->tenant, ['feedback.view']);
    $employee = tenantUser($this->tenant, ['employee.self']);

    expect(fn () => $this->templates->saveTemplate(null, ['code' => 'A', 'name' => 'A', 'process' => 'induction', 'questions' => [['title' => 'Q', 'type' => 'text']]], null, $viewer))->toThrow(RuntimeException::class, 'cannot set up')
        ->and(fn () => $this->templates->saveBank(null, ['code' => 'B', 'name' => 'B'], [], null, $viewer))->toThrow(RuntimeException::class, 'cannot set up')
        ->and(fn () => $this->templates->addFromLibrary(['CONFIRMATION'], $viewer))->toThrow(RuntimeException::class, 'cannot set up')
        ->and($viewer->can('viewAny', FeedbackTemplate::class))->toBeTrue()->and($viewer->can('create', FeedbackTemplate::class))->toBeFalse()
        ->and($employee->can('viewAny', QuestionBank::class))->toBeFalse()
        ->and($this->hr->can('create', QuestionBank::class))->toBeTrue()
        ->and(collect(config('peopleos.roles.tenant-hr-admin.permissions')))->toContain('feedback.*');
});

it('sets up question banks and adds library templates on their screens', function () {
    Livewire::test(ManageQuestionBanks::class)->assertOk()
        ->callAction('create', ['name' => 'Culture', 'code' => 'cult', 'module' => 'workforce', 'status' => 'active', 'bank_questions' => [
            ['title' => 'I feel valued here', 'type' => 'likert', 'is_mandatory' => true, 'status' => 'active'],
            ['title' => 'Favourite perk', 'type' => 'single_choice', 'options' => ['Lunch', 'Gym'], 'is_mandatory' => false, 'status' => 'active'],
        ]])->assertHasNoActionErrors();
    $bank = QuestionBank::query()->where('code', 'CULT')->firstOrFail();
    expect($bank->questions()->pluck('title')->all())->toBe(['I feel valued here', 'Favourite perk'])
        ->and($bank->questions()->get()[1]->options)->toBe([['value' => 'lunch', 'label' => 'Lunch'], ['value' => 'gym', 'label' => 'Gym']]);

    Livewire::test(ManageFeedbackTemplates::class)->assertOk()
        ->callAction('library', ['codes' => ['PROBATION_SELF']])->assertHasNoActionErrors();
    expect(FeedbackTemplate::query()->where('code', 'PROBATION_SELF')->value('process'))->toBe('probation');

    Livewire::test(ManageFeedbackTemplates::class)
        ->callAction('create', ['name' => 'Short exit', 'code' => 'short_exit', 'process' => 'exit_interview_hr', 'status' => 'active', 'questions' => [
            ['title' => 'Why are you leaving?', 'type' => 'text', 'mandatory' => true],
        ]])->assertHasNoActionErrors();
    expect(FeedbackTemplate::query()->where('code', 'SHORT_EXIT')->first()->questions[0]['title'])->toBe('Why are you leaving?');
});
