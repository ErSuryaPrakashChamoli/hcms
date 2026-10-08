<?php

use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Services\Positions;

/** The company employees are hired into (activeEmployee uses the first company), attached to the tree, with one department. */
function workforceOrg(): array
{
    $company = Company::query()->first() ?? Company::factory()->create();
    $tree = app(OrganisationTree::class);
    $root = OrganisationNode::query()->where('nodeable_type', Company::class)->where('nodeable_id', $company->id)->first() ?? $tree->attach($company);
    $department = $tree->createUnit('department', ['name' => 'Engineering '.uniqid(), 'code' => 'ENG'.random_int(100, 999)], $root);

    return ['company' => $company, 'root' => $root, 'department' => $department];
}

/** A position created by $creator, proposed by $creator, approved by $approver and opened by $creator. */
function openPosition(array $attributes, User $creator, User $approver, ?string $from = null): Position
{
    $positions = app(Positions::class);
    $position = $positions->create(['effective_from' => $from ?? now()->toDateString(), ...$attributes], $creator);
    // Backdated positions (recording an existing organisation) are approved and opened on their own date.
    $positions->transition($position, 'proposed', null, $creator, $from);
    $positions->transition($position, 'approved', null, $approver, $from);
    $positions->transition($position, 'open', null, $creator, $from);

    return $position->refresh();
}
