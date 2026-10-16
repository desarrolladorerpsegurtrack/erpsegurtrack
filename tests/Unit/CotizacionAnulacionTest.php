<?php

namespace Tests\Unit;

use App\Services\CotizacionService;
use Illuminate\Database\Schema\Blueprint;
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
    }

    protected function tearDown(): void
    {
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

        $result = (new CotizacionService())->anularCotizaciones(['C001', 'C002']);

        $this->assertTrue($result['success']);
        $this->assertSame(['C001', 'C002'], $result['quote_ids']);
        $this->assertSame([CotizacionService::STATE_ANULADO, CotizacionService::STATE_ANULADO], DB::table('cotizacion')->orderBy('nroCotizacion')->pluck('estado')->all());
        $this->assertSame([$ticketId], $result['ticket_ids']);
        $this->assertSame('Resuelto', DB::table('ticket')->value('estado'));
        $this->assertNotNull(DB::table('ticket')->value('fechaHoraCierre'));
    }

    public function test_no_anula_cotizaciones_aprobadas_con_pago(): void
    {
        DB::table('cotizacion')->insert([
            ['nroCotizacion' => 'C001', 'batch_id' => null, 'estado' => CotizacionService::STATE_APROBADO],
            ['nroCotizacion' => 'C002', 'batch_id' => null, 'estado' => CotizacionService::STATE_APROBADO_SP],
        ]);

        $result = (new CotizacionService())->anularCotizaciones(['C001', 'C002']);

        $this->assertFalse($result['success']);
        $this->assertSame(CotizacionService::STATE_APROBADO, DB::table('cotizacion')->where('nroCotizacion', 'C001')->value('estado'));
        $this->assertSame(CotizacionService::STATE_APROBADO_SP, DB::table('cotizacion')->where('nroCotizacion', 'C002')->value('estado'));
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

        $result = (new CotizacionService())->anularCotizaciones(['C001']);

        $this->assertTrue($result['success']);
        $this->assertSame('Activo', DB::table('ticket')->value('estado'));
        $this->assertSame(CotizacionService::STATE_APROBADO_SP, DB::table('cotizacion')->where('nroCotizacion', 'C002')->value('estado'));
    }
}
