<?php

namespace Tests\Feature;

use App\Services\VehicleComponentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VehicleComponentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('almacen', function (Blueprint $table): void {
            $table->integer('idalmacen')->primary();
            $table->integer('tipoElemento_idtipoElemento');
            $table->string('detalle')->nullable();
            $table->string('usaRedMovil')->nullable();
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->integer('idtipoElemento')->primary();
            $table->string('nombre');
        });
        Schema::create('elementoalmacen', function (Blueprint $table): void {
            $table->string('imei')->primary();
            $table->integer('dispositivo_iddispositivo');
        });
        Schema::create('dispositivocliente', function (Blueprint $table): void {
            $table->string('iddispositivoCliente')->primary();
            $table->string('vehiculo_placa')->nullable();
            $table->string('estado');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dispositivocliente');
        Schema::dropIfExists('elementoalmacen');
        Schema::dropIfExists('tipoelemento');
        Schema::dropIfExists('almacen');

        parent::tearDown();
    }

    public function test_it_reads_only_active_sensors_and_accessories_already_linked_to_the_vehicle(): void
    {
        DB::table('tipoelemento')->insert([
            ['idtipoElemento' => 1, 'nombre' => 'Sensor'],
            ['idtipoElemento' => 2, 'nombre' => 'Accesorio'],
            ['idtipoElemento' => 3, 'nombre' => 'Equipo'],
        ]);
        DB::table('almacen')->insert([
            ['idalmacen' => 501, 'tipoElemento_idtipoElemento' => 1, 'detalle' => 'Sensor de combustible', 'usaRedMovil' => 'N'],
            ['idalmacen' => 502, 'tipoElemento_idtipoElemento' => 2, 'detalle' => 'Botón de pánico', 'usaRedMovil' => 'S'],
            ['idalmacen' => 503, 'tipoElemento_idtipoElemento' => 3, 'detalle' => 'GPS principal', 'usaRedMovil' => 'S'],
            ['idalmacen' => 504, 'tipoElemento_idtipoElemento' => 1, 'detalle' => 'Sensor dado de baja', 'usaRedMovil' => 'N'],
        ]);
        DB::table('elementoalmacen')->insert([
            ['imei' => 'SENSOR-IMEI', 'dispositivo_iddispositivo' => 501],
            ['imei' => 'ACCESSORY-IMEI', 'dispositivo_iddispositivo' => 502],
            ['imei' => 'GPS-IMEI', 'dispositivo_iddispositivo' => 503],
            ['imei' => 'INACTIVE-IMEI', 'dispositivo_iddispositivo' => 504],
        ]);
        DB::table('dispositivocliente')->insert([
            ['iddispositivoCliente' => 'SENSOR-IMEI', 'vehiculo_placa' => 'ABC-123', 'estado' => '1'],
            ['iddispositivoCliente' => 'ACCESSORY-IMEI', 'vehiculo_placa' => 'ABC-123', 'estado' => '1'],
            ['iddispositivoCliente' => 'GPS-IMEI', 'vehiculo_placa' => 'ABC-123', 'estado' => '1'],
            ['iddispositivoCliente' => 'INACTIVE-IMEI', 'vehiculo_placa' => 'ABC-123', 'estado' => '0'],
            ['iddispositivoCliente' => 'OTHER-IMEI', 'vehiculo_placa' => 'XYZ-987', 'estado' => '1'],
        ]);

        $components = app(VehicleComponentService::class)->forVehicle('ABC-123');
        $html = view('vehiculo.componentes', [
            'componentesVehiculo' => $components,
            'componentesTitle' => 'Accesorios',
        ])->render();

        $this->assertCount(2, $components);
        $this->assertSame('SENSOR-IMEI', $components[0]->imei);
        $this->assertSame('Sensor de combustible', $components[0]->producto);
        $this->assertSame('Sensor', $components[0]->categoria);
        $this->assertSame('No', $components[0]->usa_red_movil_label);
        $this->assertSame('ACCESSORY-IMEI', $components[1]->imei);
        $this->assertSame('Botón de pánico', $components[1]->producto);
        $this->assertSame('Accesorio', $components[1]->categoria);
        $this->assertSame('Sí', $components[1]->usa_red_movil_label);
        $this->assertStringContainsString('ACCESSORY-IMEI', $html);
        $this->assertFalse(Schema::hasTable('vehiculo_componente'));
    }

    public function test_component_table_is_hidden_when_the_vehicle_has_no_components(): void
    {
        $html = view('vehiculo.componentes', [
            'componentesVehiculo' => collect(),
            'componentesTitle' => 'Accesorios',
        ])->render();

        $this->assertSame('', trim($html));
    }
}
