<?php

namespace Tests\Unit;

use App\Services\CxcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CxcCurrencyConversionTest extends TestCase
{
    public function test_conversion_direction_uses_the_payment_currency_instead_of_assuming_dollars(): void
    {
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle');
            $table->string('simbolo');
        });

        DB::table('moneda')->insert([
            ['idmoneda' => 1, 'detalle' => 'Sol', 'simbolo' => 'S/'],
            ['idmoneda' => 2, 'detalle' => 'Dólar', 'simbolo' => '$'],
            ['idmoneda' => 3, 'detalle' => 'Euro', 'simbolo' => '€'],
        ]);

        $this->assertSame(100.0, CxcService::convertPaymentAmountToDebtCurrency(345, 3.45, 3, 1));
        $this->assertSame(345.0, CxcService::convertPaymentAmountToDebtCurrency(100, 3.45, 1, 3));
        $this->assertSame(100.0, CxcService::convertPaymentAmountToDebtCurrency(345, 3.45, 2, 1));
        $this->assertSame(345.0, CxcService::convertPaymentAmountToDebtCurrency(100, 3.45, 3, 2));
    }
}
