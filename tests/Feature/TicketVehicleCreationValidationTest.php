<?php

namespace Tests\Feature;

use App\Http\Controllers\TicketsController;
use App\Services\TicketsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketVehicleCreationValidationTest extends TestCase
{
    public function test_plan_units_are_automatically_distributed_across_equipment(): void
    {
        $service = app(TicketsService::class);
        $method = new \ReflectionMethod(TicketsService::class, 'buildAutomaticPlanAssignments');
        $assignments = $method->invoke($service, [
            (object) ['iddetalleCotizacion' => 100, 'cantidad' => 1],
            (object) ['iddetalleCotizacion' => 101, 'cantidad' => 1],
        ], [
            (object) ['iddetalleCotizacion' => 201, 'cantidad' => 2],
            (object) ['iddetalleCotizacion' => 202, 'cantidad' => 2],
        ]);

        $this->assertSame([
            100 => [0 => ['201_1', '202_1']],
            101 => [0 => ['201_2', '202_2']],
        ], $assignments);
    }

    public function test_component_assignments_keep_sensor_and_accessory_units_with_their_equipment(): void
    {
        $service = app(TicketsService::class);
        $method = new \ReflectionMethod(TicketsService::class, 'buildComponentAssignments');
        $assignments = $method->invoke($service, [
            (object) ['iddetalleCotizacion' => 100, 'cantidad' => 1],
            (object) ['iddetalleCotizacion' => 101, 'cantidad' => 1],
        ], [
            (object) [
                'iddetalleCotizacion' => 201,
                'almacen_idalmacen' => 501,
                'cantidad' => 2,
                'producto' => 'Sensor de combustible',
                'tipo_nombre' => 'Sensor',
            ],
            (object) [
                'iddetalleCotizacion' => 202,
                'almacen_idalmacen' => 502,
                'cantidad' => 1,
                'producto' => 'Botón de pánico',
                'tipo_nombre' => 'Accesorio',
            ],
            (object) [
                'iddetalleCotizacion' => 203,
                'almacen_idalmacen' => 503,
                'cantidad' => 1,
                'producto' => 'Servicio GPS',
                'tipo_nombre' => 'Servicio',
            ],
        ], [
            100 => [0 => ['201_1', '202_1', '203_1']],
            101 => [0 => ['201_2']],
        ]);

        $this->assertSame('Sensor de combustible', $assignments['sensors'][0]['producto']);
        $this->assertSame('100', $assignments['sensors'][0]['equipmentDetailId']);
        $this->assertSame(0, $assignments['sensors'][0]['equipmentIndex']);
        $this->assertSame(1, $assignments['sensors'][0]['position']);
        $this->assertSame('101', $assignments['sensors'][1]['equipmentDetailId']);
        $this->assertSame(1, $assignments['sensors'][1]['position']);
        $this->assertSame('Botón de pánico', $assignments['accessories'][0]['producto']);
        $this->assertCount(2, $assignments['sensors']);
        $this->assertCount(1, $assignments['accessories']);
    }

    public function test_finishing_a_flow_links_selected_sensors_and_accessories_to_their_equipment_vehicles(): void
    {
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
        });
        Schema::create('marca', function (Blueprint $table): void {
            $table->integer('idmarca')->primary();
            $table->string('nombreMarca');
        });
        Schema::create('modelo', function (Blueprint $table): void {
            $table->integer('idmodelo')->primary();
            $table->integer('marca_idmarca')->nullable();
            $table->string('nombreModelo');
        });
        Schema::create('almacen', function (Blueprint $table): void {
            $table->integer('idalmacen')->primary();
            $table->integer('modelo_idmodelo')->nullable();
        });
        Schema::create('dispositivocliente', function (Blueprint $table): void {
            $table->string('iddispositivoCliente')->primary();
            $table->string('vehiculo_placa');
            $table->string('marcaDispositivo')->nullable();
            $table->string('modeloDispositivo')->nullable();
            $table->dateTime('fechaInstalacion')->nullable();
            $table->string('estado');
        });

        DB::table('vehiculo')->insert([
            ['placa' => 'ABC-123'],
            ['placa' => 'XYZ-987'],
        ]);
        DB::table('marca')->insert([
            ['idmarca' => 1, 'nombreMarca' => 'Marca sensor'],
            ['idmarca' => 2, 'nombreMarca' => 'Marca accesorio'],
        ]);
        DB::table('modelo')->insert([
            ['idmodelo' => 11, 'marca_idmarca' => 1, 'nombreModelo' => 'Modelo sensor'],
            ['idmodelo' => 12, 'marca_idmarca' => 2, 'nombreModelo' => 'Modelo accesorio'],
        ]);
        DB::table('almacen')->insert([
            ['idalmacen' => 501, 'modelo_idmodelo' => 11],
            ['idalmacen' => 502, 'modelo_idmodelo' => 12],
        ]);

        $service = app(TicketsService::class);
        $buildAssignments = new \ReflectionMethod(TicketsService::class, 'buildComponentAssignments');
        $components = $buildAssignments->invoke($service, [
            (object) ['iddetalleCotizacion' => 100, 'cantidad' => 1],
            (object) ['iddetalleCotizacion' => 101, 'cantidad' => 1],
        ], [
            (object) [
                'iddetalleCotizacion' => 201,
                'almacen_idalmacen' => 501,
                'producto' => 'Sensor combustible',
                'tipo_nombre' => 'Sensor',
            ],
            (object) [
                'iddetalleCotizacion' => 202,
                'almacen_idalmacen' => 502,
                'producto' => 'Botón de pánico',
                'tipo_nombre' => 'Accesorio',
            ],
        ], [
            100 => [0 => ['201_1']],
            101 => [0 => ['202_1']],
        ]);

        $persistDevices = new \ReflectionMethod(TicketsService::class, 'persistComponentDevices');
        $persistDevices->invoke($service, $components, [
            'component_imeis' => [
                201 => [1 => 'SENSOR-IMEI'],
                202 => [1 => 'ACCESSORY-IMEI'],
            ],
            'placas' => [
                100 => [0 => 'ABC-123'],
                101 => [0 => 'XYZ-987'],
            ],
        ]);

        $sensor = DB::table('dispositivocliente')->where('iddispositivoCliente', 'SENSOR-IMEI')->first();
        $accessory = DB::table('dispositivocliente')->where('iddispositivoCliente', 'ACCESSORY-IMEI')->first();

        $this->assertSame('ABC-123', $sensor->vehiculo_placa);
        $this->assertSame('Marca sensor', $sensor->marcaDispositivo);
        $this->assertSame('Modelo sensor', $sensor->modeloDispositivo);
        $this->assertSame('1', $sensor->estado);
        $this->assertSame('XYZ-987', $accessory->vehiculo_placa);
        $this->assertSame('Marca accesorio', $accessory->marcaDispositivo);
        $this->assertSame('Modelo accesorio', $accessory->modeloDispositivo);
        $this->assertSame('1', $accessory->estado);
        $this->assertFalse(Schema::hasTable('serviciocliente'));
        $this->assertFalse(Schema::hasTable('detalle_serviciodispositivo'));
    }

    public function test_assignment_validation_rejects_repeated_and_unavailable_values(): void
    {
        $controller = app(TicketsController::class);
        $method = new \ReflectionMethod(TicketsController::class, 'validateVista3Assignments');
        $result = $method->invoke($controller, [
            'equipamiento' => [
                (object) ['iddetalleCotizacion' => 100, 'availableImeis' => ['IMEI-1']],
                (object) ['iddetalleCotizacion' => 101, 'availableImeis' => ['IMEI-2']],
                (object) ['iddetalleCotizacion' => 102, 'availableImeis' => ['IMEI-3']],
            ],
            'vehiculosExistentes' => [(object) ['placa' => 'PLATE-1']],
            'numerosTelefonicos' => [(object) ['numeroTelefonico' => '5551000']],
        ], [
            100 => ['IMEI-1'],
            101 => ['IMEI-1'],
        ], [
            100 => ['PLATE-1'],
            101 => ['plate-1'],
        ], [
            100 => ['5551000'],
            101 => ['5551000'],
            102 => ['5552000'],
        ]);

        $messages = implode(' ', $result['messages']);
        $this->assertStringContainsString('IMEIs no están disponibles', $messages);
        $this->assertStringContainsString('mismo IMEI', $messages);
        $this->assertStringContainsString('misma placa', $messages);
        $this->assertStringContainsString('mismo número telefónico', $messages);
        $this->assertStringContainsString('estado Disponible', $messages);
    }

    public function test_component_imei_validation_rejects_unavailable_duplicate_and_missing_values(): void
    {
        $controller = app(TicketsController::class);
        $method = new \ReflectionMethod(TicketsController::class, 'validateVista3Assignments');
        $extraData = [
            'equipamiento' => [
                (object) [
                    'iddetalleCotizacion' => 100,
                    'cantidad' => 1,
                    'availableImeis' => ['MAIN-IMEI'],
                ],
            ],
            'componentAssignments' => [
                'sensors' => [
                    [
                        'detailId' => '201',
                        'unitIndex' => 1,
                        'category' => 'sensor',
                        'producto' => 'Sensor de combustible',
                        'availableImeis' => ['SENSOR-IMEI'],
                    ],
                ],
                'accessories' => [
                    [
                        'detailId' => '202',
                        'unitIndex' => 1,
                        'category' => 'accessory',
                        'producto' => 'Botón de pánico',
                        'availableImeis' => ['ACCESSORY-IMEI'],
                    ],
                ],
            ],
        ];

        $result = $method->invoke(
            $controller,
            $extraData,
            [100 => ['MAIN-IMEI']],
            [],
            [],
            true,
            false,
            [
                201 => [1 => 'MAIN-IMEI'],
                202 => [1 => ''],
            ]
        );

        $messages = implode(' ', $result['messages']);
        $this->assertStringContainsString('IMEIs no están disponibles', $messages);
        $this->assertStringContainsString('mismo IMEI', $messages);
        $this->assertStringContainsString('IMEI válido a cada sensor y accesorio', $messages);
        $this->assertContains('Accesorio: Botón de pánico', $result['missingComponentImeis']);
    }

    public function test_imei_duplicate_check_covers_all_submitted_values(): void
    {
        $controller = app(TicketsController::class);
        $method = new \ReflectionMethod(TicketsController::class, 'validateVista3Assignments');
        $result = $method->invoke($controller, [
            'equipamiento' => [
                (object) ['iddetalleCotizacion' => 100, 'availableImeis' => ['IMEI-1']],
            ],
        ], [
            100 => ['IMEI-1'],
            999 => ['IMEI-1'],
        ], [], []);

        $this->assertStringContainsString(
            'mismo IMEI',
            implode(' ', $result['messages'])
        );
    }

    public function test_incomplete_assignments_are_allowed_before_vista_three_and_reported_there(): void
    {
        $controller = app(TicketsController::class);
        $method = new \ReflectionMethod(TicketsController::class, 'validateVista3Assignments');
        $extraData = [
            'equipamiento' => [
                (object) [
                    'iddetalleCotizacion' => 100,
                    'producto' => 'Equipo GPS',
                    'cantidad' => 1,
                    'availableImeis' => ['IMEI-1'],
                ],
            ],
        ];

        $vistaDosResult = $method->invoke($controller, $extraData, [], [], []);
        $this->assertSame([], $vistaDosResult['messages']);

        $vistaTresResult = $method->invoke($controller, $extraData, [], [], [], true, true);
        $this->assertStringContainsString('IMEI válido', implode(' ', $vistaTresResult['messages']));
        $this->assertStringContainsString('una placa', implode(' ', $vistaTresResult['messages']));
        $this->assertStringContainsString('un número telefónico', implode(' ', $vistaTresResult['messages']));
        $this->assertSame(['Equipo GPS'], $vistaTresResult['missingImeis']);
        $this->assertSame(['Equipo GPS'], $vistaTresResult['missingPlacas']);
        $this->assertSame(['Equipo GPS'], $vistaTresResult['missingNumeros']);
    }

    public function test_new_tickets_default_to_pending_and_pending_is_normalized(): void
    {
        $controller = app(TicketsController::class);
        $normalize = new \ReflectionMethod(TicketsController::class, 'normalizeEstadoValue');
        $buildFields = new \ReflectionMethod(TicketsController::class, 'buildTicketFields');
        $stateField = collect($buildFields->invoke($controller, collect()))
            ->firstWhere('name', 'estado');

        $this->assertSame('Pendiente', $stateField['value']);
        $this->assertSame('Pendiente', $normalize->invoke($controller, 'Pendiente'));
        $this->assertSame('Pendiente', $normalize->invoke($controller, null));
    }

    public function test_ticket_created_for_an_approved_quote_starts_pending(): void
    {
        Schema::create('tipooperacion', function (Blueprint $table): void {
            $table->increments('idtipoOperacion');
            $table->string('nomenclatura')->nullable();
            $table->string('detalle')->nullable();
        });
        Schema::create('ticket', function (Blueprint $table): void {
            $table->increments('idticket');
            $table->integer('tipoOperacion_idtipoOperacion');
            $table->string('pedidoReferencia');
            $table->string('usuarioEmisor')->nullable();
            $table->string('usuarioReceptor')->nullable();
            $table->dateTime('fechaHoraRegistro')->nullable();
            $table->dateTime('fechaHoraCierre')->nullable();
            $table->string('detalle')->nullable();
            $table->string('ImagenEvidencia')->nullable();
            $table->string('respuesta')->nullable();
            $table->string('estado');
        });
        Schema::create('usuario', function (Blueprint $table): void {
            $table->string('usuario')->primary();
            $table->string('personal_dniPersonal')->nullable();
        });
        Schema::create('historialflujo', function (Blueprint $table): void {
            $table->increments('idhistorialflujo');
            $table->integer('ticket_idticket');
        });
        Schema::create('flujo', function (Blueprint $table): void {
            $table->increments('idflujo');
            $table->integer('tipoOperacion_idtipoOperacion');
        });

        DB::table('tipooperacion')->insert([
            'nomenclatura' => 'CT',
            'detalle' => 'Cotización',
        ]);
        $controller = app(\App\Http\Controllers\CotizacionController::class);
        $createTicket = new \ReflectionMethod(
            \App\Http\Controllers\CotizacionController::class,
            'createTicketForApprovedCotizacion'
        );
        $ticketId = $createTicket->invoke($controller, 'Q-100', 'test-user');

        $this->assertSame('Pendiente', DB::table('ticket')->where('idticket', $ticketId)->value('estado'));
    }

    public function test_removing_all_plan_assignments_clears_the_temporary_selection(): void
    {
        Schema::create('ticket', function (Blueprint $table): void {
            $table->integer('idticket')->primary();
            $table->text('temp_data')->nullable();
        });
        DB::table('ticket')->insert([
            'idticket' => 10,
            'temp_data' => json_encode(['planes' => ['100' => ['0' => ['200_1']]]]),
        ]);

        $tempData = app(TicketsService::class)->persistTempSession(
            Request::create('/', 'POST', ['planes_present' => '1']),
            3,
            'ticket_temp_10',
            10,
            'test-user'
        );

        $this->assertSame([], $tempData['planes']);
    }

    public function test_saving_view_two_imeis_preserves_the_vehicle_assigned_to_each_equipment_unit(): void
    {
        Schema::create('ticket', function (Blueprint $table): void {
            $table->integer('idticket')->primary();
            $table->text('temp_data')->nullable();
        });
        DB::table('ticket')->insert([
            'idticket' => 10,
            'temp_data' => json_encode([
                'placas' => ['100' => ['BEX703']],
            ]),
        ]);

        $tempData = app(TicketsService::class)->persistTempSession(
            Request::create('/', 'POST', [
                'imeis' => ['100' => ['IMEI-1']],
                'component_imeis' => ['200' => [1 => 'SENSOR-IMEI-1']],
                'comentario' => 'Equipo relacionado al vehículo.',
            ]),
            2,
            'ticket_temp_10',
            10,
            'test-user'
        );

        $this->assertSame('BEX703', $tempData['placas']['100'][0]);
        $this->assertSame('IMEI-1', $tempData['imeis']['100'][0]);
        $this->assertSame('SENSOR-IMEI-1', $tempData['component_imeis']['200'][1]);
    }

    public function test_vehicle_creation_rejects_global_duplicate_and_limits_customer_to_quote_group(): void
    {
        $this->createSchema();
        DB::table('cliente')->insert([
            ['idcliente' => 'CLIENT-1'],
            ['idcliente' => 'CLIENT-2'],
            ['idcliente' => 'CLIENT-3'],
        ]);
        DB::table('detallegrupocliente')->insert([
            ['cliente_idcliente' => 'CLIENT-1', 'grupoCliente_idgrupoCliente' => 7],
            ['cliente_idcliente' => 'CLIENT-2', 'grupoCliente_idgrupoCliente' => 7],
        ]);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'Q-100',
            'batch_id' => null,
            'cliente_idcliente' => 'CLIENT-1',
        ]);
        DB::table('ticket')->insert([
            'idticket' => 10,
            'pedidoReferencia' => 'Q-100',
            'temp_data' => null,
        ]);
        DB::table('tipovehiculo')->insert([
            'idtipoVehiculo' => 1,
        ]);
        DB::table('vehiculo')->insert([
            'placa' => 'DUP-123',
            'cliente_idcliente' => 'CLIENT-3',
        ]);

        $controller = app(TicketsController::class);
        $duplicateResponse = $controller->storeVehiculo($this->request([
            'ticket_id' => 10,
            'placa' => ' dup-123 ',
            'cliente_idcliente' => 'CLIENT-1',
            'tipoUnidad_idtable1' => 1,
        ]));

        $this->assertFalse($duplicateResponse->getData(true)['success']);
        $this->assertStringContainsString('ya está registrada', $duplicateResponse->getData(true)['message']);

        $outsideGroupResponse = $controller->storeVehiculo($this->request([
            'ticket_id' => 10,
            'placa' => 'NEW-456',
            'cliente_idcliente' => 'CLIENT-3',
            'tipoUnidad_idtable1' => 1,
        ]));

        $this->assertSame(422, $outsideGroupResponse->getStatusCode());

        $groupMemberResponse = $controller->storeVehiculo($this->request([
            'ticket_id' => 10,
            'placa' => 'NEW-456',
            'cliente_idcliente' => 'CLIENT-2',
            'tipoUnidad_idtable1' => 1,
        ]));

        $this->assertTrue($groupMemberResponse->getData(true)['success']);
        $storedTempData = json_decode((string) DB::table('ticket')->where('idticket', 10)->value('temp_data'), true);
        $this->assertSame('NEW-456', $storedTempData['vehiculos'][0]['placa']);
    }

    public function test_show_preserves_flash_success_when_redirecting_to_ticket_list(): void
    {
        $this->createSchema();
        DB::table('ticket')->insert([
            'idticket' => 10,
            'pedidoReferencia' => 'Q-100',
            'temp_data' => null,
        ]);
        session()->flash('success', 'Ticket liberado correctamente.');
        session()->ageFlashData();

        $response = app(TicketsController::class)->show(10);

        $this->assertSame(route('modules.tickets'), $response->getTargetUrl());
        $this->assertContains('success', session()->get('_flash.new', []));
        $this->assertSame('Ticket liberado correctamente.', session('success'));
    }

    public function test_imei_and_final_views_load_view_one_vehicle_and_phone_availability(): void
    {
        $this->createShowExtraDataSchema();
        DB::table('cliente')->insert(['idcliente' => 'CLIENT-1']);
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'Q-100',
            'batch_id' => null,
            'cliente_idcliente' => 'CLIENT-1',
        ]);
        DB::table('vehiculo')->insert([
            'placa' => 'BEX703',
            'cliente_idcliente' => 'CLIENT-1',
        ]);
        DB::table('numerotelefonico')->insert([
            'numeroTelefonico' => '913025619',
            'estado' => '2',
        ]);
        DB::table('detallesimcard')->insert([
            'numeroTelefonico_numeroTelefonico' => '913025619',
            'estado' => 0,
        ]);

        $service = app(TicketsService::class);
        $ticket = (object) [
            'pedidoReferencia' => 'Q-100',
            'temp_data' => json_encode([
                'placas' => ['100' => ['BEX703']],
                'numeros' => ['100' => ['913025619']],
            ]),
        ];

        foreach ([2, 7] as $vistaId) {
            $extraData = $service->buildShowExtraData($ticket, $vistaId, 10);

            $this->assertSame(
                ['BEX703'],
                collect($extraData['vehiculosExistentes'])->pluck('placa')->all()
            );
            $this->assertSame(
                ['913025619'],
                collect($extraData['numerosTelefonicos'])->pluck('numeroTelefonico')->all()
            );
        }
    }

    private function request(array $data): Request
    {
        return Request::create(
            '/modulos/tickets/vehiculos/guardar',
            'POST',
            $data,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );
    }

    private function createSchema(): void
    {
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
        });
        Schema::create('detallegrupocliente', function (Blueprint $table): void {
            $table->string('cliente_idcliente');
            $table->integer('grupoCliente_idgrupoCliente');
        });
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('batch_id')->nullable();
            $table->string('cliente_idcliente');
        });
        Schema::create('ticket', function (Blueprint $table): void {
            $table->integer('idticket')->primary();
            $table->string('pedidoReferencia')->nullable();
            $table->text('temp_data')->nullable();
        });
        Schema::create('tipovehiculo', function (Blueprint $table): void {
            $table->integer('idtipoVehiculo')->primary();
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
            $table->string('cliente_idcliente');
        });
    }

    private function createShowExtraDataSchema(): void
    {
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('detallegrupocliente', function (Blueprint $table): void {
            $table->string('cliente_idcliente');
            $table->integer('grupoCliente_idgrupoCliente');
        });
        Schema::create('grupocliente', function (Blueprint $table): void {
            $table->integer('idgrupoCliente')->primary();
            $table->string('nombreGrupo');
        });
        Schema::create('formapago', function (Blueprint $table): void {
            $table->integer('idformaPago')->primary();
            $table->string('detalle')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->integer('idmoneda')->primary();
            $table->string('detalle')->nullable();
        });
        Schema::create('vigenciaoferta', function (Blueprint $table): void {
            $table->integer('idvigenciaOferta')->primary();
            $table->string('detalle')->nullable();
        });
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('batch_id')->nullable();
            $table->string('cliente_idcliente');
            $table->integer('formaPago_idformaPago')->nullable();
            $table->integer('moneda_idmoneda')->nullable();
            $table->integer('vigenciaOferta_idvigenciaOferta')->nullable();
            $table->text('comentario')->nullable();
        });
        Schema::create('direccioncliente', function (Blueprint $table): void {
            $table->integer('iddireccionCliente')->primary();
            $table->string('cliente_idcliente');
            $table->text('direccion')->nullable();
            $table->boolean('default')->nullable();
        });
        Schema::create('contacto', function (Blueprint $table): void {
            $table->integer('idcontacto')->primary();
            $table->string('cliente_idcliente');
            $table->string('numero')->nullable();
            $table->string('correo')->nullable();
            $table->boolean('default')->nullable();
        });
        Schema::create('detallecotizacion', function (Blueprint $table): void {
            $table->integer('iddetalleCotizacion')->primary();
            $table->string('cotizacion_nroCotizacion');
            $table->integer('almacen_idalmacen');
            $table->integer('cantidad');
        });
        Schema::create('almacen', function (Blueprint $table): void {
            $table->integer('idalmacen')->primary();
            $table->integer('tipoElemento_idtipoElemento');
            $table->string('detalle')->nullable();
            $table->integer('cantidadDisponible')->nullable();
            $table->string('periodo')->nullable();
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->integer('idtipoElemento')->primary();
            $table->string('nombre');
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
            $table->string('cliente_idcliente');
        });
        Schema::create('tipovehiculo', function (Blueprint $table): void {
            $table->integer('idtipoVehiculo')->primary();
            $table->string('nombre');
        });
        Schema::create('numerotelefonico', function (Blueprint $table): void {
            $table->string('numeroTelefonico')->primary();
            $table->string('estado');
        });
        Schema::create('detallesimcard', function (Blueprint $table): void {
            $table->string('numeroTelefonico_numeroTelefonico');
            $table->integer('estado');
        });
        Schema::create('detnumerosdispositivo', function (Blueprint $table): void {
            $table->string('numeroTelefonico_numeroTelefonico');
            $table->string('dispositivoCliente_iddispositivoCliente');
        });
        Schema::create('dispositivocliente', function (Blueprint $table): void {
            $table->string('iddispositivoCliente')->primary();
            $table->string('estado');
        });
        Schema::create('detalle_serviciodispositivo', function (Blueprint $table): void {
            $table->string('dispositivoCliente_iddispositivoCliente');
            $table->integer('servicioCliente_idservicioCliente');
            $table->integer('estado');
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->integer('idservicioCliente')->primary();
            $table->string('estado');
        });
    }
}
