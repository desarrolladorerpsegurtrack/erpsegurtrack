<?php

namespace Tests\Unit;

use App\Support\ServicePeriod;
use PHPUnit\Framework\TestCase;

class ServicePeriodTest extends TestCase
{
    public function test_it_parses_a_dynamic_month_count(): void
    {
        $this->assertSame(1, ServicePeriod::months('Mensual'));
        $this->assertSame(5, ServicePeriod::months('5 Meses'));
        $this->assertSame(13, ServicePeriod::months('13 Meses'));
        $this->assertSame(29, ServicePeriod::months('29 Meses'));
        $this->assertSame(24, ServicePeriod::months('2 años'));
        $this->assertNull(ServicePeriod::months('No'));
    }

    public function test_it_formats_dynamic_month_counts(): void
    {
        $this->assertSame('Mensual', ServicePeriod::label(1));
        $this->assertSame('5 Meses', ServicePeriod::label(5));
        $this->assertSame('13 Meses', ServicePeriod::label('13'));
        $this->assertSame('', ServicePeriod::label('No'));
    }

    public function test_it_adds_months_without_overflowing_the_calendar_month(): void
    {
        $this->assertSame('2027-02-28', ServicePeriod::addToDate('2027-01-31', 1)->format('Y-m-d'));
        $this->assertSame('2028-02-29', ServicePeriod::addToDate('2027-01-31', 13)->format('Y-m-d'));
    }
}
