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
    public function test_partial_quotation_payment_keeps_withholding_pending_without_confirmation(): void
    {
        config(['locks.enabled' => false]);
        $this->createSchema();

        DB::table('formapago')->insert(['idformaPago' => 1, 'detalle' => 'Contado']);
        DB::table('entidadbancaria')->insert(['identidadBancaria' => 1]);
        DB::table('moneda')->insert(['idmoneda' => 1]);
        DB::table('tipoelemento')->insert(['idtipoElemento' => 1, 'nombre' => 'PLANES']);
        DB::table('almacen')->insert([
            'idalmacen' => 1,
            'tipoElemento_idtipoElemento' => 1,
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT-PARTIAL-WITHHOLDING',
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 1,
            'formaPago_idformaPago' => 1,
            'total' => 1000,
        ]);
        DB::table('detallecotizacion')->insert([
            'cotizacion_nroCotizacion' => 'CT-PARTIAL-WITHHOLDING',
            'almacen_idalmacen' => 1,
        ]);
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 1,
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:CT-PARTIAL-WITHHOLDING',
            'montoOriginal' => 1000,
            'montoActual' => 1000,
            'estado' => 'NUEVO',
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

        $request = Request::create('/quotation/CT-PARTIAL-WITHHOLDING', 'POST', [
            'fecha_pago' => '2026-11-03',
            'docReferencia' => 'F001-550',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 550,
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
        ))->approveQuotation($request, 'CT-PARTIAL-WITHHOLDING');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Pago registrado.', $response->getData(true)['message']);
        $this->assertSame('partial_pending', $submittedPayment['withholding_decision']);
        $this->assertSame('parcial', $submittedPayment['modo_pago']);
        $this->assertSame(550.0, (float) $submittedPayment['montoPago']);
    }

    public function test_partial_quotation_withholding_stays_pending_until_remaining_cash_is_paid(): void
    {
        config(['locks.enabled' => false]);
        $this->createSchema();

        DB::table('cliente')->insert([
            'idcliente' => 'CLIENTE-1',
            'detraccion' => '0',
            'razonSocial' => 'Cliente de prueba',
        ]);
        DB::table('formapago')->insert(['idformaPago' => 1, 'detalle' => 'Contado']);
        DB::table('entidadbancaria')->insert([
            ['identidadBancaria' => 1, 'razonSocial' => 'Banco'],
            ['identidadBancaria' => 2, 'razonSocial' => 'Banco de la Nacion'],
        ]);
        DB::table('moneda')->insert([
            'idmoneda' => 1,
            'detalle' => 'Sol',
            'simbolo' => 'S/',
        ]);
        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:CT-PARTIAL-WITHHOLDING',
            'montoOriginal' => 1380,
            'montoActual' => 1380,
            'estado' => 'NUEVO',
        ]);
        $cxcService = new CxcService();

        $cxcService->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 1000,
            'fechaPago' => '2026-11-03',
            'docReferencia' => 'F001-1000',
            'descripcion' => 'Primer pago parcial de cotización',
            'renewal_cxc_id' => $cxcId,
            'aplicar_deduccion' => true,
            'withholding_decision' => 'partial_pending',
            'origen_pago' => 'cotizacion',
            'is_retention_por_tipo_servicio' => false,
        ]);

        $this->assertSame(380.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('montoActual'));
        $this->assertSame('Pendiente Pago parcial', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));
        $this->assertSame(
            'Detracción pendiente: 166.00',
            DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                ->where('estado', '0')
                ->value('descripcion')
        );

        $cxcService->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 100,
            'fechaPago' => '2026-11-04',
            'docReferencia' => 'F001-650',
            'descripcion' => 'Segundo pago parcial de cotización',
            'renewal_cxc_id' => $cxcId,
            'aplicar_deduccion' => false,
            'origen_pago' => 'cotizacion',
            'is_retention_por_tipo_servicio' => false,
        ]);
        $this->assertSame(280.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('montoActual'));
        $this->assertSame('Pendiente Pago parcial', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));

        $cxcService->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 114,
            'fechaPago' => '2026-11-05',
            'docReferencia' => 'F001-880',
            'descripcion' => 'Pago restante del efectivo de cotización',
            'renewal_cxc_id' => $cxcId,
            'aplicar_deduccion' => false,
            'origen_pago' => 'cotizacion',
            'is_retention_por_tipo_servicio' => false,
        ]);

        $this->assertSame(166.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('montoActual'));
        $this->assertSame('Pendiente a Detracción', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));
        $this->assertSame(1, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
            ->where('estado', '0')
            ->where('descripcion', 'like', 'Detracción pendiente:%')
            ->count());

        $cxcService->registrarDetraccionPendiente($cxcId, null, null, '', '2026-11-06');

        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('montoActual'));
        $this->assertSame('3', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));
        $this->assertSame(1, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
            ->where('estado', '1')
            ->where('descripcion', 'Detracción aplicada')
            ->count());
    }

    public function test_quote_continuation_selects_latest_exact_receivable_consistently(): void
    {
        config(['locks.enabled' => false]);
        $this->createSchema();

        DB::table('formapago')->insert(['idformaPago' => 1, 'detalle' => 'Contado']);
        DB::table('entidadbancaria')->insert(['identidadBancaria' => 1]);
        DB::table('moneda')->insert(['idmoneda' => 1]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT-DUPLICATE-CXC',
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 1,
            'formaPago_idformaPago' => 1,
            'total' => 1000,
        ]);
        DB::table('cuentasporcobrar')->insert([
            [
                'idcuentasPorCobrar' => 1,
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'descripcion' => 'COTIZACION:CT-DUPLICATE-CXC',
                'montoOriginal' => 1000,
                'montoActual' => 280,
                'estado' => 'Pendiente Pago parcial',
            ],
            [
                'idcuentasPorCobrar' => 2,
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'descripcion' => 'COTIZACION:CT-DUPLICATE-CXC',
                'montoOriginal' => 1000,
                'montoActual' => 1000,
                'estado' => 'NUEVO',
            ],
        ]);

        $cxcService = Mockery::mock(CxcService::class);
        $cxcService->shouldReceive('procesarPagoGrupo')
            ->once()
            ->withArgs(fn (array $cxcIds): bool => $cxcIds === [2])
            ->andReturn([]);
        $request = Request::create('/quotation/CT-DUPLICATE-CXC', 'POST', [
            'fecha_pago' => '2026-11-03',
            'docReferencia' => 'F001-100',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 100,
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
        ))->approveQuotation($request, 'CT-DUPLICATE-CXC');

        $this->assertSame(200, $response->getStatusCode());
    }

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
            $table->string('razonSocial')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle')->nullable();
            $table->string('simbolo')->nullable();
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('detraccion')->nullable();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->integer('entidadBancaria_identidadBancaria');
            $table->dateTime('fechaRegistro');
            $table->string('informacion', 100);
            $table->decimal('monto', 20, 2);
            $table->string('tipoMov', 1);
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
            $table->integer('canCuotas')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
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
            $table->dateTime('fechaPago')->nullable();
            $table->integer('nCuota')->nullable();
            $table->integer('bancos_idbancos')->nullable();
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
