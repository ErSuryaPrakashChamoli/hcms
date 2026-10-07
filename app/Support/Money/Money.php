<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;

/**
 * SaaS.7: an exact amount of one currency, as an integer count of its minor units (paise, cents, fils; yen are
 * whole). Never a float. Arithmetic refuses mixed currencies and values outside the signed 64-bit range (BIGINT
 * columns); anything that needs a fraction (a rate) is computed with exact decimals and rounded once, with a
 * named rounding mode, to the currency's minor unit.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(public int $minor, public Currency $currency) {}

    public static function ofMinor(int $minor, Currency|string $currency): self
    {
        return new self($minor, Currency::of($currency));
    }

    public static function zero(Currency|string $currency): self
    {
        return new self(0, Currency::of($currency));
    }

    /** "1234.50" in the currency's major unit; refuses more decimals than the currency has (no silent rounding). */
    public static function parse(string $amount, Currency|string $currency): self
    {
        $currency = Currency::of($currency);
        $amount = trim($amount);
        if (preg_match('/^-?\d{1,16}(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException("{$amount} is not an amount (digits, optionally a decimal point).");
        }
        $decimal = BigDecimal::of($amount);
        if ($decimal->getScale() > $currency->minorUnits()) {
            throw new InvalidArgumentException("{$currency->value} has {$currency->minorUnits()} decimal places; {$amount} has more.");
        }

        return new self(self::fit($decimal->withPointMovedRight($currency->minorUnits())->toBigInteger()), $currency);
    }

    /** @param  iterable<Money>  $amounts */
    public static function sum(Currency|string $currency, iterable $amounts): self
    {
        $total = self::zero($currency);
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::fit(BigInteger::of($this->minor)->plus($other->minor)), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::fit(BigInteger::of($this->minor)->minus($other->minor)), $this->currency);
    }

    /** Exact multiplication by a whole quantity (no rounding is ever needed). */
    public function times(int $quantity): self
    {
        return new self(self::fit(BigInteger::of($this->minor)->multipliedBy($quantity)), $this->currency);
    }

    /** $rate per cent ("18", "8.875"), computed exactly and rounded once to the minor unit with $mode. */
    public function percentage(string $rate, RoundingMode $mode): self
    {
        try {
            $exact = BigDecimal::of($this->minor)->multipliedBy(BigDecimal::of($rate))->dividedBy(100, 10, RoundingMode::Unnecessary);
        } catch (MathException $e) {
            throw new InvalidArgumentException("{$rate} is not a usable rate: {$e->getMessage()}");
        }

        return new self(self::fit($exact->toScale(0, $mode)->toBigInteger()), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /** The amount in major units with exactly the currency's decimals: "1234.50", "1250" (JPY), "1.234" (BHD). */
    public function toDecimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->minor, $this->currency->minorUnits());
    }

    /** @return array{amount_minor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->minor, 'currency' => $this->currency->value];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException("Cannot combine {$this->currency->value} and {$other->currency->value}: amounts are never converted implicitly.");
        }
    }

    private static function fit(BigInteger $value): int
    {
        if ($value->isGreaterThan(PHP_INT_MAX) || $value->isLessThan(PHP_INT_MIN)) {
            throw new InvalidArgumentException('The amount is outside the range PeopleOS stores (signed 64-bit minor units).');
        }

        return $value->toInt();
    }
}
