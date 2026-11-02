<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CxcListingPerformanceIndexesMigrationTest extends TestCase
{
    public function test_performance_indexes_are_added_only_when_required_columns_exist(): void
    {
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->string('cliente_idcliente');
            $table->string('estado');
            $table->date('fechaCancelacion')->nullable();
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->string('cliente_idcliente');
            $table->date('fecheVencimiento')->nullable();
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado');
            $table->dateTime('fechaPago')->nullable();
        });
        Schema::create('nota_credito_aplicacion', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado');
            $table->dateTime('fechaAplicacion')->nullable();
        });
        Schema::create('detalle_serviciodispositivo', function (Blueprint $table): void {
            $table->integer('servicioCliente_idservicioCliente');
            $table->integer('iddetalle_serviciodispositivo');
        });
        Schema::create('detnumerosdispositivo', function (Blueprint $table): void {
            $table->integer('dispositivoCliente_iddispositivoCliente');
            $table->integer('iddetNumerosDispositivo');
        });

        $migration = require base_path('database/migrations/2026_10_21_140000_add_cxc_listing_performance_indexes.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('cuentasporcobrar', 'cxc_perf_client_state_due_idx'));
        $this->assertTrue(Schema::hasIndex('serviciocliente', 'cxc_perf_service_client_due_idx'));
        $this->assertTrue(Schema::hasIndex('detallecxc', 'cxc_perf_payment_date_idx'));
        $this->assertTrue(Schema::hasIndex('nota_credito_aplicacion', 'cxc_perf_credit_note_date_idx'));
        $this->assertTrue(Schema::hasIndex('detalle_serviciodispositivo', 'cxc_perf_service_device_idx'));
        $this->assertTrue(Schema::hasIndex('detnumerosdispositivo', 'cxc_perf_device_number_idx'));

        $migration->down();

        $this->assertFalse(Schema::hasIndex('cuentasporcobrar', 'cxc_perf_client_state_due_idx'));
        $this->assertFalse(Schema::hasIndex('serviciocliente', 'cxc_perf_service_client_due_idx'));
        $this->assertFalse(Schema::hasIndex('detallecxc', 'cxc_perf_payment_date_idx'));
        $this->assertFalse(Schema::hasIndex('nota_credito_aplicacion', 'cxc_perf_credit_note_date_idx'));
        $this->assertFalse(Schema::hasIndex('detalle_serviciodispositivo', 'cxc_perf_service_device_idx'));
        $this->assertFalse(Schema::hasIndex('detnumerosdispositivo', 'cxc_perf_device_number_idx'));
    }
}
