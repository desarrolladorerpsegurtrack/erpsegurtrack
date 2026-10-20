<?php

namespace Tests\Unit;

use App\Services\BancosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BancosMovementStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('entidadbancaria', function (Blueprint $table): void {
            $table->increments('identidadBancaria');
            $table->string('razonSocial')->nullable();
            $table->string('descripcion')->nullable();
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('simbolo')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->decimal('montoActual', 10, 2)->nullable();
            $table->string('estado')->nullable();
            $table->string('descripcion')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->integer('entidadBancaria_identidadBancaria')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->string('informacion');
            $table->decimal('monto', 10, 2);
            $table->string('tipoMov');
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos');
            $table->integer('moneda_idmoneda');
            $table->string('estado');
        });
        DB::table('entidadbancaria')->insert([
            'identidadBancaria' => 1,
            'razonSocial' => 'Banco de prueba',
        ]);
        DB::table('cliente')->insert([
            'idcliente' => 'CLIENT-1',
            'razonSocial' => 'Cliente de prueba',
        ]);
        DB::table('moneda')->insert([
            'idmoneda' => 1,
            'simbolo' => 'S/',
        ]);
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 1,
            'cliente_idcliente' => 'CLIENT-1',
            'montoActual' => 0,
            'estado' => 'ANULADO',
            'descripcion' => 'COTIZACION:CT001',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['detallecxc', 'bancos', 'cuentasporcobrar', 'moneda', 'cliente', 'entidadbancaria'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_annulled_autodetraccion_is_filtered_and_not_double_counted_with_its_payment(): void
    {
        $movementIds = [];
        foreach ([
            ['informacion' => '[ANULADO] Anulación de cotización CT001 | Pago de cotización CT001', 'monto' => 801.60, 'tipoMov' => 'I'],
            ['informacion' => '[ANULADO] Anulación de cotización CT001 | Autodetracción pagada de CT001', 'monto' => 96, 'tipoMov' => 'T'],
        ] as $movement) {
            $movementIds[] = DB::table('bancos')->insertGetId($movement + [
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-19 12:00:00',
            ]);
        }
        foreach ($movementIds as $movementId) {
            DB::table('detallecxc')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'bancos_idbancos' => $movementId,
                'moneda_idmoneda' => 1,
                'estado' => '0',
            ]);
        }

        $service = new BancosService();
        $stats = $service->getStats(Request::create('/bancos'));
        $this->assertSame(0.0, $stats['balances']['S/']['balance_positivo']);
        $this->assertSame(0.0, $stats['balances']['S/']['balance_negativo']);
        $this->assertSame(801.6, $stats['balances']['S/']['anulado_revertido']);

        $applyFilters = new \ReflectionMethod($service, 'applyFilters');
        $annulledQuery = DB::table('bancos as b');
        $applyFilters->invoke($service, $annulledQuery, Request::create('/bancos?tipoMov=ANULADO'));
        $this->assertSame($movementIds, array_map('intval', $annulledQuery->pluck('b.idbancos')->all()));

        $autoQuery = DB::table('bancos as b');
        $applyFilters->invoke($service, $autoQuery, Request::create('/bancos?tipoMov=AUTODETRACCION'));
        $this->assertSame([$movementIds[1]], array_map('intval', $autoQuery->pluck('b.idbancos')->all()));
    }

    public function test_active_autodetraccion_is_a_negative_balance_and_autoretencion_is_included(): void
    {
        foreach ([
            ['informacion' => 'Pago de cotización CT002', 'monto' => 801.60, 'tipoMov' => 'I'],
            ['informacion' => 'Autodetracción pagada de CT002', 'monto' => 96, 'tipoMov' => 'T'],
            ['informacion' => 'Autoretención pagada de CT003', 'monto' => 30, 'tipoMov' => 'T'],
        ] as $movement) {
            $movementId = DB::table('bancos')->insertGetId($movement + [
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-19 12:00:00',
            ]);
            DB::table('detallecxc')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'bancos_idbancos' => $movementId,
                'moneda_idmoneda' => 1,
                'estado' => '1',
            ]);
        }

        $stats = (new BancosService())->getStats(Request::create('/bancos'));
        $this->assertSame(801.6, $stats['balances']['S/']['balance_positivo']);
        $this->assertSame(126.0, $stats['balances']['S/']['balance_negativo']);
        $this->assertSame(0.0, $stats['balances']['S/']['anulado_revertido']);
    }
}
