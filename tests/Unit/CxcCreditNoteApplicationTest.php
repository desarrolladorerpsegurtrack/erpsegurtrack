<?php

namespace Tests\Unit;

use App\Services\CxcService;
use App\Services\CuentasPorCobrarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CxcCreditNoteApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente', 20)->primary();
            $table->string('detraccion', 1)->default('0');
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente', 20);
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion', 100);
            $table->decimal('montoOriginal', 20, 2);
            $table->decimal('montoActual', 20, 2);
            $table->integer('canCuotas')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->dateTime('fechaCancelacion')->nullable();
            $table->string('docReferencia')->nullable();
            $table->string('docfactura')->nullable();
            $table->string('estado', 50);
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle');
            $table->string('simbolo');
        });
        Schema::create('entidadbancaria', function (Blueprint $table): void {
            $table->increments('identidadBancaria');
            $table->string('razonSocial')->nullable();
        });
        Schema::create('formapago', function (Blueprint $table): void {
            $table->increments('idformaPago');
            $table->string('detalle');
            $table->integer('tiempo')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->integer('entidadBancaria_identidadBancaria');
            $table->dateTime('fechaRegistro');
            $table->string('informacion', 100);
            $table->decimal('monto', 20, 2);
            $table->string('tipoMov', 1);
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('formaPago_idformaPago');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion', 100)->nullable();
            $table->dateTime('fechaPagoProgramada')->nullable();
            $table->dateTime('fechaPago')->nullable();
            $table->integer('nCuota')->nullable();
            $table->integer('bancos_idbancos')->nullable();
            $table->string('estado', 1)->nullable();
        });
        Schema::create('evidenciapago', function (Blueprint $table): void {
            $table->increments('idevidenciaPago');
            $table->dateTime('fechaCarga');
            $table->string('archivo');
            $table->integer('bancos_idbancos');
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente', 20);
            $table->string('docReferencia')->nullable();
        });
        Schema::create('nota_credito', function (Blueprint $table): void {
            $table->increments('idnotacredito');
            $table->string('cliente_idcliente', 20);
            $table->integer('moneda_idmoneda');
            $table->string('docReferencia', 15)->nullable();
            $table->decimal('monto', 20, 2);
            $table->string('descripcion', 100)->nullable();
            $table->dateTime('fechaEmision');
            $table->string('estado', 1);
        });
        Schema::create('nota_credito_aplicacion', function (Blueprint $table): void {
            $table->bigIncrements('idnotaCreditoAplicacion');
            $table->integer('nota_credito_idnotacredito');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->decimal('monto', 20, 2);
            $table->dateTime('fechaAplicacion');
            $table->string('estado', 1)->default('1');
        });

        DB::table('cliente')->insert(['idcliente' => 'CLIENT-1', 'detraccion' => '0']);
        DB::table('moneda')->insert(['idmoneda' => 1, 'detalle' => 'Sol', 'simbolo' => 'S/']);
        DB::table('entidadbancaria')->insert(['identidadBancaria' => 1, 'razonSocial' => 'Banco']);
        DB::table('formapago')->insert(['idformaPago' => 1, 'detalle' => 'Contado']);
        DB::table('nota_credito')->insert([
            'idnotacredito' => 1,
            'cliente_idcliente' => 'CLIENT-1',
            'moneda_idmoneda' => 1,
            'docReferencia' => 'F00-111',
            'monto' => 100,
            'descripcion' => 'A favor del cliente',
            'fechaEmision' => '2026-10-16 10:00:00',
            'estado' => 'E',
        ]);
    }

    public function test_selected_note_is_applied_across_grouped_cxcs_without_creating_a_bank_movement_for_the_note(): void
    {
        $firstCxcId = $this->createCxc(60);
        $secondCxcId = $this->createCxc(80);

        $result = (new CxcService())->procesarPagoGrupo([$firstCxcId, $secondCxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 40,
            'monto_factura' => 140,
            'fechaPago' => '2026-10-16',
            'nota_credito_ids' => [1],
            'docReferencia' => 'F00-111',
            'descripcion' => 'Cobro con nota de crédito',
        ]);

        $applications = DB::table('nota_credito_aplicacion')
            ->orderBy('cuentasPorCobrar_idcuentasPorCobrar')
            ->get();
        $this->assertSame([$firstCxcId, $secondCxcId], $applications->pluck('cuentasPorCobrar_idcuentasPorCobrar')->all());
        $this->assertSame([60.0, 40.0], $applications->pluck('monto')->map(fn ($amount): float => (float) $amount)->all());
        $this->assertSame([0.0, 0.0], DB::table('cuentasporcobrar')
            ->orderBy('idcuentasPorCobrar')
            ->pluck('montoActual')
            ->map(fn ($amount): float => (float) $amount)
            ->all());
        $this->assertSame('A', DB::table('nota_credito')->where('idnotacredito', 1)->value('estado'));
        $this->assertSame(40.0, (float) DB::table('bancos')->where('tipoMov', 'I')->sum('monto'));
        $this->assertSame(1, DB::table('bancos')->count());
        $this->assertSame(100.0, (float) $result['credit_applied']);
        $this->assertSame(40.0, (float) $result['cash_applied']);
    }

    public function test_reverting_a_note_only_payment_releases_the_note_balance(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $cxcId = $this->createCxc(100);
            (new CxcService())->procesarPagoGrupo([$cxcId], [
                'entidadBancaria_identidadBancaria' => 1,
                'formaPago_idformaPago' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'moneda_idmoneda' => 1,
                'modo_pago' => 'total',
                'montoPago' => 0,
                'monto_factura' => 100,
                'fechaPago' => '2026-10-16',
                'nota_credito_ids' => [1],
                'docReferencia' => 'F00-111',
                'descripcion' => 'Pago cubierto con nota de crédito',
            ]);

            $this->assertSame('A', DB::table('nota_credito')->where('idnotacredito', 1)->value('estado'));
            $this->assertSame(0, DB::table('bancos')->count());

            (new CxcService())->revertirPagoCancelado($cxcId, 'tester', 'Reversión de prueba');

            $this->assertSame('E', DB::table('nota_credito')->where('idnotacredito', 1)->value('estado'));
            $this->assertSame('0', DB::table('nota_credito_aplicacion')->value('estado'));
            $this->assertSame(100.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('montoActual'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_group_reversal_releases_credit_and_repayment_records_cash_and_payment_details_again(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            DB::table('nota_credito')->where('idnotacredito', 1)->update(['monto' => 50]);
            $amounts = [45, 50, 50, 45, 30, 50];
            $cxcIds = array_map(fn (float $amount): int => $this->createCxc($amount), $amounts);
            $service = new CxcService();
            $paymentData = [
                'entidadBancaria_identidadBancaria' => 1,
                'formaPago_idformaPago' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'moneda_idmoneda' => 1,
                'modo_pago' => 'total',
                'montoPago' => 220,
                'monto_factura' => 270,
                'fechaPago' => '2026-10-16',
                'nota_credito_ids' => [1],
                'docReferencia' => 'F00-111',
                'descripcion' => 'Pago agrupado con nota de crédito',
            ];

            $firstPayment = $service->procesarPagoGrupo($cxcIds, $paymentData, 'comprobantes/reingreso.png');
            $this->assertSame(50.0, (float) $firstPayment['credit_applied']);
            $this->assertSame(220.0, (float) $firstPayment['cash_applied']);
            $this->assertSame(0.0, (float) $firstPayment['new_credit']);

            $reversal = $service->revertirPagoCanceladoGrupo($cxcIds, 'tester', 'Reversión del grupo');
            $this->assertSame(6, $reversal['count']);
            $this->assertSame([45.0, 50.0, 50.0, 45.0, 30.0, 50.0], DB::table('cuentasporcobrar')
                ->orderBy('idcuentasPorCobrar')
                ->pluck('montoActual')
                ->map(fn ($amount): float => (float) $amount)
                ->all());
            $this->assertSame('E', DB::table('nota_credito')->where('idnotacredito', 1)->value('estado'));

            $secondPayment = $service->procesarPagoGrupo($cxcIds, $paymentData, 'comprobantes/reingreso.png');
            $this->assertSame(50.0, (float) $secondPayment['credit_applied']);
            $this->assertSame(220.0, (float) $secondPayment['cash_applied']);
            $this->assertSame(0.0, (float) $secondPayment['new_credit']);
            $this->assertSame(220.0, (float) DB::table('bancos')
                ->where('tipoMov', 'I')
                ->where('informacion', 'not like', '[ANULADO]%')
                ->sum('monto'));

            $latestPayment = DB::table('detallecxc as dc')
                ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                ->leftJoin('evidenciapago as ep', 'ep.bancos_idbancos', '=', 'b.idbancos')
                ->where('dc.cuentasPorCobrar_idcuentasPorCobrar', $cxcIds[1])
                ->where('dc.estado', '1')
                ->whereNotNull('dc.fechaPago')
                ->orderByDesc('dc.iddetalleCXC')
                ->first([
                    'dc.fechaPago',
                    'dc.formaPago_idformaPago',
                    'dc.descripcion',
                    'b.entidadBancaria_identidadBancaria',
                    'b.informacion',
                    'ep.archivo',
                ]);

            $this->assertNotNull($latestPayment);
            $this->assertSame('2026-10-16 12:00:00', $latestPayment->fechaPago);
            $this->assertSame(1, (int) $latestPayment->formaPago_idformaPago);
            $this->assertSame(1, (int) $latestPayment->entidadBancaria_identidadBancaria);
            $this->assertSame('Pago agrupado con nota de crédito', $latestPayment->descripcion);
            $this->assertSame('comprobantes/reingreso.png', $latestPayment->archivo);
            $this->assertStringContainsString('Pago agrupado con nota de crédito', $latestPayment->informacion);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_note_application_date_is_used_as_cxc_reversal_date(): void
    {
        $cxcId = $this->createCxc(100);
        DB::table('nota_credito_aplicacion')->insert([
            'nota_credito_idnotacredito' => 1,
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'monto' => 100,
            'fechaAplicacion' => '2026-10-16 12:00:00',
            'estado' => '1',
        ]);

        $service = new CuentasPorCobrarService();
        $method = new \ReflectionMethod($service, 'latestPaymentDateSubquery');
        $latestPaymentDateQuery = $method->invoke($service);
        $paymentDate = DB::table('cuentasporcobrar as c')
            ->where('c.idcuentasPorCobrar', $cxcId)
            ->selectSub($latestPaymentDateQuery, 'payment_date')
            ->value('payment_date');

        $this->assertSame('2026-10-16 12:00:00', $paymentDate);
    }

    protected function tearDown(): void
    {
        foreach ([
            'nota_credito_aplicacion',
            'nota_credito',
            'evidenciapago',
            'detallecxc',
            'serviciocliente',
            'bancos',
            'formapago',
            'entidadbancaria',
            'moneda',
            'cuentasporcobrar',
            'cliente',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function createCxc(float $amount): int
    {
        return (int) DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'Servicio mensual',
            'montoOriginal' => $amount,
            'montoActual' => $amount,
            'estado' => '1',
        ]);
    }
}
