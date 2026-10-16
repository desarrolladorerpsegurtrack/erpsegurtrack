<?php

namespace Tests\Unit;

use App\Http\Controllers\BancosController;
use App\Services\BancosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BancosControllerCreditNoteStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente', 20)->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle')->nullable();
            $table->string('simbolo')->nullable();
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
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar')->nullable();
            $table->dateTime('fechaAplicacion')->nullable();
        });
        DB::table('cliente')->insert(['idcliente' => 'CLIENT-1']);
        DB::table('moneda')->insert(['idmoneda' => 1]);
    }

    public function test_credit_note_creation_writes_the_credit_note_table_without_a_bank_movement(): void
    {
        $request = Request::create('/modulos/finanzas/nota-creditos', 'POST', [
            'cliente_idcliente' => 'CLIENT-1',
            'docReferencia' => 'F00-111',
            'descripcion' => 'Saldo a favor',
            'moneda_idmoneda' => 1,
            'monto' => '100.00',
        ]);

        $response = (new BancosController(new BancosService()))->storeNotaCredito($request);

        $note = DB::table('nota_credito')->first();
        $this->assertSame('CLIENT-1', $note->cliente_idcliente);
        $this->assertSame('F00-111', $note->docReferencia);
        $this->assertSame('Saldo a favor', $note->descripcion);
        $this->assertSame(100.0, (float) $note->monto);
        $this->assertSame('E', $note->estado);
        $this->assertNull($note->cuentasPorCobrar_idcuentasPorCobrar);
        $this->assertFalse(Schema::hasTable('bancos'));
        $this->assertSame(route('modules.finanzas.nota-creditos.index'), $response->getTargetUrl());
    }

    public function test_credit_note_form_uses_its_own_route_without_bank_query_parameters(): void
    {
        $view = (new BancosController(new BancosService()))->createNotaCredito();

        $this->assertSame(route('modules.finanzas.nota-creditos.store'), $view->getData()['formAction']);
        $this->assertStringNotContainsString('/bancos', $view->getData()['formAction']);
    }

    protected function tearDown(): void
    {
        foreach (['nota_credito', 'moneda', 'cliente'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }
}
