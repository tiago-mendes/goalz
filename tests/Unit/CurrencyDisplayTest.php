<?php

namespace Tests\Unit;

use App\Support\CurrencyDisplay;
use Tests\TestCase;

class CurrencyDisplayTest extends TestCase
{
    public function test_brl_is_rendered_as_the_display_symbol_without_changing_numeric_formatting(): void
    {
        $this->assertSame('R$ 1500.00', CurrencyDisplay::format('BRL', '1500.00'));
        $this->assertSame('R$ 0.00', CurrencyDisplay::format('BRL', '0.00'));
        $this->assertSame('-R$ 120.00', CurrencyDisplay::format('BRL', '-120.00'));
    }

    public function test_non_brl_currency_codes_remain_visible_as_their_code(): void
    {
        $this->assertSame('USD 12.34', CurrencyDisplay::format('USD', '12.34'));
    }
}
