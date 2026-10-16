<?php

namespace Tests\Unit;

use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CotizacionService;
use App\Services\CxcService;
use App\Services\CuentasPorCobrarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CotizacionExportCustomLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('cliente_idcliente');
            $table->string('batch_id')->nullable();
            $table->decimal('total', 20, 2);
            $table->dateTime('fechaHoraEmision');
            $table->string('estado');
            $table->string('docReferencia')->nullable();
            $table->integer('moneda_idmoneda')->default(1);
            $table->integer('vigenciaOferta_idvigenciaOferta')->default(1);
            $table->integer('formaPago_idformaPago')->default(1);
        });

        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
            $table->string('detraccion')->default('0');
        });

        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle');
            $table->string('simbolo');
        });

        Schema::create('vigenciaoferta', function (Blueprint $table): void {
            $table->increments('idvigenciaOferta');
            $table->string('detalle');
        });

        Schema::create('formapago', function (Blueprint $table): void {
            $table->increments('idformaPago');
            $table->string('detalle');
            $table->integer('tiempo')->default(0);
        });

        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('descripcion');
            $table->string('docReferencia')->nullable();
            $table->string('docFactura')->nullable();
            $table->decimal('montoOriginal', 20, 2);
            $table->decimal('montoActual', 20, 2);
            $table->string('estado');
            $table->string('cliente_idcliente')->nullable();
            $table->integer('moneda_idmoneda')->default(1);
            $table->dateTime('fechaRegistro')->nullable();
            $table->dateTime('fechaCancelacion')->nullable();
        });

        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos')->nullable();
            $table->string('descripcion')->nullable();
            $table->dateTime('fechaPago')->nullable();
            $table->date('fechaPagoProgramada')->nullable();
            $table->integer('nCuota')->nullable();
            $table->string('estado')->default('1');
        });

        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->decimal('monto', 20, 2);
            $table->string('tipoMov')->default('I');
            $table->dateTime('fechaRegistro')->nullable();
            $table->string('informacion')->nullable();
        });

        DB::table('moneda')->insert([
            ['idmoneda' => 1, 'detalle' => 'Soles', 'simbolo' => 'S/'],
        ]);
        DB::table('vigenciaoferta')->insert([
            ['idvigenciaOferta' => 1, 'detalle' => 'Vigencia de 10 dias'],
        ]);
        DB::table('formapago')->insert([
            ['idformaPago' => 1, 'detalle' => 'Contado', 'tiempo' => 0],
        ]);
    }

    public function test_prepare_quotations_export_data_matches_image_scenarios(): void
    {
        // 1. Cliente A&G con Detracción (monto 790, cliente pagó 695, detracción 95 pagada)
        DB::table('cliente')->insert([
            'idcliente' => '20522228347',
            'razonSocial' => 'A&G TRUCKS AND SERVICES S.A.C.',
            'detraccion' => '0',
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00413',
            'cliente_idcliente' => '20522228347',
            'total' => 790.00,
            'fechaHoraEmision' => '2026-10-01 10:00:00',
            'estado' => '2',
        ]);
        $cxc1 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00413',
            'docReferencia' => 'F00-413',
            'montoOriginal' => 790.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '20522228347',
        ]);
        $bancoPago1 = DB::table('bancos')->insertGetId([
            'monto' => 695.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-10-09 12:00:00',
            'informacion' => 'PAGO 9/10/2026',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc1,
            'bancos_idbancos' => $bancoPago1,
            'descripcion' => 'PAGO 9/10/2026',
            'fechaPago' => '2026-10-09 12:00:00',
            'estado' => '1',
        ]);
        $bancoDet1 = DB::table('bancos')->insertGetId([
            'monto' => 95.00,
            'tipoMov' => 'T',
            'fechaRegistro' => '2026-10-05 10:00:00',
            'informacion' => 'Detracción aplicada',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc1,
            'bancos_idbancos' => $bancoDet1,
            'descripcion' => 'Detracción aplicada',
            'fechaPago' => '2026-10-05 10:00:00',
            'estado' => '1',
        ]);

        // 2. Cliente TRANS CARGO con Autodetracción (monto 850, cliente pagó 850 total, autodetracción 102 pagada)
        DB::table('cliente')->insert([
            'idcliente' => '20491821559',
            'razonSocial' => 'TRANS CARGO TERRA S.A.C.',
            'detraccion' => '0',
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00414',
            'cliente_idcliente' => '20491821559',
            'total' => 850.00,
            'fechaHoraEmision' => '2026-10-01 11:00:00',
            'estado' => '2',
        ]);
        $cxc2 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00414',
            'docReferencia' => 'F00-414',
            'montoOriginal' => 850.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '20491821559',
        ]);
        $bancoPago2 = DB::table('bancos')->insertGetId([
            'monto' => 850.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-10-15 14:00:00',
            'informacion' => 'PAGO 15/10/2026',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc2,
            'bancos_idbancos' => $bancoPago2,
            'descripcion' => 'PAGO 15/10/2026',
            'fechaPago' => '2026-10-15 14:00:00',
            'estado' => '1',
        ]);
        $bancoDet2 = DB::table('bancos')->insertGetId([
            'monto' => 102.00,
            'tipoMov' => 'T',
            'fechaRegistro' => '2026-10-18 09:30:00',
            'informacion' => 'Detracción aplicada',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc2,
            'bancos_idbancos' => $bancoDet2,
            'descripcion' => 'Detracción aplicada',
            'fechaPago' => '2026-10-18 09:30:00',
            'estado' => '1',
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $prepareMethod = $reflection->getMethod('prepareQuotationsExportData');
        $prepareMethod->setAccessible(true);

        $rawRows = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->select([
                'c.nroCotizacion',
                'c.cliente_idcliente',
                'c.batch_id',
                'cli.razonSocial',
                'cli.nombreComercial',
                'cli.detraccion as cliente_detraccion',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'c.estado as cotizacion_estado',
                'm.detalle as moneda_detalle',
                'm.simbolo as moneda_simbolo',
                DB::raw("'SERVICIOS TÉCNICOS' as servicio"),
            ])
            ->orderBy('c.nroCotizacion')
            ->get();

        $exportData = $prepareMethod->invoke($controller, $rawRows);

        $this->assertCount(2, $exportData);

        // Fila 1: Detracción normal
        $row1 = $exportData[0];
        $this->assertSame('A&G TRUCKS AND SERVICES S.A.C.', $row1['cliente']);
        $this->assertSame('20522228347', $row1['ruc']);
        $this->assertSame('1/10/2026', $row1['fecha_emision']);
        $this->assertSame('F00-413', $row1['nro_factura']);
        $this->assertSame('S/ 790.00', $row1['total']);
        $this->assertSame('S/ 695.00', $row1['pago_realizado']);
        $this->assertSame('PAGO 9/10/2026', $row1['observacion']);
        $this->assertSame('S/ 95.00', $row1['detraccion']);
        $this->assertSame('5/10/2026', $row1['fecha_abono_detraccion']);
        $this->assertSame('', $row1['autodetraccion']);
        $this->assertSame('', $row1['fecha_abono_autodetraccion']);

        // Fila 2: Autodetracción
        $row2 = $exportData[1];
        $this->assertSame('TRANS CARGO TERRA S.A.C.', $row2['cliente']);
        $this->assertSame('20491821559', $row2['ruc']);
        $this->assertSame('1/10/2026', $row2['fecha_emision']);
        $this->assertSame('F00-414', $row2['nro_factura']);
        $this->assertSame('S/ 850.00', $row2['total']);
        $this->assertSame('S/ 850.00', $row2['pago_realizado']);
        $this->assertSame('PAGO 15/10/2026', $row2['observacion']);
        $this->assertSame('', $row2['detraccion']);
        $this->assertSame('', $row2['fecha_abono_detraccion']);
        $this->assertSame('S/ 102.00', $row2['autodetraccion']);
        $this->assertSame('18/10/2026', $row2['fecha_abono_autodetraccion']);
    }

    public function test_user_feedback_scenarios_840_and_720(): void
    {
        // 1. CT00412: Fila 1 de la imagen del usuario (A&G TRUCKS... S/ 840 total, pagó 739, detracción 101 pendiente, obs: 'Se pago 739 falta detracción.')
        DB::table('cliente')->insert([
            'idcliente' => '20601509793',
            'razonSocial' => 'A&G TRUCKS AND SERVICES S.A.C.',
            'detraccion' => '0',
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00412',
            'cliente_idcliente' => '20601509793',
            'total' => 840.00,
            'fechaHoraEmision' => '2026-10-13 09:00:00',
            'estado' => '1',
        ]);
        $cxc1 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00412',
            'docReferencia' => 'F00-24814',
            'montoOriginal' => 840.00,
            'montoActual' => 101.00,
            'estado' => 'Pendiente a Detracción',
            'cliente_idcliente' => '20601509793',
        ]);
        $banco1 = DB::table('bancos')->insertGetId([
            'monto' => 739.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-10-13 10:00:00',
            'informacion' => 'Se pago 739 falta detracción.',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc1,
            'bancos_idbancos' => $banco1,
            'descripcion' => 'Se pago 739 falta detracción.',
            'fechaPago' => '2026-10-13 10:00:00',
            'estado' => '1',
        ]);
        // Detracción pendiente de 101 aún no pagada por el cliente
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc1,
            'bancos_idbancos' => $banco1,
            'descripcion' => 'Detracción pendiente: 101.00',
            'fechaPagoProgramada' => '2026-10-13',
            'fechaPago' => null,
            'estado' => '0',
        ]);

        // 2. CT00415: Fila 2 de la imagen del usuario (ALDABA NIEVES... pagó 634 con detracción, obs: 'pago con detraccion pago total es 720', detracción 86.00 pagada el 13/10/2026)
        DB::table('cliente')->insert([
            'idcliente' => '10226758581',
            'razonSocial' => 'ALDABA NIEVES JULIO',
            'detraccion' => '0',
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00415',
            'cliente_idcliente' => '10226758581',
            'total' => 720.00,
            'fechaHoraEmision' => '2026-10-13 11:00:00',
            'estado' => '2',
        ]);
        $cxc2 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00415',
            'docReferencia' => 'F00-310126',
            'montoOriginal' => 720.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '10226758581',
        ]);
        $banco2 = DB::table('bancos')->insertGetId([
            'monto' => 634.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-10-13 11:30:00',
            'informacion' => 'pago con detraccion pago total es 720',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc2,
            'bancos_idbancos' => $banco2,
            'descripcion' => 'pago con detraccion pago total es 720',
            'fechaPago' => '2026-10-13 11:30:00',
            'estado' => '1',
        ]);
        $bancoDet2 = DB::table('bancos')->insertGetId([
            'monto' => 86.00,
            'tipoMov' => 'T',
            'fechaRegistro' => '2026-10-13 12:00:00',
            'informacion' => 'Detracción aplicada',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc2,
            'bancos_idbancos' => $bancoDet2,
            'descripcion' => 'Detracción aplicada',
            'fechaPago' => '2026-10-13 12:00:00',
            'estado' => '1',
        ]);

        // 3. CT00416: Autodetracción (cliente pagó los 840 completos, y la empresa autodetrae 101 depositado el 8/10/2026)
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00416',
            'cliente_idcliente' => '20601509793',
            'total' => 840.00,
            'fechaHoraEmision' => '2026-10-01 08:00:00',
            'estado' => '2',
        ]);
        $cxc3 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00416',
            'docReferencia' => 'F00-24899',
            'montoOriginal' => 840.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '20601509793',
        ]);
        $banco3 = DB::table('bancos')->insertGetId([
            'monto' => 840.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-10-01 09:00:00',
            'informacion' => 'Pago completo de cotización',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc3,
            'bancos_idbancos' => $banco3,
            'descripcion' => 'Pago de cotización CT00416',
            'fechaPago' => '2026-10-01 09:00:00',
            'estado' => '1',
        ]);
        $bancoDet3 = DB::table('bancos')->insertGetId([
            'monto' => 101.00,
            'tipoMov' => 'T',
            'fechaRegistro' => '2026-10-08 10:00:00',
            'informacion' => 'Detracción aplicada',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc3,
            'bancos_idbancos' => $bancoDet3,
            'descripcion' => 'Detracción aplicada',
            'fechaPago' => '2026-10-08 10:00:00',
            'estado' => '1',
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $prepareMethod = $reflection->getMethod('prepareQuotationsExportData');
        $prepareMethod->setAccessible(true);

        $rawRows = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->whereIn('c.nroCotizacion', ['CT00412', 'CT00415', 'CT00416'])
            ->select([
                'c.nroCotizacion',
                'c.cliente_idcliente',
                'c.batch_id',
                'cli.razonSocial',
                'cli.nombreComercial',
                'cli.detraccion as cliente_detraccion',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'c.estado as cotizacion_estado',
                'm.detalle as moneda_detalle',
                'm.simbolo as moneda_simbolo',
                DB::raw("'SERVICIOS TÉCNICOS' as servicio"),
            ])
            ->orderBy('c.nroCotizacion')
            ->get();

        $exportData = $prepareMethod->invoke($controller, $rawRows);

        $this->assertCount(3, $exportData);

        // 1. CT00412: Total 840, pagó 739, detracción 101 pendiente
        $rowPending = $exportData[0];
        $this->assertSame('A&G TRUCKS AND SERVICES S.A.C.', $rowPending['cliente']);
        $this->assertSame('20601509793', $rowPending['ruc']);
        $this->assertSame('13/10/2026', $rowPending['fecha_emision']);
        $this->assertSame('F00-24814', $rowPending['nro_factura']);
        $this->assertSame('S/ 840.00', $rowPending['total']);
        $this->assertSame('S/ 739.00', $rowPending['pago_realizado']);
        $this->assertSame('Se pago 739 falta detracción.', $rowPending['observacion']);
        $this->assertSame('S/ 101.00', $rowPending['detraccion']);
        $this->assertSame('', $rowPending['fecha_abono_detraccion']); // Pendiente, no pagada aún
        $this->assertSame('', $rowPending['autodetraccion']);
        $this->assertSame('', $rowPending['fecha_abono_autodetraccion']);

        // 2. CT00415: Total 720, pagó 634 con detracción 86 abonada el 13/10/2026
        $rowDetPaid = $exportData[1];
        $this->assertSame('ALDABA NIEVES JULIO', $rowDetPaid['cliente']);
        $this->assertSame('10226758581', $rowDetPaid['ruc']);
        $this->assertSame('13/10/2026', $rowDetPaid['fecha_emision']);
        $this->assertSame('F00-310126', $rowDetPaid['nro_factura']);
        $this->assertSame('S/ 720.00', $rowDetPaid['total']);
        $this->assertSame('S/ 634.00', $rowDetPaid['pago_realizado']);
        $this->assertSame('pago con detraccion pago total es 720', $rowDetPaid['observacion']);
        $this->assertSame('S/ 86.00', $rowDetPaid['detraccion']);
        $this->assertSame('13/10/2026', $rowDetPaid['fecha_abono_detraccion']);
        $this->assertSame('', $rowDetPaid['autodetraccion']);
        $this->assertSame('', $rowDetPaid['fecha_abono_autodetraccion']);

        // 3. CT00416: Total 840, cliente pagó 840 completo -> Autodetracción 101 abonada el 8/10/2026
        $rowAutoPaid = $exportData[2];
        $this->assertSame('A&G TRUCKS AND SERVICES S.A.C.', $rowAutoPaid['cliente']);
        $this->assertSame('20601509793', $rowAutoPaid['ruc']);
        $this->assertSame('1/10/2026', $rowAutoPaid['fecha_emision']);
        $this->assertSame('F00-24899', $rowAutoPaid['nro_factura']);
        $this->assertSame('S/ 840.00', $rowAutoPaid['total']);
        $this->assertSame('S/ 840.00', $rowAutoPaid['pago_realizado']);
        $this->assertSame('', $rowAutoPaid['observacion']);
        $this->assertSame('', $rowAutoPaid['detraccion']);
        $this->assertSame('', $rowAutoPaid['fecha_abono_detraccion']);
        $this->assertSame('S/ 101.00', $rowAutoPaid['autodetraccion']);
        $this->assertSame('8/10/2026', $rowAutoPaid['fecha_abono_autodetraccion']);
    }

    public function test_retention_equipamiento_above_700_is_classified_as_retention(): void
    {
        // Cotización en EQUIPAMIENTO mayor a 700
        DB::table('cliente')->insert([
            'idcliente' => '20519755611',
            'razonSocial' => 'TRANSP. CRUZ DE LARA S.R.L.',
            'detraccion' => '0',
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00420',
            'cliente_idcliente' => '20519755611',
            'total' => 1575.00,
            'fechaHoraEmision' => '2026-08-25 10:00:00',
            'estado' => '2',
        ]);
        $cxc = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00420',
            'docReferencia' => 'F00-410162',
            'montoOriginal' => 1575.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '20519755611',
        ]);
        $bancoPago = DB::table('bancos')->insertGetId([
            'monto' => 1575.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-08-25 11:00:00',
            'informacion' => 'Pago completo de 1575 dolares.',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc,
            'bancos_idbancos' => $bancoPago,
            'descripcion' => 'Pago completo de 1575 dolares.',
            'fechaPago' => '2026-08-25 11:00:00',
            'estado' => '1',
        ]);
        $bancoRet = DB::table('bancos')->insertGetId([
            'monto' => 189.00,
            'tipoMov' => 'T',
            'fechaRegistro' => '2026-10-16 10:00:00',
            'informacion' => 'Retención aplicada',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc,
            'bancos_idbancos' => $bancoRet,
            'descripcion' => 'Retención aplicada',
            'fechaPago' => '2026-10-16 10:00:00',
            'estado' => '1',
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $prepareMethod = $reflection->getMethod('prepareQuotationsExportData');
        $prepareMethod->setAccessible(true);

        $rawRows = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->where('c.nroCotizacion', 'CT00420')
            ->select([
                'c.nroCotizacion',
                'c.cliente_idcliente',
                'c.batch_id',
                'cli.razonSocial',
                'cli.nombreComercial',
                'cli.detraccion as cliente_detraccion',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'c.estado as cotizacion_estado',
                'm.detalle as moneda_detalle',
                'm.simbolo as moneda_simbolo',
                DB::raw("'EQUIPAMIENTO' as servicio"),
            ])
            ->get();

        $exportData = $prepareMethod->invoke($controller, $rawRows);

        $this->assertCount(1, $exportData);
        $row = $exportData[0];
        $this->assertTrue($row['is_retention']);
        $this->assertSame('TRANSP. CRUZ DE LARA S.R.L.', $row['cliente']);
        $this->assertSame('F00-410162', $row['nro_factura']);
        $this->assertSame('Pago completo de 1575 dolares.', $row['observacion']);
        $this->assertSame('', $row['detraccion']);
        $this->assertSame('', $row['autodetraccion']);
        $this->assertSame('', $row['retencion']);
        $this->assertSame('S/ 189.00', $row['autoretencion']);
        $this->assertSame('16/10/2026', $row['fecha_abono_autoretencion']);
    }

    public function test_xlsx_export_separates_detraccion_and_retencion_tables(): void
    {
        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $exportMethod = $reflection->getMethod('exportCustomQuotationsXlsxResponse');
        $exportMethod->setAccessible(true);

        $dummyRows = [
            [
                'cliente' => 'CLIENTE NORMAL 1',
                'ruc' => '20111111111',
                'fecha_emision' => '1/10/2026',
                'nro_factura' => 'F001-01',
                'total' => 'S/ 500.00',
                'pago_realizado' => 'S/ 500.00',
                'observacion' => 'Normal sin deducción',
                'detraccion' => '',
                'fecha_abono_detraccion' => '',
                'autodetraccion' => '',
                'fecha_abono_autodetraccion' => '',
                'is_retention' => false,
            ],
            [
                'cliente' => 'CLIENTE DETRACCION 2',
                'ruc' => '20222222222',
                'fecha_emision' => '2/10/2026',
                'nro_factura' => 'F001-02',
                'total' => 'S/ 1,000.00',
                'pago_realizado' => 'S/ 880.00',
                'observacion' => 'Pago con detracción',
                'detraccion' => 'S/ 120.00',
                'fecha_abono_detraccion' => '5/10/2026',
                'autodetraccion' => '',
                'fecha_abono_autodetraccion' => '',
                'is_retention' => false,
            ],
            [
                'cliente' => 'CLIENTE RETENCION 3',
                'ruc' => '20333333333',
                'fecha_emision' => '3/10/2026',
                'nro_factura' => 'F001-03',
                'total' => '$ 1,575.00',
                'pago_realizado' => '$ 1,575.00',
                'observacion' => 'Pago completo equipamiento',
                'retencion' => '',
                'fecha_abono_retencion' => '',
                'autoretencion' => 'S/ 189.00',
                'fecha_abono_autoretencion' => '16/10/2026',
                'is_retention' => true,
            ],
        ];

        /** @var \Symfony\Component\HttpFoundation\BinaryFileResponse $response */
        $response = $exportMethod->invoke($controller, $dummyRows, 'test_export.xlsx');
        $filePath = $response->getFile()->getPathname();

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Tabla de Detracciones y Normales inicia en fila 1
        $this->assertSame('CLIENTE', $sheet->getCell('A1')->getValue());
        $this->assertSame('DETRACCION', $sheet->getCell('H1')->getValue());
        $this->assertSame('AUTODETRACCION', $sheet->getCell('J1')->getValue());

        // Fila 2: CLIENTE NORMAL 1
        $this->assertSame('CLIENTE NORMAL 1', $sheet->getCell('A2')->getValue());
        $this->assertSame('S/ 500.00', $sheet->getCell('E2')->getValue());

        // Fila 3: CLIENTE DETRACCION 2
        $this->assertSame('CLIENTE DETRACCION 2', $sheet->getCell('A3')->getValue());
        $this->assertSame('S/ 120.00', $sheet->getCell('H3')->getValue());

        // Fila 4 y 5 deben ser filas vacías de separación
        $this->assertNull($sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('H4')->getValue());
        $this->assertNull($sheet->getCell('A5')->getValue());
        $this->assertNull($sheet->getCell('H5')->getValue());

        // Fila 6: Cabecera de la Tabla de Retenciones
        $this->assertSame('CLIENTE', $sheet->getCell('A6')->getValue());
        $this->assertSame('RETENCION', $sheet->getCell('H6')->getValue());
        $this->assertSame('AUTORETENCION', $sheet->getCell('J6')->getValue());

        // Fila 7: Fila de datos de la Tabla de Retenciones
        $this->assertSame('CLIENTE RETENCION 3', $sheet->getCell('A7')->getValue());
        $this->assertSame('$ 1,575.00', $sheet->getCell('E7')->getValue());
        $this->assertSame('S/ 189.00', $sheet->getCell('J7')->getValue());
        $this->assertSame('16/10/2026', $sheet->getCell('K7')->getValue());
    }

    public function test_xlsx_export_only_retenciones_starts_at_row_1(): void
    {
        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $exportMethod = $reflection->getMethod('exportCustomQuotationsXlsxResponse');
        $exportMethod->setAccessible(true);

        $dummyRows = [
            [
                'cliente' => 'EMPRESA SOLO RETENCION',
                'ruc' => '20999999999',
                'fecha_emision' => '10/10/2026',
                'nro_factura' => 'F002-99',
                'total' => '$ 2,000.00',
                'pago_realizado' => '$ 1,940.00',
                'observacion' => 'Retención aplicada',
                'retencion' => '$ 60.00',
                'fecha_abono_retencion' => '12/10/2026',
                'autoretencion' => '',
                'fecha_abono_autoretencion' => '',
                'is_retention' => true,
            ],
        ];

        /** @var \Symfony\Component\HttpFoundation\BinaryFileResponse $response */
        $response = $exportMethod->invoke($controller, $dummyRows, 'test_only_ret.xlsx');
        $filePath = $response->getFile()->getPathname();

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Debe iniciar directamente en la fila 1 con cabecera de RETENCION
        $this->assertSame('CLIENTE', $sheet->getCell('A1')->getValue());
        $this->assertSame('RETENCION', $sheet->getCell('H1')->getValue());
        $this->assertSame('AUTORETENCION', $sheet->getCell('J1')->getValue());

        $this->assertSame('EMPRESA SOLO RETENCION', $sheet->getCell('A2')->getValue());
        $this->assertSame('$ 60.00', $sheet->getCell('H2')->getValue());
        $this->assertSame('12/10/2026', $sheet->getCell('I2')->getValue());

        // No hay fila 3 con otra cabecera
        $this->assertNull($sheet->getCell('A3')->getValue());
    }

    public function test_export_approved_quotations_without_banco_records_does_not_fail(): void
    {
        // Cotización aprobada (estado = 2), CXC CANCELADO, pero sin registros en detallecxc/bancos
        DB::table('cliente')->insert([
            'idcliente' => '20605306714',
            'razonSocial' => '24.7 RENTING S.A.C.',
            'detraccion' => '0',
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00421',
            'cliente_idcliente' => '20605306714',
            'total' => 1040.00,
            'fechaHoraEmision' => '2026-10-15 10:00:00',
            'estado' => '2',
        ]);
        DB::table('cuentasporcobrar')->insert([
            'descripcion' => 'COTIZACION:CT00421',
            'docReferencia' => 'F00-052687',
            'montoOriginal' => 1040.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'fechaCancelacion' => '2026-10-15 15:00:00',
            'cliente_idcliente' => '20605306714',
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $prepareMethod = $reflection->getMethod('prepareQuotationsExportData');
        $prepareMethod->setAccessible(true);

        $rawRows = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->where('c.nroCotizacion', 'CT00421')
            ->select([
                'c.nroCotizacion',
                'c.cliente_idcliente',
                'c.batch_id',
                'cli.razonSocial',
                'cli.nombreComercial',
                'cli.detraccion as cliente_detraccion',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'c.estado as cotizacion_estado',
                'm.detalle as moneda_detalle',
                'm.simbolo as moneda_simbolo',
                DB::raw("'SERVICIOS TÉCNICOS' as servicio"),
            ])
            ->get();

        // Esto antes lanzaba ErrorException: Attempt to read property "banco_fecha_registro" on bool
        $exportData = $prepareMethod->invoke($controller, $rawRows);

        $this->assertCount(1, $exportData);
        $row = $exportData[0];
        $this->assertSame('24.7 RENTING S.A.C.', $row['cliente']);
        $this->assertSame('S/ 1,040.00', $row['total']);
        $this->assertSame('S/ 1,040.00', $row['pago_realizado']);
        $this->assertSame('S/ 125.00', $row['autodetraccion']);
    }

    public function test_equipamiento_under_700_has_no_retention_and_above_700_has_retention(): void
    {
        // Cliente TOPY TOP S A con detraccion = '1'
        DB::table('cliente')->insert([
            'idcliente' => '20100047056',
            'razonSocial' => 'TOPY TOP S A',
            'detraccion' => '1',
        ]);

        // 1. Cotización de 50 dólares (no debe aplicar retención)
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00430',
            'cliente_idcliente' => '20100047056',
            'total' => 50.00,
            'fechaHoraEmision' => '2026-09-10 10:00:00',
            'estado' => '2',
            'moneda_idmoneda' => 2,
        ]);
        $cxc1 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00430',
            'docReferencia' => '',
            'montoOriginal' => 50.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '20100047056',
            'moneda_idmoneda' => 2,
        ]);
        $banco1 = DB::table('bancos')->insertGetId([
            'monto' => 50.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-09-10 11:00:00',
            'informacion' => 'Pago 50 dolares',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc1,
            'bancos_idbancos' => $banco1,
            'descripcion' => 'Pago 50 dolares',
            'fechaPago' => '2026-09-10 11:00:00',
            'estado' => '1',
        ]);

        // 2. Cotización de 1,200 dólares (sí supera 700 -> aplica retención 3% = 36.00)
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00431',
            'cliente_idcliente' => '20100047056',
            'total' => 1200.00,
            'fechaHoraEmision' => '2026-09-17 10:00:00',
            'estado' => '2',
            'moneda_idmoneda' => 2,
        ]);
        $cxc2 = DB::table('cuentasporcobrar')->insertGetId([
            'descripcion' => 'COTIZACION:CT00431',
            'docReferencia' => '',
            'montoOriginal' => 1200.00,
            'montoActual' => 0.00,
            'estado' => 'CANCELADO',
            'cliente_idcliente' => '20100047056',
            'moneda_idmoneda' => 2,
        ]);
        $banco2 = DB::table('bancos')->insertGetId([
            'monto' => 1200.00,
            'tipoMov' => 'I',
            'fechaRegistro' => '2026-09-17 11:00:00',
            'informacion' => 'Pago 1200 dolares',
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxc2,
            'bancos_idbancos' => $banco2,
            'descripcion' => 'Pago 1200 dolares',
            'fechaPago' => '2026-09-17 11:00:00',
            'estado' => '1',
        ]);

        // Moneda dólares en tabla moneda
        DB::table('moneda')->insert([
            'idmoneda' => 2,
            'detalle' => 'Dólares',
            'simbolo' => '$',
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );

        $reflection = new \ReflectionClass($controller);
        $prepareMethod = $reflection->getMethod('prepareQuotationsExportData');
        $prepareMethod->setAccessible(true);

        $rawRows = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->whereIn('c.nroCotizacion', ['CT00430', 'CT00431'])
            ->select([
                'c.nroCotizacion',
                'c.cliente_idcliente',
                'c.batch_id',
                'cli.razonSocial',
                'cli.nombreComercial',
                'cli.detraccion as cliente_detraccion',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'c.estado as cotizacion_estado',
                'm.detalle as moneda_detalle',
                'm.simbolo as moneda_simbolo',
                DB::raw("'EQUIPAMIENTO' as servicio"),
            ])
            ->orderBy('c.nroCotizacion')
            ->get();

        $exportData = $prepareMethod->invoke($controller, $rawRows);

        $this->assertCount(2, $exportData);

        // Fila 1: CT00430 ($50.00) -> Va a tabla retención (is_retention=true) pero SIN retención ni autoretención
        $row50 = $exportData[0];
        $this->assertTrue($row50['is_retention']);
        $this->assertSame('$ 50.00', $row50['total']);
        $this->assertSame('$ 50.00', $row50['pago_realizado']);
        $this->assertSame('', $row50['retencion']);
        $this->assertSame('', $row50['autoretencion']);

        // Fila 2: CT00431 ($1,200.00) -> Va a tabla retención con autoretención de 3% ($36.00)
        $row1200 = $exportData[1];
        $this->assertTrue($row1200['is_retention']);
        $this->assertSame('$ 1,200.00', $row1200['total']);
        $this->assertSame('$ 1,200.00', $row1200['pago_realizado']);
        $this->assertSame('', $row1200['retencion']);
        $this->assertSame('$ 36.00', $row1200['autoretencion']);
    }
}
