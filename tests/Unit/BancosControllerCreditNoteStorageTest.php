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
        Schema::create('nota_credito_aplicacion', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('nota_credito_idnotacredito');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->decimal('monto', 20, 2);
            $table->dateTime('fechaAplicacion');
            $table->string('estado', 1);
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

    public function test_credit_note_listing_loads_with_the_credit_note_tables(): void
    {
        $view = (new BancosController(new BancosService()))
            ->notaCreditos(Request::create('/modulos/finanzas/nota-creditos'));

        $this->assertSame('Nota de créditos', $view->getData()['title']);
        $this->assertSame(0, $view->getData()['items']->total());
    }

    public function test_credit_note_statistics_follow_search_and_filters(): void
    {
        DB::table('moneda')->where('idmoneda', 1)->update(['simbolo' => 'S/']);
        DB::table('moneda')->insert(['idmoneda' => 2, 'simbolo' => '$']);
        $this->createCreditNote();
        DB::table('nota_credito')->insert([
            [
                'cliente_idcliente' => 'CLIENT-1',
                'moneda_idmoneda' => 1,
                'docReferencia' => 'F00-222',
                'monto' => 25,
                'descripcion' => 'Otra nota en soles',
                'fechaEmision' => now(),
                'estado' => 'E',
            ],
            [
                'cliente_idcliente' => 'CLIENT-1',
                'moneda_idmoneda' => 2,
                'docReferencia' => 'F00-333',
                'monto' => 50,
                'descripcion' => 'Nota en dólares',
                'fechaEmision' => now(),
                'estado' => 'E',
            ],
        ]);
        $controller = new BancosController(new BancosService());

        $searchView = $controller->notaCreditos(
            Request::create('/modulos/finanzas/nota-creditos?q=F00-111')
        );
        $this->assertSame(1, $searchView->getData()['items']->total());
        $this->assertSame('S/ 100.00', $searchView->getData()['stats'][0]['value']);
        $this->assertSame('$ 0.00', $searchView->getData()['stats'][1]['value']);

        $filterView = $controller->notaCreditos(Request::create(
            '/modulos/finanzas/nota-creditos',
            'GET',
            ['moneda' => '$', 'montoDesde' => 50, 'montoHasta' => 50]
        ));
        $this->assertSame(1, $filterView->getData()['items']->total());
        $this->assertSame('S/ 0.00', $filterView->getData()['stats'][0]['value']);
        $this->assertSame('$ 50.00', $filterView->getData()['stats'][1]['value']);
    }

    public function test_credit_note_can_be_edited_when_it_has_no_applications(): void
    {
        $id = $this->createCreditNote();
        $this->attachSession(Request::create('/'));
        $controller = new BancosController(new BancosService());
        $view = $controller->editNotaCredito($id);

        $this->assertSame(route('modules.finanzas.nota-creditos.update', ['id' => $id]), $view->getData()['formAction']);
        $this->assertTrue($view->getData()['readOnly']);
        $this->assertSame('finanzas.nota_creditos', $view->getData()['lockResource']);
        $this->assertSame((string) $id, $view->getData()['lockId']);

        $request = Request::create('/modulos/finanzas/nota-creditos/' . $id, 'PUT', [
            'cliente_idcliente' => 'CLIENT-1',
            'docReferencia' => 'F00-222',
            'descripcion' => 'Actualizada',
            'moneda_idmoneda' => 1,
            'monto' => '125.00',
        ]);
        $this->attachSession($request);
        $response = $controller->updateNotaCredito($request, $id);

        $note = DB::table('nota_credito')->where('idnotacredito', $id)->first();
        $this->assertSame('F00-222', $note->docReferencia);
        $this->assertSame('Actualizada', $note->descripcion);
        $this->assertSame(125.0, (float) $note->monto);
        $this->assertSame(route('modules.finanzas.nota-creditos.index'), $response->getTargetUrl());
    }

    public function test_credit_note_with_application_cannot_be_edited_or_deleted(): void
    {
        $id = $this->createCreditNote();
        $this->attachSession(Request::create('/'));
        DB::table('nota_credito_aplicacion')->insert([
            'nota_credito_idnotacredito' => $id,
            'cuentasPorCobrar_idcuentasPorCobrar' => 1,
            'monto' => 10,
            'fechaAplicacion' => now(),
            'estado' => '1',
        ]);
        $controller = new BancosController(new BancosService());

        $editResponse = $controller->editNotaCredito($id);
        $updateRequest = Request::create('/modulos/finanzas/nota-creditos/' . $id, 'PUT', [
            'cliente_idcliente' => 'CLIENT-1',
            'docReferencia' => 'F00-222',
            'descripcion' => 'No debe actualizarse',
            'moneda_idmoneda' => 1,
            'monto' => '125.00',
        ]);
        $this->attachSession($updateRequest);
        $updateResponse = $controller->updateNotaCredito($updateRequest, $id);
        $deleteRequest = Request::create('/modulos/finanzas/nota-creditos/' . $id, 'DELETE');
        $this->attachSession($deleteRequest);
        $deleteResponse = $controller->destroyNotaCredito($deleteRequest, $id);

        $expectedPath = parse_url(route('modules.finanzas.nota-creditos.index'), PHP_URL_PATH);
        $this->assertSame($expectedPath, parse_url($editResponse->getTargetUrl(), PHP_URL_PATH));
        $this->assertSame($expectedPath, parse_url($updateResponse->getTargetUrl(), PHP_URL_PATH));
        $this->assertSame($expectedPath, parse_url($deleteResponse->getTargetUrl(), PHP_URL_PATH));
        $this->assertDatabaseHas('nota_credito', ['idnotacredito' => $id]);
        $this->assertDatabaseHas('nota_credito', [
            'idnotacredito' => $id,
            'docReferencia' => 'F00-111',
            'descripcion' => 'Saldo a favor',
            'monto' => 100,
        ]);
    }

    public function test_credit_note_without_applications_can_be_deleted(): void
    {
        $id = $this->createCreditNote();
        $request = Request::create('/modulos/finanzas/nota-creditos/' . $id, 'DELETE');
        $this->attachSession($request);
        $response = (new BancosController(new BancosService()))->destroyNotaCredito($request, $id);

        $this->assertSame(route('modules.finanzas.nota-creditos.index'), $response->getTargetUrl());
        $this->assertDatabaseMissing('nota_credito', ['idnotacredito' => $id]);
    }

    private function createCreditNote(): int
    {
        return (int) DB::table('nota_credito')->insertGetId([
            'cliente_idcliente' => 'CLIENT-1',
            'moneda_idmoneda' => 1,
            'docReferencia' => 'F00-111',
            'monto' => 100,
            'descripcion' => 'Saldo a favor',
            'fechaEmision' => now(),
            'estado' => 'E',
        ]);
    }

    private function attachSession(Request $request): void
    {
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);
    }

    protected function tearDown(): void
    {
        foreach (['nota_credito_aplicacion', 'nota_credito', 'moneda', 'cliente'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }
}
