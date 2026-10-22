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

it('compares text ignoring case and picks values with in, lookup and slab', function () {
    $vars = ['grade' => 'm2', 'work_city' => ' Mumbai ', 'ctc_monthly' => 40000, 'basic' => 20000];

    expect($this->engine->evaluate('if(grade == "M2", 1, 0)', $vars))->toBe(1.0)
        ->and($this->engine->evaluate("if(grade != 'M2', 1, 0)", $vars))->toBe(0.0)
        ->and($this->engine->evaluate('basic * if(in(work_city, "Mumbai", "Delhi"), 0.50, 0.40)', $vars))->toBe(10000.0)
        ->and($this->engine->evaluate('lookup(grade, "M1", 3000, "M2", 5000, 1000)', $vars))->toBe(5000.0)
        ->and($this->engine->evaluate('lookup(grade, "G1", 3000, 1000)', $vars))->toBe(1000.0)
        ->and($this->engine->evaluate('slab(ctc_monthly, 25000, 1000, 50000, 2000, 3500)', $vars))->toBe(2000.0)
        ->and($this->engine->evaluate('slab(ctc_monthly, 25000, 1000, 30000, 2000, 3500)', $vars))->toBe(3500.0)
        ->and($this->engine->evaluate('if(in(grade, "5", 7), 1, 0)', ['grade' => '7']))->toBe(1.0)
        ->and(fn () => $this->engine->evaluate('lookup(grade, "G1", 3000)', $vars))->toThrow(RuntimeException::class, 'no entry for "m2"')
        ->and(fn () => $this->engine->evaluate('slab(ctc_monthly, 25000, 1000)', $vars))->toThrow(RuntimeException::class, 'no band for 40000');
});

it('rounds to steps, clamps and tests ranges', function () {
    expect($this->engine->evaluate('round_to(1234, 50)'))->toBe(1250.0)
        ->and($this->engine->evaluate('ceil_to(1201, 100)'))->toBe(1300.0)
        ->and($this->engine->evaluate('ceil_to(1200, 100)'))->toBe(1200.0)
        ->and($this->engine->evaluate('floor_to(1299, 100)'))->toBe(1200.0)
        ->and($this->engine->evaluate('round(12345, -2)'))->toBe(12300.0)
        ->and($this->engine->evaluate('clamp(5000, 15000, 100000)'))->toBe(15000.0)
        ->and($this->engine->evaluate('between(3, 3, 5) + between(6, 3, 5)'))->toBe(1.0)
        ->and(fn () => $this->engine->evaluate('round_to(10, 0)'))->toThrow(RuntimeException::class, 'step above zero');
});

it('fails a division by zero only when its result is used', function () {
    expect($this->engine->evaluate('if(paid_days > 0, 3000 / paid_days, 0)', ['paid_days' => 0]))->toBe(0.0)
        ->and($this->engine->evaluate('if(paid_days > 0 && 3000 / paid_days > 50, 1, 2)', ['paid_days' => 0]))->toBe(2.0)
        ->and(fn () => $this->engine->evaluate('max(0, 3000 / paid_days)', ['paid_days' => 0]))->toThrow(RuntimeException::class, 'Division by zero')
        ->and(fn () => $this->engine->evaluate('if(3000 / paid_days > 1, 1, 0)', ['paid_days' => 0]))->toThrow(RuntimeException::class, 'Division by zero');
});

it('explains misuse of text, functions and argument counts', function () {
    expect(fn () => $this->engine->evaluate('grade * 2', ['grade' => 'M2']))->toThrow(RuntimeException::class, 'cannot be used as a number')
        ->and(fn () => $this->engine->evaluate('if(grade == 2, 1, 0)', ['grade' => 'M2']))->toThrow(RuntimeException::class, 'Cannot compare text "M2"')
        ->and(fn () => $this->engine->evaluate('"M2"'))->toThrow(RuntimeException::class, 'not text')
        ->and(fn () => $this->engine->validate('round_to(10)'))->toThrow(RuntimeException::class, 'round_to() takes 2 arguments, not 1')
        ->and(fn () => $this->engine->validate('vlookup(grade, 1)'))->toThrow(RuntimeException::class, "Unknown function 'vlookup'")
        ->and(fn () => $this->engine->validate('min + 1'))->toThrow(RuntimeException::class, 'min is a function')
        ->and(fn () => $this->engine->validate('grade == "M2'))->toThrow(RuntimeException::class, 'Text is not closed')
        ->and($this->engine->variablesIn('lookup(grade, "basic", 1, hra)'))->toBe(['grade', 'hra']);
});

it('explains a percent sign and other missing values or operators', function () {
    expect(fn () => $this->engine->validate('basic*100%'))->toThrow(RuntimeException::class, "'%' is the remainder operator, not percent")
        ->and(fn () => $this->engine->validate('ctc_monthly * 40% + 100'))->toThrow(RuntimeException::class, 'not percent')
        ->and(fn () => $this->engine->validate('basic * '))->toThrow(RuntimeException::class, 'ends with an operator')
        ->and(fn () => $this->engine->validate('basic hra'))->toThrow(RuntimeException::class, "An operator is missing before 'hra'")
        ->and(fn () => $this->engine->validate('max(basic, )'))->toThrow(RuntimeException::class, "A value is missing before ')'")
        ->and(fn () => $this->engine->validate('* basic'))->toThrow(RuntimeException::class, "'*' needs a value before it")
        ->and($this->engine->evaluate('-basic + -(2) * 10 % 3', ['basic' => 1]))->toBe(-3.0);
});
