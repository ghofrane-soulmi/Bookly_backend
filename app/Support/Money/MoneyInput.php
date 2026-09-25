<?php

namespace App\Support\Money;

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;

/**
 * Parses user-supplied decimal-string amounts (e.g. "12.50") into Money
 * objects. This is the one place that decides how "too many decimal places"
 * is handled — rounds to the currency's own precision (half up) rather than
 * rejecting the input, since real users routinely type more precision than
 * a currency supports.
 */
class MoneyInput
{
    /**
     * @throws InvalidMoneyAmountException
     */
    public static function fromDecimalString(string $amount, string $currencyCode): Money
    {
        try {
            $money = Money::of($amount, $currencyCode, roundingMode: RoundingMode::HalfUp);
        } catch (UnknownCurrencyException|MathException $e) {
            throw new InvalidMoneyAmountException(
                "Invalid amount \"{$amount}\" for currency \"{$currencyCode}\".",
                previous: $e,
            );
        }

        if ($money->isNegative()) {
            throw new InvalidMoneyAmountException("Amount \"{$amount}\" must not be negative.");
        }

        return $money;
    }
}
