<?php

namespace Tests\Unit;

use App\Services\CxcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CxcCreditNoteAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('nota_credito_aplicacion');
        Schema::dropIfExists('nota_credito');
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('nota_credito_aplicacion');
        Schema::dropIfExists('nota_credito');

        parent::tearDown();
    }

    public function test_available_notes_return_only_remaining_amount_for_client_and_currency(): void
    {
        DB::table('nota_credito')->insert([
            [
                'idnotacredito' => 1,
                'cliente_idcliente' => 'CLIENT-1',
                'moneda_idmoneda' => 1,
                'docReferencia' => 'NC-001',
                'monto' => 100,
                'descripcion' => 'Ajuste',
                'fechaEmision' => '2026-10-01 10:00:00',
                'estado' => 'E',
            ],
            [
                'idnotacredito' => 2,
                'cliente_idcliente' => 'CLIENT-1',
                'moneda_idmoneda' => 2,
                'docReferencia' => 'NC-002',
                'monto' => 80,
                'descripcion' => 'Otra moneda',
                'fechaEmision' => '2026-10-02 10:00:00',
                'estado' => 'E',
            ],
            [
                'idnotacredito' => 3,
                'cliente_idcliente' => 'CLIENT-2',
                'moneda_idmoneda' => 1,
                'docReferencia' => 'NC-003',
                'monto' => 70,
                'descripcion' => 'Otro cliente',
                'fechaEmision' => '2026-10-03 10:00:00',
                'estado' => 'E',
            ],
            [
                'idnotacredito' => 4,
                'cliente_idcliente' => 'CLIENT-1',
                'moneda_idmoneda' => 1,
                'docReferencia' => 'NC-004',
                'monto' => 50,
                'descripcion' => 'Anulada',
                'fechaEmision' => '2026-10-04 10:00:00',
                'estado' => 'A',
            ],
        ]);
        DB::table('nota_credito_aplicacion')->insert([
            [
                'nota_credito_idnotacredito' => 1,
                'cuentasPorCobrar_idcuentasPorCobrar' => 10,
                'monto' => 25,
                'fechaAplicacion' => '2026-10-05 10:00:00',
                'estado' => '1',
            ],
            [
                'nota_credito_idnotacredito' => 1,
                'cuentasPorCobrar_idcuentasPorCobrar' => 11,
                'monto' => 15,
                'fechaAplicacion' => '2026-10-05 10:00:00',
                'estado' => '0',
            ],
        ]);

        $notes = (new CxcService())->notasCreditoDisponibles('CLIENT-1', 1);

        $this->assertCount(1, $notes);
        $this->assertSame(1, $notes[0]['idnotacredito']);
        $this->assertSame('NC-001', $notes[0]['docReferencia']);
        $this->assertSame(75.0, $notes[0]['monto_disponible']);
    }
}
