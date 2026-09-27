<?php

use App\Domain\Payroll\Services\FormulaEngine;

beforeEach(fn () => $this->engine = new FormulaEngine);

it('evaluates arithmetic, precedence, unary minus and functions', function () {
    expect($this->engine->evaluate('2 + 3 * 4'))->toBe(14.0)
        ->and($this->engine->evaluate('(2 + 3) * 4'))->toBe(20.0)
        ->and($this->engine->evaluate('-basic * 0.5', ['basic' => 10]))->toBe(-5.0)
        ->and($this->engine->evaluate('min(basic, 15000) * 0.12', ['basic' => 40000]))->toBe(1800.0)
        ->and($this->engine->evaluate('max(0, ctc_monthly - basic - hra)', ['ctc_monthly' => 100, 'basic' => 40, 'hra' => 80]))->toBe(0.0)
        ->and($this->engine->evaluate('round(10 / 3, 2)'))->toBe(3.33)
        ->and($this->engine->evaluate('if(basic > 15000, 1800, basic * 0.12)', ['basic' => 12000]))->toBe(1440.0)
        ->and($this->engine->evaluate('if(a >= 1 && b == 2, 1, 0)', ['a' => 1, 'b' => 2]))->toBe(1.0)
        ->and($this->engine->evaluate('10 % 4'))->toBe(2.0);
});

it('resolves variables lazily through a closure', function () {
    $value = $this->engine->evaluate('basic + hra', fn (string $name) => match ($name) {
        'basic' => 100, 'hra' => 50, default => null
    });

    expect($value)->toBe(150.0)->and($this->engine->variablesIn('basic * 0.5 + max(0, hra)'))->toBe(['basic', 'hra']);
});

it('rejects unknown variables, division by zero, bad syntax and anything that is not arithmetic', function () {
    expect(fn () => $this->engine->evaluate('basic * 2', []))->toThrow(RuntimeException::class, "Unknown variable 'basic'")
        ->and(fn () => $this->engine->evaluate('1 / 0'))->toThrow(RuntimeException::class, 'Division by zero')
        ->and(fn () => $this->engine->evaluate('(1 + 2'))->toThrow(RuntimeException::class, 'Unbalanced')
        ->and(fn () => $this->engine->evaluate('1 + 2)'))->toThrow(RuntimeException::class, 'Unbalanced')
        ->and(fn () => $this->engine->evaluate('exec("ls")'))->toThrow(RuntimeException::class)
        ->and(fn () => $this->engine->evaluate('basic; drop', ['basic' => 1]))->toThrow(RuntimeException::class, "Unexpected character ';'")
        ->and(fn () => $this->engine->evaluate(''))->toThrow(RuntimeException::class, 'empty');
});
