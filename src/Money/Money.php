<?php
declare(strict_types=1);

namespace gCore\gNode\Money;

use gCore\gNode\Exception\MoneyException;

/**
 * Money - an exact decimal amount and its currency, and nothing else
 *
 * This class deliberately has no arithmetic. Addition, multiplication,
 * division, percentages and rounding all live in the gNode daemon's decimal
 * domain, reached through {@see MoneyClient}, because PHP cannot perform them
 * exactly: there is no bcmath or gmp on this estate, so every division is a
 * float, and a float cannot represent 0.01.
 *
 * What it does own is the shape of the value. A float or an int is refused
 * rather than converted — rounding a binary float makes a wrong value
 * plausible and moves the error from the call site to a settlement report.
 *
 * The number of decimals a currency actually has is NOT checked here. That
 * table lives in one place, the daemon, and duplicating it in PHP is how the
 * two copies drift.
 *
 * @package gCore\gNode\Money
 */
final class Money
{
    /** Shape gate only: one optional sign, digits, at most one point. */
    private const SHAPE = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/';

    /** @var string */
    private $amount;

    /** @var string */
    private $currency;

    private function __construct(string $amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    /**
     * @param string $amount   Exact decimal string, e.g. "19.99", "-5.50", "0"
     * @param string $currency 3-letter uppercase ISO 4217 code
     * @throws MoneyException
     * @api
     */
    public static function of(string $amount, string $currency): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new MoneyException(
                "currency must be a 3-letter uppercase ISO 4217 code, got " . var_export($currency, true)
            );
        }
        if (preg_match(self::SHAPE, $amount) !== 1) {
            throw new MoneyException(
                "amount must be an exact decimal string, got " . var_export($amount, true)
                . " — pass money as a string, never as a float, and without surrounding whitespace"
            );
        }
        return new self($amount, $currency);
    }

    /**
     * Integer minor units at a known exponent, for the one case where a
     * caller genuinely holds cents: "3399" at exponent 2 becomes "33.99".
     *
     * @throws MoneyException
     * @api
     */
    public static function ofMinor(int $minor, int $exponent, string $currency): self
    {
        if ($exponent < 0 || $exponent > 6) {
            throw new MoneyException("exponent must be between 0 and 6, got {$exponent}");
        }
        $sign = $minor < 0 ? '-' : '';
        $magnitude = (string) abs($minor);
        if ($exponent === 0) {
            return self::of($sign . $magnitude, $currency);
        }
        $magnitude = str_pad($magnitude, $exponent + 1, '0', STR_PAD_LEFT);
        $whole = substr($magnitude, 0, -$exponent);
        $fraction = substr($magnitude, -$exponent);
        return self::of($sign . $whole . '.' . $fraction, $currency);
    }

    /**
     * The exact decimal string, exactly as it will go on the wire
     *
     * @api
     */
    public function amount(): string
    {
        return $this->amount;
    }

    /**
     * The 3-letter uppercase ISO 4217 code
     *
     * @api
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * "19.99 EUR" — for logs and messages, never for the wire
     *
     * @api
     */
    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    /**
     * Same currency and same value, ignoring trailing zeros
     *
     * @api
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && self::normalise($this->amount) === self::normalise($other->amount);
    }

    /**
     * True when no digit is non-zero, so "-0.00" is zero
     *
     * @api
     */
    public function isZero(): bool
    {
        return preg_match('/[1-9]/', $this->amount) !== 1;
    }

    /**
     * True for a value below zero; negative zero is not negative
     *
     * @api
     */
    public function isNegative(): bool
    {
        return $this->amount[0] === '-' && !$this->isZero();
    }

    /**
     * One canonical spelling per value, so "19.9", "19.90" and "19.900" compare
     * equal and "-0.00" equals "0". A rewrite of the digits, not arithmetic:
     * no value is produced, so nothing can be rounded.
     */
    private static function normalise(string $value): string
    {
        $negative = $value[0] === '-';
        $parts = explode('.', ltrim($value, '-+'), 2);
        $whole = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');
        if ($whole === '' && $fraction === '') {
            return '0';
        }
        return ($negative ? '-' : '') . ($whole === '' ? '0' : $whole)
            . ($fraction === '' ? '' : '.' . $fraction);
    }
}
