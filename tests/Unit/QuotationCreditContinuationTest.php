<?php

namespace Tests\Unit;

use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CotizacionService;
use App\Services\CuentasPorCobrarService;
use App\Services\CxcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class QuotationCreditContinuationTest extends TestCase
{
    public function test_quote_continuation_uses_pending_credit_installment_when_quote_method_is_cash(): void
    {
        config(['locks.enabled' => false]);
        $this->createSchema();

        DB::table('formapago')->insert([
            ['idformaPago' => 1, 'detalle' => 'Contado'],
            ['idformaPago' => 2, 'detalle' => 'Crédito'],
        ]);
        DB::table('entidadbancaria')->insert(['identidadBancaria' => 1]);
        DB::table('moneda')->insert(['idmoneda' => 1]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT-CONTINUATION',
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 1,
            'formaPago_idformaPago' => 1,
            'total' => 600,
        ]);
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 1,
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:CT-CONTINUATION',
            'montoOriginal' => 600,
            'montoActual' => 400,
            'estado' => 'Pendiente Pago parcial',
        ]);
        DB::table('detallecxc')->insert([
            'iddetalleCXC' => 1,
            'formaPago_idformaPago' => 2,
            'cuentasPorCobrar_idcuentasPorCobrar' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'Cuota programada 2 - Monto: 200.00',
            'fechaPagoProgramada' => '2026-11-30',
            'nCuota' => 2,
            'estado' => '0',
        ]);

        $submittedPayment = null;
        $cxcService = Mockery::mock(CxcService::class);
        $cxcService->shouldReceive('procesarPagoGrupo')
            ->once()
            ->withArgs(function (array $cxcIds, array $paymentData, $file) use (&$submittedPayment): bool {
                $submittedPayment = $paymentData;

                return $cxcIds === [1] && $file === null;
            })
            ->andReturn([]);

        $request = Request::create('/quotation/CT-CONTINUATION', 'POST', [
            'fecha_pago' => '2026-10-30',
            'docReferencia' => 'F001-001',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 200,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
        ], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);

        $response = (new CuentasPorCobrarController(
            $cxcService,
            new CuentasPorCobrarService(),
            new CotizacionService()
        ))->approveQuotation($request, 'CT-CONTINUATION');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'Pago registrado correctamente. Se pagó la cuota 2 de la cotización CT-CONTINUATION.',
            $response->getData(true)['message']
        );
        $this->assertSame(2, (int) $submittedPayment['formaPago_idformaPago']);
        $this->assertSame(2, (int) $submittedPayment['cuota_cotizacion_numero']);
        $this->assertSame('2026-11-30', $submittedPayment['cuota_cotizacion_fecha']);
    }

    private function createSchema(): void
    {
        Schema::create('formapago', function (Blueprint $table): void {
            $table->increments('idformaPago');
            $table->string('detalle');
        });
        Schema::create('entidadbancaria', function (Blueprint $table): void {
            $table->increments('identidadBancaria');
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
        });
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('estado');
            $table->string('cliente_idcliente');
            $table->integer('moneda_idmoneda');
            $table->integer('formaPago_idformaPago');
            $table->decimal('total', 20, 2);
            $table->string('docReferencia')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion');
            $table->decimal('montoOriginal', 20, 2);
            $table->decimal('montoActual', 20, 2);
            $table->string('estado');
            $table->dateTime('fechaCancelacion')->nullable();
            $table->string('docReferencia')->nullable();
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('formaPago_idformaPago');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion')->nullable();
            $table->date('fechaPagoProgramada')->nullable();
            $table->integer('nCuota')->nullable();
            $table->string('estado');
        });
        Schema::create('detallecotizacion', function (Blueprint $table): void {
            $table->string('cotizacion_nroCotizacion');
            $table->integer('almacen_idalmacen');
        });
        Schema::create('almacen', function (Blueprint $table): void {
            $table->increments('idalmacen');
            $table->integer('tipoElemento_idtipoElemento');
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->increments('idtipoElemento');
            $table->string('nombre');
        });
    }
}
