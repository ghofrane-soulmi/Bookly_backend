<?php

namespace App\Casts;

use Brick\Money\Money;

/**
 * Reshapes any attribute cast via MoneyCast from Brick\Money\Money (which
 * Eloquent's default array/JSON conversion doesn't know how to serialize)
 * into the API's {amount, currency} contract. Models using this should also
 * hide their underlying currency_code column via #[Hidden(...)], since it's
 * now represented inside each money field instead.
 */
trait SerializesMoneyAttributes
{
    /**
     * Attribute names cast via MoneyCast.
     *
     * @return list<string>
     */
    abstract protected function moneyAttributes(): array;

    public function toArray()
    {
        $array = parent::toArray();

        foreach ($this->moneyAttributes() as $attribute) {
            if ($this->{$attribute} instanceof Money) {
                $array[$attribute] = [
                    'amount' => $this->{$attribute}->getMinorAmount()->toInt(),
                    'currency' => $this->{$attribute}->getCurrency()->getCurrencyCode(),
                ];
            }
        }

        return $array;
    }
}
