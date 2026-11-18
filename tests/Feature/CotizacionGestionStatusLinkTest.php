<?php

namespace Tests\Feature;

use App\Http\Controllers\CotizacionController;
use App\Services\CotizacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CotizacionGestionStatusLinkTest extends TestCase
{
    private int $quotationOperationId;

    private int $vista1RuleId;

    private int $newOrderOperationId;

    private int $newOrderVista1RuleId;

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
            $table->string('cliente_idcliente');
            $table->unsignedInteger('moneda_idmoneda');
            $table->unsignedInteger('vigenciaOferta_idvigenciaOferta');
            $table->unsignedInteger('formaPago_idformaPago');
            $table->string('personal_dniPersonal')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('igv', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->dateTime('fechaHoraEmision')->nullable();
            $table->string('estado');
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle');
        });
        Schema::create('vigenciaoferta', function (Blueprint $table): void {
            $table->increments('idvigenciaOferta');
            $table->string('detalle');
            $table->unsignedInteger('dias')->default(0);
        });
        Schema::create('formapago', function (Blueprint $table): void {
            $table->increments('idformaPago');
            $table->string('detalle');
            $table->unsignedInteger('tiempo')->default(0);
        });
        Schema::create('personal', function (Blueprint $table): void {
            $table->string('dniPersonal')->primary();
            $table->string('nombre')->nullable();
            $table->string('apellido')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('descripcion');
            $table->dateTime('fechaRegistro')->nullable();
        });
        Schema::create('tipooperacion', function (Blueprint $table): void {
            $table->increments('idtipoOperacion');
            $table->string('nomenclatura')->nullable();
            $table->string('detalle')->nullable();
        });
        Schema::create('flujo', function (Blueprint $table): void {
            $table->increments('idflujo');
            $table->unsignedInteger('tipoOperacion_idtipoOperacion');
        });
        Schema::create('flujoregla', function (Blueprint $table): void {
            $table->increments('idflujoregla');
            $table->unsignedInteger('flujo_idflujo');
            $table->unsignedInteger('vista_idvista');
            $table->unsignedInteger('orden');
            $table->string('estado');
        });
        Schema::create('ticket', function (Blueprint $table): void {
            $table->increments('idticket');
            $table->string('pedidoReferencia');
            $table->unsignedInteger('tipoOperacion_idtipoOperacion');
            $table->string('estado');
        });
        Schema::create('historialflujo', function (Blueprint $table): void {
            $table->increments('idhistorialflujo');
            $table->unsignedInteger('ticket_idticket');
            $table->unsignedInteger('flujoregla_idflujoregla');
            $table->string('resultado');
        });

        DB::table('cliente')->insert([
            'idcliente' => 'CLIENT-1',
            'razonSocial' => 'Cliente de prueba',
        ]);
        DB::table('moneda')->insert([
            'detalle' => 'Soles',
        ]);
        DB::table('vigenciaoferta')->insert([
            'detalle' => 'Vigencia de prueba',
        ]);
        DB::table('formapago')->insert([
            'detalle' => 'Contado',
        ]);
        $this->quotationOperationId = (int) DB::table('tipooperacion')->insertGetId([
            'nomenclatura' => 'CT',
            'detalle' => 'Cotización',
        ]);
        $flowId = DB::table('flujo')->insertGetId([
            'tipoOperacion_idtipoOperacion' => $this->quotationOperationId,
        ]);
        $this->vista1RuleId = (int) DB::table('flujoregla')->insertGetId([
            'flujo_idflujo' => $flowId,
            'vista_idvista' => 1,
            'orden' => 1,
            'estado' => '1',
        ]);

        $this->newOrderOperationId = (int) DB::table('tipooperacion')->insertGetId([
            'nomenclatura' => 'NP',
            'detalle' => 'Nuevo Pedido',
        ]);
        $newOrderFlowId = DB::table('flujo')->insertGetId([
            'tipoOperacion_idtipoOperacion' => $this->newOrderOperationId,
        ]);
        $this->newOrderVista1RuleId = (int) DB::table('flujoregla')->insertGetId([
            'flujo_idflujo' => $newOrderFlowId,
            'vista_idvista' => 1,
            'orden' => 1,
            'estado' => '1',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'historialflujo',
            'ticket',
            'flujoregla',
            'flujo',
            'tipooperacion',
            'cuentasporcobrar',
            'personal',
            'formapago',
            'vigenciaoferta',
            'moneda',
            'cliente',
            'cotizacion',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_grouped_approved_quotes_link_to_the_shared_vista_one_management(): void
    {
        $this->createQuote('CT-001', 'GRP-307', CotizacionService::STATE_APROBADO_SP);
        $this->createQuote('CT-002', 'GRP-307', CotizacionService::STATE_APROBADO_SP);
        $ticketId = $this->createTicket('GRP-307', 'creado');

        $rows = $this->indexRows($this->authorizedUser());
        $expectedUrl = route('modules.tickets.show.vista', ['ticketId' => $ticketId, 'vistaId' => 1]);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringContainsString('href="'.$expectedUrl.'"', $row->estado_label);
            $this->assertStringContainsString('Aprobado(SP)', $row->estado_label);
        }
    }

    public function test_grouped_approved_quotes_link_when_management_uses_another_operation_type(): void
    {
        $this->createQuote('CT-006', 'GRP-308', CotizacionService::STATE_APROBADO_SP);
        $ticketId = $this->createTicket('GRP-308', 'pendiente', $this->newOrderOperationId, $this->newOrderVista1RuleId);

        $row = $this->indexRows($this->authorizedUser())->first();
        $expectedUrl = route('modules.tickets.show.vista', ['ticketId' => $ticketId, 'vistaId' => 1]);

        $this->assertNotNull($row);
        $this->assertStringContainsString('href="'.$expectedUrl.'"', $row->estado_label);
    }

    public function test_individual_approved_quote_links_to_its_vista_one_management(): void
    {
        $this->createQuote('CT-005', 'IND-005', CotizacionService::STATE_APROBADO);
        $ticketId = $this->createTicket('IND-005', 'creado');

        $row = $this->indexRows($this->authorizedUser())->first();
        $expectedUrl = route('modules.tickets.show.vista', ['ticketId' => $ticketId, 'vistaId' => 1]);

        $this->assertNotNull($row);
        $this->assertStringContainsString('href="'.$expectedUrl.'"', $row->estado_label);
        $this->assertStringContainsString('Aprobado', $row->estado_label);
    }

    public function test_approved_status_is_plain_text_after_vista_one_is_completed(): void
    {
        $this->createQuote('CT-003', 'IND-003', CotizacionService::STATE_APROBADO);
        $this->createTicket('IND-003', 'completado', $this->newOrderOperationId, $this->newOrderVista1RuleId);

        $row = $this->indexRows($this->authorizedUser())->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('<a ', $row->estado_label);
        $this->assertStringContainsString('Aprobado', $row->estado_label);
    }

    public function test_approved_status_is_plain_text_without_ticket_or_vista_one_permission(): void
    {
        $this->createQuote('CT-004', 'IND-004', CotizacionService::STATE_APROBADO_SP);

        $withoutTicketPermission = $this->indexRows([
            'permissions' => ['ventas.personal' => ['ver']],
            'allowed_vistas' => [1],
        ])->first();
        $withoutVista1Permission = $this->indexRows([
            'permissions' => ['ventas.personal' => ['ver'], 'tickets' => ['ver']],
            'allowed_vistas' => [2],
        ])->first();

        $this->assertNotNull($withoutTicketPermission);
        $this->assertNotNull($withoutVista1Permission);
        $this->assertStringNotContainsString('<a ', $withoutTicketPermission->estado_label);
        $this->assertStringNotContainsString('<a ', $withoutVista1Permission->estado_label);
    }

    private function createQuote(string $quoteId, string $reference, string $state): void
    {
        DB::table('cotizacion')->insert([
            'nroCotizacion' => $quoteId,
            'batch_id' => $reference,
            'cliente_idcliente' => 'CLIENT-1',
            'moneda_idmoneda' => 1,
            'vigenciaOferta_idvigenciaOferta' => 1,
            'formaPago_idformaPago' => 1,
            'subtotal' => 100,
            'total' => 118,
            'fechaHoraEmision' => now()->format('Y-m-d H:i:s'),
            'estado' => $state,
        ]);
    }

    private function createTicket(string $reference, string $vista1Result, ?int $operationId = null, ?int $vista1RuleId = null): int
    {
        $ticketId = (int) DB::table('ticket')->insertGetId([
            'pedidoReferencia' => $reference,
            'tipoOperacion_idtipoOperacion' => $operationId ?? $this->quotationOperationId,
            'estado' => 'Pendiente',
        ]);
        DB::table('historialflujo')->insert([
            'ticket_idticket' => $ticketId,
            'flujoregla_idflujoregla' => $vista1RuleId ?? $this->vista1RuleId,
            'resultado' => $vista1Result,
        ]);

        return $ticketId;
    }

    private function authorizedUser(): array
    {
        return [
            'usuario' => 'test-user',
            'permissions' => [
                'ventas.personal' => ['ver'],
                'tickets' => ['ver'],
            ],
            'allowed_vistas' => [1],
        ];
    }

    private function indexRows(array $authData)
    {
        $session = app('session')->driver();
        $session->start();
        $session->flush();
        $session->put('erp_auth', $authData);

        $request = Request::create('/modulos/ventas/cotizaciones', 'GET');
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        $view = app(CotizacionController::class)->index($request);

        return $view->getData()['items']->getCollection();
    }
}
