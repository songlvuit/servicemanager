<?php

namespace Tests\Unit;

use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_it_multiplies_and_rounds_half_up(): void
    {
        $this->assertSame('3750000.00', Decimal::mul('2.5', '1500000'));
        $this->assertSame('0.34', Decimal::mul('0.335', '1'));
        $this->assertSame('-0.34', Decimal::mul('-0.335', '1'));
    }

    public function test_it_adds_and_subtracts_without_float_errors(): void
    {
        $this->assertSame('0.30', Decimal::add('0.10', '0.20'));
        $this->assertSame('99.99', Decimal::sub('100.00', '0.01'));
    }

    public function test_it_calculates_percentages(): void
    {
        $this->assertSame('100000.00', Decimal::percentOf('1000000', '10'));
        $this->assertSame('26.67', Decimal::percentOf('333.33', '8'));
        $this->assertSame('25.00', Decimal::ratio('20', '80'));
    }

    public function test_division_by_zero_returns_zero(): void
    {
        $this->assertSame('0.00', Decimal::div('5', '0'));
        $this->assertSame('0.00', Decimal::ratio('5', '0'));
    }

    public function test_blank_and_invalid_values_count_as_zero(): void
    {
        $this->assertSame('0', Decimal::add('', null));
        $this->assertSame('1.5', Decimal::add('abc', '1.5'));
        $this->assertTrue(Decimal::isZero('0.00'));
        $this->assertFalse(Decimal::isPositive('-1'));
    }

    public function test_it_handles_the_largest_money_value(): void
    {
        $this->assertSame('9999999999999.99', Decimal::mul('9999999999999.99', '1'));
    }

    public function test_it_trims_trailing_zeros_for_inputs(): void
    {
        $this->assertSame('1500000', Decimal::trimZeros('1500000.00'));
        $this->assertSame('2.5', Decimal::trimZeros('2.50'));
        $this->assertSame('0', Decimal::trimZeros('0.00'));
        $this->assertNull(Decimal::trimZeros(null));
    }

    public function test_it_compares_values(): void
    {
        $this->assertSame(0, Decimal::compare('20.00', '20'));
        $this->assertSame(-1, Decimal::compare('19.99', 20));
        $this->assertSame(1, Decimal::compare('20.01', 20));
    }
}
