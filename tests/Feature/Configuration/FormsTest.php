<?php

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Services\Forms;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->forms = app(Forms::class);
    $this->form = Form::create(['name' => 'Travel request', 'key' => 'travel', 'requires_approval' => true]);
});

it('walks the form lifecycle: draft, publish, collect, validate, approve', function () {
    $draft = $this->forms->draft($this->form);
    expect($draft->version)->toBe(1)->and($draft->status)->toBe(VersionStatus::Draft);

    expect(fn () => $this->forms->publish($this->form))->toThrow(ConfigurationException::class, 'at least one field');

    $draft->update(['fields' => [
        ['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'required' => true],
        ['key' => 'mode', 'label' => 'Mode', 'type' => 'dropdown', 'required' => true, 'options' => ['air', 'rail']],
        ['key' => 'budget', 'label' => 'Budget', 'type' => 'number'],
    ]]);

    $published = $this->forms->publish($this->form, 'Go live');
    expect($published->status)->toBe(VersionStatus::Published)->and($published->published_by)->toBe(auth()->id());

    expect(fn () => $published->update(['fields' => []]))->toThrow(ConfigurationException::class, 'immutable');
    expect(fn () => $this->forms->submit($this->form, ['destination' => 'Pune']))->toThrow(ValidationException::class);
    expect(fn () => $this->forms->submit($this->form, ['destination' => 'Pune', 'mode' => 'bus']))->toThrow(ValidationException::class);

    $submission = $this->forms->submit($this->form, ['destination' => 'Pune', 'mode' => 'rail', 'budget' => '1500', 'ignored' => 'x']);
    expect($submission->status)->toBe('submitted')
        ->and($submission->data)->toBe(['destination' => 'Pune', 'mode' => 'rail', 'budget' => '1500'])
        ->and($submission->form_version_id)->toBe($published->id);

    $this->forms->review($submission, true, 'Approved by HR');
    expect($submission->fresh()->status)->toBe('approved')->and($submission->fresh()->reviewed_by)->toBe(auth()->id());
    expect(fn () => $this->forms->review($submission, false))->toThrow(ConfigurationException::class);
});

it('retires the previous version when a new draft is published and keeps submissions pinned', function () {
    $draft = $this->forms->draft($this->form);
    $draft->update(['fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]]);
    $v1 = $this->forms->publish($this->form);
    $submission = $this->forms->submit($this->form, ['a' => '1']);

    $v2 = $this->forms->draft($this->form);
    expect($v2->version)->toBe(2)->and($v2->fields)->toBe($v1->fields);
    $v2->update(['fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text'], ['key' => 'b', 'label' => 'B', 'type' => 'checkbox']]]);
    $this->forms->publish($this->form);

    expect($v1->fresh()->status)->toBe(VersionStatus::Retired)
        ->and($this->form->published()->first()->version)->toBe(2)
        ->and($submission->fresh()->version->version)->toBe(1);
});

it('auto-approves submissions for forms that do not need approval', function () {
    $this->form->update(['requires_approval' => false]);
    $this->forms->draft($this->form)->update(['fields' => [['key' => 'ok', 'label' => 'OK', 'type' => 'checkbox']]]);
    $this->forms->publish($this->form);

    expect($this->forms->submit($this->form, ['ok' => true])->status)->toBe('approved');
});
