<?php

namespace Tests\Unit;

use App\Services\BancosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EstadoCuentaCxcDetailsTest extends TestCase
{
    public function test_estado_cuenta_details_are_loaded_from_cxc_without_joining_services(): void
    {
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('simbolo')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion')->nullable();
            $table->string('docReferencia')->nullable();
            $table->decimal('montoOriginal', 10, 2);
            $table->decimal('montoActual', 10, 2);
            $table->string('estado');
            $table->date('fechaCancelacion')->nullable();
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('docReferencia')->nullable();
            $table->date('fechaInicio')->nullable();
            $table->date('fecheVencimiento')->nullable();
            $table->decimal('monto', 10, 2)->default(0);
            $table->string('vehiculo_placa')->nullable();
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
        });
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        DB::table('serviciocliente')->insert([
            [
                'idservicioCliente' => 41,
                'cliente_idcliente' => 'CLIENTE-1',
                'monto' => 150,
                'fechaInicio' => '2026-01-01',
                'fecheVencimiento' => '2026-02-01',
                'vehiculo_placa' => 'ABC123',
            ],
            [
                'idservicioCliente' => 42,
                'cliente_idcliente' => 'CLIENTE-1',
                'monto' => 75,
                'fechaInicio' => '2023-08-04',
                'fecheVencimiento' => '2024-08-03',
                'vehiculo_placa' => 'XYZ789',
            ],
        ]);
        DB::table('vehiculo')->insert([['placa' => 'ABC123'], ['placa' => 'XYZ789']]);
        DB::table('cuentasporcobrar')->insert([
            [
                'idcuentasPorCobrar' => 10,
                'cliente_idcliente' => 'CLIENTE-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Cobro por servicio ABC123 SC-41',
                'docReferencia' => null,
                'montoOriginal' => 150,
                'montoActual' => 100,
                'estado' => '4',
            ],
            [
                'idcuentasPorCobrar' => 11,
                'cliente_idcliente' => 'CLIENTE-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Cobro por servicio XYZ789',
                'docReferencia' => null,
                'montoOriginal' => 1350,
                'montoActual' => 1350,
                'estado' => '1',
            ],
            [
                'idcuentasPorCobrar' => 12,
                'cliente_idcliente' => 'CLIENTE-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Cobro por servicio ABC123',
                'docReferencia' => null,
                'montoOriginal' => 999,
                'montoActual' => 999,
                'estado' => '3',
            ],
            [
                'idcuentasPorCobrar' => 13,
                'cliente_idcliente' => 'CLIENTE-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Cargo nuevo no facturado',
                'docReferencia' => null,
                'montoOriginal' => 888,
                'montoActual' => 888,
                'estado' => 'NUEVO',
            ],
            [
                'idcuentasPorCobrar' => 14,
                'cliente_idcliente' => 'CLIENTE-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'CXC 04/08/2023 a 03/08/2024',
                'docReferencia' => null,
                'montoOriginal' => 320,
                'montoActual' => 320,
                'estado' => '4',
            ],
        ]);

        $details = (new BancosService())->getEstadoCuentaDetailsByClient(['CLIENTE-1']);

        $this->assertCount(3, $details['CLIENTE-1']);
        $this->assertSame(14, $details['CLIENTE-1'][0]->cxc_id);
        $this->assertSame('XYZ789', $details['CLIENTE-1'][0]->vehiculo);
        $this->assertSame('CXC 04/08/2023 a 03/08/2024', $details['CLIENTE-1'][0]->descripcion);
        $this->assertSame(320.0, $details['CLIENTE-1'][0]->monto);
        $this->assertSame(320.0, $details['CLIENTE-1'][0]->deuda_pendiente['S/']);
        $this->assertSame(11, $details['CLIENTE-1'][1]->cxc_id);
        $this->assertSame('XYZ789', $details['CLIENTE-1'][1]->vehiculo);
        $this->assertSame(1350.0, $details['CLIENTE-1'][1]->deuda_pendiente['S/']);
        $this->assertSame(10, $details['CLIENTE-1'][2]->cxc_id);
        $this->assertSame('ABC123', $details['CLIENTE-1'][2]->vehiculo);
        $this->assertSame('Cobro por servicio ABC123 SC-41', $details['CLIENTE-1'][2]->descripcion);
        $this->assertSame(100.0, $details['CLIENTE-1'][2]->deuda_pendiente['S/']);
    }
}
