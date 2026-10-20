<?php

namespace Tests\Unit;

use App\Services\CotizacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CotizacionAnulacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('batch_id')->nullable();
            $table->string('estado');
            $table->dateTime('fechaHoraEmision')->nullable();
        });
        Schema::create('tipooperacion', function (Blueprint $table): void {
            $table->increments('idtipoOperacion');
            $table->string('nomenclatura')->nullable();
            $table->string('detalle')->nullable();
        });
        Schema::create('ticket', function (Blueprint $table): void {
            $table->increments('idticket');
            $table->string('pedidoReferencia');
            $table->unsignedInteger('tipoOperacion_idtipoOperacion');
            $table->string('estado');
            $table->dateTime('fechaHoraCierre')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion');
            $table->decimal('montoOriginal', 10, 2);
            $table->decimal('montoActual', 10, 2);
            $table->dateTime('fechaRegistro')->nullable();
            $table->date('fechaCancelacion')->nullable();
            $table->string('estado');
            $table->string('docReferencia')->nullable();
            $table->string('docfactura')->nullable();
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('formaPago_idformaPago');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('moneda_idmoneda')->nullable();
            $table->dateTime('fechaPago')->nullable();
            $table->integer('bancos_idbancos')->nullable();
            $table->string('estado');
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->string('informacion');
            $table->string('tipoMov')->nullable();
            $table->decimal('monto', 10, 2)->nullable();
            $table->integer('entidadBancaria_identidadBancaria')->nullable();
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('docReferencia')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('serviciocliente');
        Schema::dropIfExists('bancos');
        Schema::dropIfExists('detallecxc');
        Schema::dropIfExists('cuentasporcobrar');
        Schema::dropIfExists('ticket');
        Schema::dropIfExists('tipooperacion');
        Schema::dropIfExists('cotizacion');

        parent::tearDown();
    }

    public function test_anula_generado_y_aprobado_sin_pago_y_resuelve_gestion_del_grupo(): void
    {
        $operationId = DB::table('tipooperacion')->insertGetId([
            'nomenclatura' => 'CT',
            'detalle' => 'Cotización',
        ]);
        DB::table('cotizacion')->insert([
            ['nroCotizacion' => 'C001', 'batch_id' => 'GRUPO-1', 'estado' => CotizacionService::STATE_GENERADO],
            ['nroCotizacion' => 'C002', 'batch_id' => 'GRUPO-1', 'estado' => CotizacionService::STATE_APROBADO_SP],
        ]);
        $ticketId = DB::table('ticket')->insertGetId([
            'pedidoReferencia' => 'GRUPO-1',
            'tipoOperacion_idtipoOperacion' => $operationId,
            'estado' => 'Activo',
        ]);

        $result = (new CotizacionService)->anularCotizaciones(['C001', 'C002']);

        $this->assertTrue($result['success']);
        $this->assertSame(['C001', 'C002'], $result['quote_ids']);
        $this->assertSame([CotizacionService::STATE_ANULADO, CotizacionService::STATE_ANULADO], DB::table('cotizacion')->orderBy('nroCotizacion')->pluck('estado')->all());
        $this->assertSame([$ticketId], $result['ticket_ids']);
        $this->assertSame('Resuelto', DB::table('ticket')->value('estado'));
        $this->assertNotNull(DB::table('ticket')->value('fechaHoraCierre'));
    }

    public function test_anula_cotizaciones_aprobadas_sin_pagos(): void
    {
        DB::table('cotizacion')->insert([
            ['nroCotizacion' => 'C001', 'batch_id' => null, 'estado' => CotizacionService::STATE_APROBADO, 'fechaHoraEmision' => '2020-01-01 10:00:00'],
            ['nroCotizacion' => 'C002', 'batch_id' => null, 'estado' => CotizacionService::STATE_APROBADO_SP, 'fechaHoraEmision' => '2020-01-01 10:00:00'],
        ]);
        DB::table('cuentasporcobrar')->insert([
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:C001',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaRegistro' => now()->format('Y-m-d H:i:s'),
            'estado' => 'NUEVO',
        ]);

        $result = (new CotizacionService)->anularCotizaciones(['C001', 'C002']);

        $this->assertTrue($result['success']);
        $this->assertSame(
            [CotizacionService::STATE_ANULADO, CotizacionService::STATE_ANULADO],
            DB::table('cotizacion')->orderBy('nroCotizacion')->pluck('estado')->all()
        );
    }

    public function test_aprobado_sp_con_pago_sigue_sin_poder_anularse(): void
    {
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'C004',
            'batch_id' => null,
            'estado' => CotizacionService::STATE_APROBADO_SP,
        ]);
        DB::table('cuentasporcobrar')->insert([
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:C004',
            'montoOriginal' => 45,
            'montoActual' => 0,
            'estado' => '3',
        ]);

        $result = (new CotizacionService)->anularCotizaciones(['C004']);

        $this->assertFalse($result['success']);
        $this->assertSame(CotizacionService::STATE_APROBADO_SP, DB::table('cotizacion')->where('nroCotizacion', 'C004')->value('estado'));
    }

    public function test_aprobado_solo_se_anula_hasta_el_septimo_dia_desde_su_aprobacion(): void
    {
        Carbon::setTestNow('2026-10-18 12:00:00');
        try {
            DB::table('cotizacion')->insert([
                ['nroCotizacion' => 'C005', 'batch_id' => null, 'estado' => CotizacionService::STATE_APROBADO, 'fechaHoraEmision' => '2026-09-01 10:00:00'],
                ['nroCotizacion' => 'C006', 'batch_id' => null, 'estado' => CotizacionService::STATE_APROBADO, 'fechaHoraEmision' => '2026-09-01 10:00:00'],
            ]);
            foreach ([
                ['COTIZACION:C005', '2026-10-11 10:00:00'],
                ['COTIZACION:C006', '2026-10-11 10:00:00'],
            ] as [$description, $approvalDate]) {
                DB::table('cuentasporcobrar')->insert([
                    'cliente_idcliente' => 'CLIENT-1',
                    'tipoCobro_idtipoCobros' => 1,
                    'moneda_idmoneda' => 1,
                    'descripcion' => $description,
                    'montoOriginal' => 45,
                    'montoActual' => 45,
                    'fechaRegistro' => $approvalDate,
                    'estado' => 'NUEVO',
                ]);
            }

            $lastAllowedDayResult = (new CotizacionService())->anularCotizaciones(['C005']);
            $this->assertTrue($lastAllowedDayResult['success']);
            $this->assertSame(CotizacionService::STATE_ANULADO, DB::table('cotizacion')->where('nroCotizacion', 'C005')->value('estado'));

            Carbon::setTestNow('2026-10-19 12:00:00');
            $expiredResult = (new CotizacionService())->anularCotizaciones(['C006']);
            $this->assertFalse($expiredResult['success']);
            $this->assertSame(CotizacionService::STATE_APROBADO, DB::table('cotizacion')->where('nroCotizacion', 'C006')->value('estado'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_anula_cotizacion_aprobada_revirtiendo_movimiento_bancario_y_cxc(): void
    {
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'C003',
            'batch_id' => null,
            'estado' => CotizacionService::STATE_APROBADO,
        ]);
        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:C003',
            'montoOriginal' => 45,
            'montoActual' => 0,
            'fechaRegistro' => now()->format('Y-m-d H:i:s'),
            'estado' => '3',
            'docReferencia' => null,
            'docfactura' => null,
        ]);
        $bankId = DB::table('bancos')->insertGetId([
            'informacion' => 'Pago de cotización C003',
            'tipoMov' => 'I',
            'monto' => 45,
            'entidadBancaria_identidadBancaria' => 1,
        ]);
        DB::table('detallecxc')->insert([
            'formaPago_idformaPago' => 1,
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'moneda_idmoneda' => 1,
            'fechaPago' => now()->format('Y-m-d H:i:s'),
            'bancos_idbancos' => $bankId,
            'estado' => '1',
        ]);

        $result = (new CotizacionService)->anularCotizaciones(['C003']);

        $this->assertTrue($result['success']);
        $this->assertSame(CotizacionService::STATE_ANULADO, DB::table('cotizacion')->where('nroCotizacion', 'C003')->value('estado'));
        $this->assertSame('ANULADO', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('montoActual'));
        $this->assertSame('0', DB::table('detallecxc')->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)->value('estado'));
        $this->assertStringStartsWith('[ANULADO]', DB::table('bancos')->where('idbancos', $bankId)->value('informacion'));
    }

    public function test_conserva_gestion_mientras_un_grupo_tenga_cotizaciones_activas(): void
    {
        $operationId = DB::table('tipooperacion')->insertGetId([
            'nomenclatura' => 'CT',
            'detalle' => 'Cotización',
        ]);
        DB::table('cotizacion')->insert([
            ['nroCotizacion' => 'C001', 'batch_id' => 'GRUPO-1', 'estado' => CotizacionService::STATE_APROBADO_SP],
            ['nroCotizacion' => 'C002', 'batch_id' => 'GRUPO-1', 'estado' => CotizacionService::STATE_APROBADO_SP],
        ]);
        DB::table('ticket')->insert([
            'pedidoReferencia' => 'GRUPO-1',
            'tipoOperacion_idtipoOperacion' => $operationId,
            'estado' => 'Activo',
        ]);

        $result = (new CotizacionService)->anularCotizaciones(['C001']);

        $this->assertTrue($result['success']);
        $this->assertSame('Activo', DB::table('ticket')->value('estado'));
        $this->assertSame(CotizacionService::STATE_APROBADO_SP, DB::table('cotizacion')->where('nroCotizacion', 'C002')->value('estado'));
    }
}
