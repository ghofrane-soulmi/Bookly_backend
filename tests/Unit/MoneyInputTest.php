<?php

namespace Tests\Unit;

use App\Casts\MoneyCast;
use App\Support\Money\InvalidMoneyAmountException;
use App\Support\Money\MoneyInput;
use Brick\Money\Money;
use Modules\Tenant\Models\Business;
use Tests\TestCase;

class MoneyInputTest extends TestCase
{
    public function test_tnd_decimal_string_parses_to_correct_minor_units(): void
    {
        $money = MoneyInput::fromDecimalString('12.500', 'TND');

        $this->assertSame(12500, $money->getMinorAmount()->toInt());
    }

    public function test_tnd_minor_units_format_back_to_decimal_string(): void
    {
        $money = Money::ofMinor(12500, 'TND');

        $this->assertSame('12.500', (string) $money->getAmount());
    }

    /**
     * brick/money's default rounding mode (Unnecessary) throws rather than round
     * when input precision exceeds the currency's own decimals. We explicitly use
     * HalfUp instead, since real user input routinely has more typed precision
     * than the currency supports and should round, not error.
     */
    public function test_tnd_input_with_extra_precision_rounds_half_up(): void
    {
        $money = MoneyInput::fromDecimalString('12.3456', 'TND');

        $this->assertSame(12346, $money->getMinorAmount()->toInt());
    }

    public function test_eur_whole_number_input_converts_to_minor_units(): void
    {
        $money = MoneyInput::fromDecimalString('35', 'EUR');

        $this->assertSame(3500, $money->getMinorAmount()->toInt());
    }

    public function test_jpy_has_zero_decimals(): void
    {
        $money = MoneyInput::fromDecimalString('1250', 'JPY');

        $this->assertSame(1250, $money->getMinorAmount()->toInt());
    }

    public function test_non_numeric_input_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        MoneyInput::fromDecimalString('abc', 'USD');
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        MoneyInput::fromDecimalString('-5.00', 'USD');
    }

    public function test_unknown_currency_code_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        MoneyInput::fromDecimalString('10.00', 'XXX');
    }

    public function test_money_cast_reads_minor_units_using_sibling_currency_column(): void
    {
        $cast = new MoneyCast;
        $stub = new Business;

        $money = $cast->get($stub, 'price', 12500, ['currency_code' => 'TND']);

        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame('TND', $money->getCurrency()->getCurrencyCode());
        $this->assertSame(12500, $money->getMinorAmount()->toInt());
    }

    public function test_money_cast_writes_money_object_to_minor_units_and_currency_column(): void
    {
        $cast = new MoneyCast;
        $stub = new Business;

        $attributes = $cast->set($stub, 'price', Money::of('12.500', 'TND'), []);

        $this->assertSame(['price' => 12500, 'currency_code' => 'TND'], $attributes);
    }

    public function test_money_cast_returns_null_for_null_value(): void
    {
        $cast = new MoneyCast;
        $stub = new Business;

        $this->assertNull($cast->get($stub, 'price', null, ['currency_code' => 'USD']));
    }
}
