<?php

use App\Domain\Configuration\Services\RuleEngine;

$engine = fn () => app(RuleEngine::class);

it('evaluates every operator', function () use ($engine) {
    $ctx = ['department_id' => 5, 'tenure_months' => 18, 'gender' => 'female', 'team_id' => null, 'tags' => []];

    expect($engine()->evaluate(['field' => 'department_id', 'operator' => 'equals', 'value' => '5'], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'department_id', 'operator' => 'not_equals', 'value' => 5], $ctx))->toBeFalse()
        ->and($engine()->evaluate(['field' => 'department_id', 'operator' => 'in', 'value' => ['4', '5']], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'department_id', 'operator' => 'not_in', 'value' => [4, 5]], $ctx))->toBeFalse()
        ->and($engine()->evaluate(['field' => 'tenure_months', 'operator' => 'greater_than', 'value' => 12], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'tenure_months', 'operator' => 'less_than', 'value' => 12], $ctx))->toBeFalse()
        ->and($engine()->evaluate(['field' => 'tenure_months', 'operator' => 'gte', 'value' => 18], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'tenure_months', 'operator' => 'lte', 'value' => 17], $ctx))->toBeFalse()
        ->and($engine()->evaluate(['field' => 'team_id', 'operator' => 'is_empty'], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'tags', 'operator' => 'is_empty'], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'gender', 'operator' => 'is_not_empty'], $ctx))->toBeTrue()
        ->and($engine()->evaluate(['field' => 'gender', 'operator' => 'bogus'], $ctx))->toBeFalse()
        ->and($engine()->evaluate(['field' => 'missing', 'operator' => 'equals', 'value' => 1], $ctx))->toBeFalse();
});

it('combines conditions with all or any', function () use ($engine) {
    $ctx = ['department_id' => 5, 'tenure_months' => 3];
    $conditions = [
        ['field' => 'department_id', 'operator' => 'equals', 'value' => 5],
        ['field' => 'tenure_months', 'operator' => 'gte', 'value' => 12],
    ];

    expect($engine()->matches($conditions, $ctx, 'all'))->toBeFalse()
        ->and($engine()->matches($conditions, $ctx, 'any'))->toBeTrue()
        ->and($engine()->matches([], $ctx))->toBeTrue();
});
