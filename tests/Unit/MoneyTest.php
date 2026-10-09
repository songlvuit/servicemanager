<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_formats_vnd_with_dot_separators_and_symbol(): void
    {
        $this->assertSame('45.000.000 ₫', Money::format(45000000));
        $this->assertSame('100.000.000 ₫', Money::format('100000000.00'));
        $this->assertSame('0 ₫', Money::format(null));
    }

    public function test_it_can_format_without_symbol(): void
    {
        $this->assertSame('15.000.000', Money::format(15000000, withSymbol: false));
    }

    public function test_it_formats_negative_amounts(): void
    {
        $this->assertSame('-3.500.000 ₫', Money::format(-3500000));
    }

    public function test_it_shortens_large_amounts_for_dashboards(): void
    {
        $this->assertSame('1,5 tỷ', Money::short(1500000000));
        $this->assertSame('2 tỷ', Money::short(2000000000));
        $this->assertSame('45 tr', Money::short(45000000));
        $this->assertSame('999.999 ₫', Money::short(999999));
    }
}
