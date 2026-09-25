<?php

namespace App\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a bigint minor-units column to a Brick\Money\Money object, reading the
 * currency from a sibling column on the same row (default: currency_code).
 *
 * Usage: protected function casts(): array
 * {
 *     return ['price' => MoneyCast::class];
 *     // or, if the currency column has a different name:
 *     return ['price' => MoneyCast::class.':currency'];
 * }
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(protected string $currencyColumn = 'currency_code') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currencyCode = $attributes[$this->currencyColumn] ?? null;

        if ($currencyCode === null) {
            return null;
        }

        return Money::ofMinor((int) $value, $currencyCode);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Money) {
            return [
                $key => $value->getMinorAmount()->toInt(),
                $this->currencyColumn => $value->getCurrency()->getCurrencyCode(),
            ];
        }

        return [$key => (int) $value];
    }
}
