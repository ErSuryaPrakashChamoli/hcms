<?php

use App\Support\Money\Currency;
use App\Support\Money\CurrencyMismatchException;
use App\Support\Money\Money;
use App\Support\Money\MoneyFormatter;
use Brick\Math\RoundingMode;

/*
| SaaS.7: money is an exact integer of minor units with an ISO-4217 currency. Precision comes from the controlled
| catalogue (0, 2 or 3 decimals); amounts never pass through floats; rounding happens once, with a named mode;
| display follows a locale without ever changing the stored currency.
*/

it('stores ISO-4217 codes with catalogue precision: 0, 2 and 3 decimals, never more', function () {
    expect(collect(['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'CHF', 'JPY'])->every(fn ($c) => Currency::tryFrom($c) !== null))->toBeTrue()
        ->and(Currency::JPY->minorUnits())->toBe(0)->and(Currency::BHD->minorUnits())->toBe(3)->and(Currency::INR->minorUnits())->toBe(2)
        ->and(Money::parse('1999.99', 'INR')->minor)->toBe(199999)
        ->and(Money::parse('1250', 'JPY')->minor)->toBe(1250)
        ->and(Money::parse('1.234', 'BHD')->minor)->toBe(1234)
        ->and(Money::parse('1.5', 'USD')->toDecimal())->toBe('1.50')
        ->and(Money::ofMinor(1234, 'KWD')->toDecimal())->toBe('1.234')
        ->and(Money::ofMinor(1250, 'JPY')->toDecimal())->toBe('1250');

    foreach (['1.999' => 'INR', '1.5' => 'JPY', '1.2345' => 'BHD', 'abc' => 'USD', '1e3' => 'USD', '0.1' => 'XYZ', '₹100' => 'INR'] as $amount => $currency) {
        expect(fn () => Money::parse($amount, $currency))->toThrow(InvalidArgumentException::class);
    }
});

it('adds exactly, never mixes currencies and never overflows silently', function () {
    // 0.1 + 0.2 is exactly 0.30, unlike floats; a thousand small amounts sum exactly.
    expect(Money::parse('0.1', 'USD')->plus(Money::parse('0.2', 'USD'))->toDecimal())->toBe('0.30')
        ->and(Money::sum('INR', array_fill(0, 1000, Money::parse('0.01', 'INR')))->toDecimal())->toBe('10.00')
        ->and(Money::parse('19.99', 'EUR')->times(3)->toDecimal())->toBe('59.97')
        ->and(fn () => Money::parse('1', 'INR')->plus(Money::parse('1', 'USD')))->toThrow(CurrencyMismatchException::class)
        ->and(fn () => Money::ofMinor(PHP_INT_MAX, 'USD')->plus(Money::ofMinor(1, 'USD')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::ofMinor(PHP_INT_MAX, 'USD')->times(2))->toThrow(InvalidArgumentException::class);
});

it('rounds a percentage once, to the minor unit, with the named mode, at boundaries', function () {
    $half = fn (string $amount, string $currency, string $rate, RoundingMode $mode) => Money::parse($amount, $currency)->percentage($rate, $mode)->minor;
    expect($half('0.50', 'INR', '9', RoundingMode::HalfUp))->toBe(5)          // 4.5 paise → 5
        ->and($half('0.50', 'INR', '9', RoundingMode::HalfEven))->toBe(4)    // 4.5 paise → 4 (banker's)
        ->and($half('0.05', 'INR', '9', RoundingMode::HalfUp))->toBe(0)      // 0.45 paise → 0
        ->and($half('1234.56', 'USD', '8.875', RoundingMode::HalfUp))->toBe(10957) // 109.5672 → 109.57
        ->and($half('1000', 'JPY', '10', RoundingMode::HalfUp))->toBe(100)
        ->and($half('333', 'JPY', '10', RoundingMode::HalfUp))->toBe(33)     // 33.3 yen → 33
        ->and($half('1.234', 'BHD', '10', RoundingMode::HalfUp))->toBe(123)  // 0.1234 → 0.123
        ->and($half('100.00', 'GBP', '0', RoundingMode::HalfUp))->toBe(0)
        ->and($half('0.01', 'USD', '100', RoundingMode::HalfUp))->toBe(1);
});

it('displays by locale and currency, never changing the stored code', function () {
    expect(MoneyFormatter::format(Money::parse('100000', 'INR'), 'en_IN'))->toBe('₹1,00,000.00')
        ->and(MoneyFormatter::format(Money::parse('1250', 'USD'), 'en_US'))->toBe('$1,250.00')
        ->and(MoneyFormatter::format(Money::parse('1250', 'EUR'), 'de_DE'))->toBe("1.250,00\u{a0}€")
        ->and(MoneyFormatter::format(Money::parse('1250', 'GBP'), 'en_GB'))->toBe('£1,250.00')
        ->and(MoneyFormatter::format(Money::parse('1250', 'JPY'), 'en_US'))->toBe('¥1,250')
        ->and(MoneyFormatter::format(Money::parse('1.234', 'BHD'), 'en'))->toContain('1.234')
        ->and(MoneyFormatter::format(Money::parse('9999999999999.99', 'USD'), 'en_US'))->toBe('$9,999,999,999,999.99')
        ->and(MoneyFormatter::format(Money::ofMinor(PHP_INT_MAX, 'USD'), 'en_US'))->toBe('USD 92233720368547758.07');
});
