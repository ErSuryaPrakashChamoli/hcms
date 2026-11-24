<?php

use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Exceptions\PositionUnavailableException;
use App\Domain\Organisation\Models\Company;
use App\Domain\Workforce\Models\Position;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Workforce/WorkforceTestHelpers.php';

/*
| Master-relationships R1: a position (seat) belongs to one company. A person is placed only in a seat of the company
| they are placed in, and a move to another company is always stated, never implied by picking a seat.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->planner = tenantUser($this->tenant, ['workforce.view', 'workforce.manage']);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve']);
    $this->employee = activeEmployee();                          // works for the first company (Markedge)
    $this->org = workforceOrg();
    $this->markedge = $this->org['company'];
    $this->fynnedge = Company::factory()->create(['name' => 'FynnEdge', 'code' => 'FYN']);
    $this->ownSeat = openPosition(['code' => 'ME-ENG-1', 'title' => 'Engineer', 'organisation_node_id' => $this->org['department']->id], $this->planner, $this->approver, '2026-10-01');
    $this->otherSeat = openPosition(['code' => 'FY-ANL-1', 'title' => 'Analyst', 'company_id' => $this->fynnedge->id], $this->planner, $this->approver, '2026-10-01');
    $this->assign = fn (array $attributes, string $from = '2026-10-05') => app(AssignPositionAction::class)->handle($this->employee, $attributes, 'transfer', $from, 'Seat');
});

it('places a person in a seat of their own company', function () {
    $row = ($this->assign)(['position_id' => $this->ownSeat->id]);

    expect($row->position_id)->toBe($this->ownSeat->id)->and($row->company_id)->toBe($this->markedge->id);
});

it('refuses a seat of another company when the move to that company is not stated', function () {
    expect(fn () => ($this->assign)(['position_id' => $this->otherSeat->id]))
        ->toThrow(PositionUnavailableException::class, 'Position FY-ANL-1 belongs to FynnEdge, but this person works for '.$this->markedge->name)
        ->and($this->employee->positions()->count())->toBe(1);
});

it('refuses a seat of one company with another company chosen', function () {
    expect(fn () => ($this->assign)(['position_id' => $this->otherSeat->id, 'company_id' => $this->markedge->id]))
        ->toThrow(PositionUnavailableException::class, 'Position FY-ANL-1 belongs to FynnEdge, not '.$this->markedge->name);
});

it('accepts a seat of another company when the move to that company is stated', function () {
    $row = ($this->assign)(['position_id' => $this->otherSeat->id, 'company_id' => $this->fynnedge->id]);

    expect($row->company_id)->toBe($this->fynnedge->id)->and($row->position_id)->toBe($this->otherSeat->id);
});

it('refuses a move to another company that would carry the old company\'s seat along, and accepts it with the seat vacated', function () {
    ($this->assign)(['position_id' => $this->ownSeat->id]);

    expect(fn () => ($this->assign)(['company_id' => $this->fynnedge->id], '2026-11-01'))
        ->toThrow(PositionUnavailableException::class, 'holds position ME-ENG-1 of '.$this->markedge->name);

    $row = ($this->assign)(['company_id' => $this->fynnedge->id, 'vacate_position' => true], '2026-11-01');
    expect($row->company_id)->toBe($this->fynnedge->id)->and($row->position_id)->toBeNull();
});

it('keeps an ordinary change inside the company working with the seat carried forward', function () {
    ($this->assign)(['position_id' => $this->ownSeat->id]);
    $row = ($this->assign)(['company_id' => $this->markedge->id], '2026-11-01');

    expect($row->position_id)->toBe($this->ownSeat->id)->and(Position::query()->count())->toBe(2);
});
