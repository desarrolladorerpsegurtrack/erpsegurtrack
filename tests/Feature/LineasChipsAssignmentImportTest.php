<?php

namespace Tests\Feature;

use App\Http\Controllers\LineasChipsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class LineasChipsAssignmentImportTest extends TestCase
{
    public function test_number_import_uses_custom_price_and_currency_from_spreadsheet(): void
    {
        $this->createSchema();
        DB::table('plantelef')->insert([
            'idplantelef' => 3,
            'nombre' => 'Plan Europa',
            'precio' => 12.50,
            'moneda' => '€',
            'megas' => '5 GB',
            'sms' => '50',
        ]);
        Storage::fake('local');

        $filePath = tempnam(sys_get_temp_dir(), 'numero-import-');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Numero', 'Plan', 'Precio'],
            ['953200099', 'Plan Europa', '€ 99.99'],
        ]);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($filePath);
        $spreadsheet->disconnectWorksheets();

        try {
            $upload = new UploadedFile(
                $filePath,
                'numeros.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                UPLOAD_ERR_OK,
                true
            );
            $previewResponse = app(LineasChipsController::class)->numerosTelefonicoImportPreview(
                Request::create(
                    '/modulos/lineas-chips/numeros-telefonico/import/preview',
                    'POST',
                    [],
                    [],
                    ['importFile' => $upload],
                    ['HTTP_ACCEPT' => 'application/json']
                )
            );
            $preview = $previewResponse->getData(true)['preview'];

            $this->assertSame(1, $preview['newRows']);
            $this->assertSame('99.99', $preview['previewRows'][0]['precio']);
            $this->assertSame('€', $preview['previewRows'][0]['moneda']);

            app(LineasChipsController::class)->numerosTelefonicoImportProcess(
                Request::create('/modulos/lineas-chips/numeros-telefonico/import/process', 'POST', [
                    'importToken' => $preview['token'],
                ])
            );

            $this->assertSame(3, DB::table('numerotelefonico')->where('numeroTelefonico', '953200099')->value('plantelef_idplantelef'));
            $this->assertEquals(99.99, (float) DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200099')->value('precio'));
            $this->assertSame('€', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200099')->value('moneda'));
        } finally {
            @unlink($filePath);
        }
    }

    public function test_number_import_requires_an_existing_plan_name_without_requiring_plan_currency(): void
    {
        $this->createSchema();
        DB::table('plantelef')->insert([
            'idplantelef' => 1,
            'nombre' => 'Plan de prueba',
            'precio' => 12.50,
            'moneda' => 'G',
            'megas' => '5 GB',
            'sms' => '50',
        ]);
        Storage::fake('local');

        $filePath = tempnam(sys_get_temp_dir(), 'numero-plan-import-');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Numero', 'Plan', 'Precio'],
            ['953200099', ' Plan de prueba ', 'S/ 15.00'],
            ['953200098', 'Plan inexistente', 'S/ 20.00'],
        ]);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($filePath);
        $spreadsheet->disconnectWorksheets();

        try {
            $upload = new UploadedFile(
                $filePath,
                'numeros.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                UPLOAD_ERR_OK,
                true
            );
            $previewResponse = app(LineasChipsController::class)->numerosTelefonicoImportPreview(
                Request::create(
                    '/modulos/lineas-chips/numeros-telefonico/import/preview',
                    'POST',
                    [],
                    [],
                    ['importFile' => $upload],
                    ['HTTP_ACCEPT' => 'application/json']
                )
            );
            $preview = $previewResponse->getData(true)['preview'];

            $this->assertSame(1, $preview['newRows']);
            $this->assertSame(1, $preview['invalidRows']);
            $this->assertSame('Nuevo', $preview['previewRows'][0]['status']);
            $this->assertSame('Inválido (el nombre del plan no existe)', $preview['previewRows'][1]['status']);
            $this->assertSame('S/ 20.00', $preview['previewRows'][1]['precio']);
            $this->assertSame('', $preview['previewRows'][1]['moneda']);
            $this->assertSame(1, $preview['previewRows'][0]['planId']);

            app(LineasChipsController::class)->numerosTelefonicoImportProcess(
                Request::create('/modulos/lineas-chips/numeros-telefonico/import/process', 'POST', [
                    'importToken' => $preview['token'],
                ])
            );

            $this->assertSame(1, DB::table('numerotelefonico')->where('numeroTelefonico', '953200099')->value('plantelef_idplantelef'));
            $this->assertSame(0, DB::table('numerotelefonico')->where('numeroTelefonico', '953200098')->count());
            $this->assertSame('S/', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200099')->value('moneda'));
        } finally {
            @unlink($filePath);
        }
    }

    public function test_number_import_validates_number_then_plan_then_numeric_price(): void
    {
        $this->createSchema();
        DB::table('plantelef')->insert([
            'idplantelef' => 1,
            'nombre' => 'Plan válido',
            'precio' => 12.50,
            'moneda' => 'S/',
            'megas' => '5 GB',
            'sms' => '50',
        ]);
        Storage::fake('local');

        $filePath = tempnam(sys_get_temp_dir(), 'numero-validation-order-');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Numero', 'Plan', 'Precio'],
            ['1234', 'Plan inexistente', 'S/ texto'],
            ['953200098', 'Plan inexistente', 'S/ texto'],
            ['953200097', 'Plan válido', 'S/ texto'],
        ]);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($filePath);
        $spreadsheet->disconnectWorksheets();

        try {
            $upload = new UploadedFile(
                $filePath,
                'numeros.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                UPLOAD_ERR_OK,
                true
            );
            $response = app(LineasChipsController::class)->numerosTelefonicoImportPreview(
                Request::create(
                    '/modulos/lineas-chips/numeros-telefonico/import/preview',
                    'POST',
                    [],
                    [],
                    ['importFile' => $upload],
                    ['HTTP_ACCEPT' => 'application/json']
                )
            );
            $previewRows = $response->getData(true)['preview']['previewRows'];

            $this->assertSame('Inválido (formato o longitud de número incorrecto)', $previewRows[0]['status']);
            $this->assertSame('Inválido (el nombre del plan no existe)', $previewRows[1]['status']);
            $this->assertSame('S/ texto', $previewRows[1]['precio']);
            $this->assertSame('Inválido (precio no numérico)', $previewRows[2]['status']);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_number_import_preview_export_keeps_currency_for_unknown_plans(): void
    {
        $preview = [
            'importType' => 'numero',
            'previewRows' => [
                ['line' => 1, 'numero' => '953200001', 'plan' => 'Plan no existente', 'precio' => 'S/ 40.00', 'moneda' => '', 'status' => 'Inválido'],
                ['line' => 2, 'numero' => '953200002', 'plan' => 'Plan no existente', 'precio' => '$ 21.00', 'moneda' => '', 'status' => 'Inválido'],
                ['line' => 3, 'numero' => '953200003', 'plan' => 'Plan no existente', 'precio' => '€ 15.00', 'moneda' => '', 'status' => 'Inválido'],
            ],
        ];
        $response = app(LineasChipsController::class)->detallesimcardPreviewExport(
            Request::create('/modulos/lineas-chips/detallesimcard/preview/export/import', 'POST', [
                'previewPayload' => rawurlencode(json_encode($preview)),
            ]),
            'import'
        );

        $filePath = $response->getFile()->getPathname();
        try {
            $exportedSheet = IOFactory::load($filePath)->getActiveSheet();
            $this->assertSame('S/ 40.00', $exportedSheet->getCell('D2')->getValue());
            $this->assertSame('$ 21.00', $exportedSheet->getCell('D3')->getValue());
            $this->assertSame('€ 15.00', $exportedSheet->getCell('D4')->getValue());
        } finally {
            @unlink($filePath);
        }
    }

    public function test_assignment_import_saves_plan_relations_and_rejects_duplicate_numbers_and_simcards(): void
    {
        $this->createSchema();
        DB::table('operador')->insert(['idoperador' => 1, 'nombre' => 'CLARO']);
        DB::table('plantelef')->insert([
            ['idplantelef' => 1, 'nombre' => '18MB', 'precio' => 30, 'moneda' => 'S/', 'megas' => '18MB', 'sms' => '100'],
            ['idplantelef' => 2, 'nombre' => '20MB', 'precio' => 40, 'moneda' => '$', 'megas' => '20MB', 'sms' => '200'],
        ]);
        Storage::fake('local');

        $filePath = tempnam(sys_get_temp_dir(), 'simcard-import-');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Numero', 'SimCard', 'Operador', 'Plan', 'Precio'],
            ['953206113', '895110130143435922F', 'CLARO', '18MB', 'S/ 25.50'],
            ['982664579', '895110130143435997F', 'CLARO', '18MB', '$ 45.25'],
            ['953214229', '895110130143436011F', 'CLARO', '', 'S/ 12.00'],
            ['953206113', '895110130143436037F', 'CLARO', '20MB', '€ 99.00'],
            ['953234466', '895110130143435922F', 'CLARO', '20MB', '$ 18.00'],
            ['953200099', '895110130143436055F', 'CLARO', 'Plan inexistente', '€ 15.00'],
        ]);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($filePath);
        $spreadsheet->disconnectWorksheets();

        try {
            $upload = new UploadedFile(
                $filePath,
                'asignaciones.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                UPLOAD_ERR_OK,
                true
            );
            $previewResponse = app(LineasChipsController::class)->detallesimcardImportPreview(
                Request::create(
                    '/modulos/lineas-chips/detallesimcard/import/preview',
                    'POST',
                    [],
                    [],
                    ['importFile' => $upload],
                    ['HTTP_ACCEPT' => 'application/json']
                )
            );

            $preview = $previewResponse->getData(true)['preview'];
            $this->assertSame(2, $preview['newRows']);
            $this->assertSame(2, $preview['fileDuplicateRows']);
            $this->assertSame(1, $preview['invalidRows']);
            $this->assertSame('18MB', $preview['previewRows'][0]['plan']);
            $this->assertSame('18MB', $preview['previewRows'][1]['plan']);
            $this->assertSame('', $preview['previewRows'][2]['plan']);
            $this->assertSame('25.50', $preview['previewRows'][0]['precio']);
            $this->assertSame('S/', $preview['previewRows'][0]['moneda']);
            $this->assertSame('45.25', $preview['previewRows'][1]['precio']);
            $this->assertSame('$', $preview['previewRows'][1]['moneda']);
            $this->assertSame('Inválido (el plan no existe)', $preview['previewRows'][5]['status']);

            app(LineasChipsController::class)->detallesimcardImportProcess(
                Request::create('/modulos/lineas-chips/detallesimcard/import/process', 'POST', [
                    'importToken' => $preview['token'],
                ])
            );

            $this->assertSame(2, DB::table('detallesimcard')->count());
            $this->assertSame(0, DB::table('numerotelefonico')->where('numeroTelefonico', '953200099')->count());
            $this->assertSame(1, DB::table('numerotelefonico')->where('numeroTelefonico', '953206113')->value('plantelef_idplantelef'));
            $this->assertSame(1, DB::table('numerotelefonico')->where('numeroTelefonico', '982664579')->value('plantelef_idplantelef'));
            $this->assertSame('2', DB::table('numerotelefonico')->where('numeroTelefonico', '953206113')->value('estado'));
            $this->assertEquals(25.50, (float) DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953206113')->value('precio'));
            $this->assertSame('S/', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953206113')->value('moneda'));
            $this->assertEquals(45.25, (float) DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '982664579')->value('precio'));
            $this->assertSame('$', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '982664579')->value('moneda'));
            $this->assertSame('2', DB::table('simcard')->where('idsimCard', '895110130143435922F')->value('estado'));
            $this->assertSame('0', DB::table('detallesimcard')->where('numeroTelefonico_numeroTelefonico', '953206113')->value('estado'));

            $assignmentView = app(LineasChipsController::class)->detallesimcardIndex(
                Request::create('/modulos/lineas-chips/detallesimcard', 'GET', ['plan' => '18MB'])
            );
            $assignment = $assignmentView->getData()['items']->first();
            $this->assertSame('18MB - 18MB - 100', $assignment->plan);
            $this->assertSame('plan', $assignmentView->getData()['columns'][2]['key']);
            $this->assertSame(2, $assignmentView->getData()['items']->total());

            config(['locks.enabled' => false]);
            $storeRequest = Request::create(
                '/modulos/lineas-chips/numeros-telefonico',
                'POST',
                [
                    'numeroTelefonico' => '953200001',
                    'plantelef_idplantelef' => 2,
                    'precio' => '25.50',
                    'moneda' => '€',
                    'estado' => '1',
                ]
            );
            $storeRequest->setLaravelSession(app('session')->driver());
            app()->instance('request', $storeRequest);
            app(LineasChipsController::class)->numerosTelefonicoStore($storeRequest);

            $this->assertSame(2, DB::table('numerotelefonico')->where('numeroTelefonico', '953200001')->value('plantelef_idplantelef'));
            $this->assertEquals(25.50, (float) DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200001')->value('precio'));
            $this->assertSame('€', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200001')->value('moneda'));

            $editRequest = Request::create(
                '/modulos/lineas-chips/numeros-telefonico/953200001',
                'PUT',
                [
                    'numeroTelefonico' => '953200001',
                    'plantelef_idplantelef' => 1,
                    'precio' => '19.75',
                    'moneda' => '$',
                    'estado' => '1',
                ]
            );
            $editRequest->setLaravelSession(app('session')->driver());
            app()->instance('request', $editRequest);
            app(LineasChipsController::class)->numerosTelefonicoUpdate($editRequest, '953200001');

            $this->assertSame(1, DB::table('numerotelefonico')->where('numeroTelefonico', '953200001')->value('plantelef_idplantelef'));
            $this->assertEquals(19.75, (float) DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200001')->value('precio'));
            $this->assertSame('$', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200001')->value('moneda'));

            $editView = app(LineasChipsController::class)->numerosTelefonicoEdit('953200001');
            $fields = collect($editView->getData()['fields']);
            $planField = $fields
                ->firstWhere('name', 'plantelef_idplantelef');
            $priceField = $fields->firstWhere('name', 'precio');
            $currencyField = $fields->firstWhere('name', 'moneda');
            $this->assertTrue($planField['tomSelect']);
            $this->assertSame('18MB - 18MB - 100', $planField['options'][1]);
            $this->assertSame('number', $priceField['type']);
            $this->assertSame(['S/' => 'S/', '$' => '$', '€' => '€'], $currencyField['options']);
            $this->assertSame(['price' => '30.00', 'currency' => 'S/'], $editView->getData()['planPriceMap']['1']);
            $this->assertSame('19.75', (string) $editView->getData()['record']->precio);
            $this->assertSame('$', $editView->getData()['record']->moneda);

            $indexView = app(LineasChipsController::class)->numerosTelefonicoIndex(
                Request::create('/modulos/lineas-chips/numeros-telefonico', 'GET')
            );
            $listedNumber = $indexView->getData()['items']->first();
            $this->assertSame('18MB - 18MB - 100', $listedNumber->plan);
            $this->assertSame('$ 19.75', $listedNumber->precio_numero);
            $planSearchView = app(LineasChipsController::class)->numerosTelefonicoIndex(
                Request::create('/modulos/lineas-chips/numeros-telefonico', 'GET', ['q' => '18MB'])
            );
            $this->assertSame(3, $planSearchView->getData()['items']->total());

            $simcardView = app(LineasChipsController::class)->simcardCreate();
            $simcardFields = collect($simcardView->getData()['fields']);
            $simcardPlanField = $simcardFields->firstWhere('name', 'plantelef_idplantelef');
            $simcardPriceField = $simcardFields->firstWhere('name', 'precio');
            $this->assertTrue($simcardPlanField['tomSelect']);
            $this->assertTrue($simcardPlanField['quickCreateNumero']);
            $this->assertTrue($simcardPriceField['quickCreateNumero']);
            $this->assertSame('18MB - 18MB - 100', $simcardPlanField['options'][1]);

            $simcardRequest = Request::create(
                '/modulos/lineas-chips/simcard',
                'POST',
                [
                    'idsimCard' => '895110130143439999F',
                    'operador_idoperador' => 1,
                    'estado' => '1',
                    'desea_relacionar_numero' => '1',
                    'numeroTelefonico' => '953200002',
                    'plantelef_idplantelef' => 2,
                    'precio' => '21.25',
                    'moneda' => '$',
                    'estado_numero' => '1',
                ]
            );
            $simcardRequest->setLaravelSession(app('session')->driver());
            app()->instance('request', $simcardRequest);
            app(LineasChipsController::class)->simcardStore($simcardRequest);

            $this->assertSame(2, DB::table('numerotelefonico')->where('numeroTelefonico', '953200002')->value('plantelef_idplantelef'));
            $this->assertEquals(21.25, (float) DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200002')->value('precio'));
            $this->assertSame('$', DB::table('detalle_precionumero')->where('numeroTelefonico_numeroTelefonico', '953200002')->value('moneda'));
            $this->assertSame('0', DB::table('detallesimcard')->where('numeroTelefonico_numeroTelefonico', '953200002')->value('estado'));
        } finally {
            @unlink($filePath);
        }
    }

    public function test_assignment_import_validates_number_simcard_operator_and_plan_in_order(): void
    {
        $this->createSchema();
        DB::table('operador')->insert(['idoperador' => 1, 'nombre' => 'CLARO']);
        DB::table('plantelef')->insert([
            'idplantelef' => 1,
            'nombre' => 'Plan válido',
            'precio' => 30,
            'moneda' => 'S/',
            'megas' => '18MB',
            'sms' => '100',
        ]);
        Storage::fake('local');

        $filePath = tempnam(sys_get_temp_dir(), 'assignment-validation-order-');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Numero', 'SimCard', 'Operador', 'Plan', 'Precio'],
            ['123', '', '', '', ''],
            ['953200001', 'bad', '', '', ''],
            ['953200002', '895110130143435922F', 'Operador inexistente', '', ''],
            ['953200003', '895110130143435923F', 'CLARO', 'Plan inexistente', 'S/ 10.00'],
            ['953200004', '895110130143435924F', 'CLARO', 'Plan válido', 'S/ no-numérico'],
        ]);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($filePath);
        $spreadsheet->disconnectWorksheets();

        try {
            $upload = new UploadedFile(
                $filePath,
                'asignaciones.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                UPLOAD_ERR_OK,
                true
            );
            $response = app(LineasChipsController::class)->detallesimcardImportPreview(
                Request::create(
                    '/modulos/lineas-chips/detallesimcard/import/preview',
                    'POST',
                    [],
                    [],
                    ['importFile' => $upload],
                    ['HTTP_ACCEPT' => 'application/json']
                )
            );
            $rows = $response->getData(true)['preview']['previewRows'];

            $this->assertSame('Inválido (formato o longitud de número incorrecto)', $rows[0]['status']);
            $this->assertSame('Inválido (formato o longitud de SimCard incorrecto)', $rows[1]['status']);
            $this->assertSame('Inválido (operador no existe en BD)', $rows[2]['status']);
            $this->assertSame('Inválido (el plan no existe)', $rows[3]['status']);
            $this->assertSame('Inválido (precio no numérico)', $rows[4]['status']);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_assignment_preview_export_includes_price_with_its_currency_symbol(): void
    {
        $preview = [
            'previewRows' => [
                [
                    'line' => 1,
                    'numero' => '953200001',
                    'simcard' => '895110130143435922F',
                    'operador' => 'CLARO',
                    'plan' => 'Plan ejemplo',
                    'precio' => '42.75',
                    'moneda' => '€',
                    'status' => 'Nuevo',
                ],
            ],
        ];
        $response = app(LineasChipsController::class)->detallesimcardPreviewExport(
            Request::create('/modulos/lineas-chips/detallesimcard/preview/export/import', 'POST', [
                'previewPayload' => rawurlencode(json_encode($preview)),
            ]),
            'import'
        );

        $filePath = $response->getFile()->getPathname();
        try {
            $sheet = IOFactory::load($filePath)->getActiveSheet();
            $this->assertSame('Precio', $sheet->getCell('F1')->getValue());
            $this->assertSame('€ 42.75', $sheet->getCell('F2')->getValue());
        } finally {
            @unlink($filePath);
        }
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('detallesimcard');
        Schema::dropIfExists('detalle_precionumero');
        Schema::dropIfExists('numerotelefonico');
        Schema::dropIfExists('plantelef');
        Schema::dropIfExists('simcard');
        Schema::dropIfExists('operador');

        Schema::create('operador', function (Blueprint $table): void {
            $table->unsignedInteger('idoperador')->primary();
            $table->string('nombre');
        });

        Schema::create('simcard', function (Blueprint $table): void {
            $table->string('idsimCard')->primary();
            $table->string('estado')->nullable();
            $table->unsignedInteger('operador_idoperador');
        });

        Schema::create('plantelef', function (Blueprint $table): void {
            $table->increments('idplantelef');
            $table->string('nombre', 45);
            $table->decimal('precio', 10, 2);
            $table->string('moneda', 10)->nullable();
            $table->string('megas', 45);
            $table->string('sms', 45);
        });

        Schema::create('numerotelefonico', function (Blueprint $table): void {
            $table->string('numeroTelefonico')->primary();
            $table->unsignedInteger('plantelef_idplantelef');
            $table->string('estado')->nullable();
        });

        Schema::create('detalle_precionumero', function (Blueprint $table): void {
            $table->increments('iddetalle_precionumero');
            $table->string('numeroTelefonico_numeroTelefonico');
            $table->decimal('precio', 10, 2);
            $table->string('moneda', 10);
            $table->dateTime('fecha')->nullable();
        });

        Schema::create('detallesimcard', function (Blueprint $table): void {
            $table->increments('iddetalleSimCard');
            $table->string('simCard_idsimCard');
            $table->string('numeroTelefonico_numeroTelefonico');
            $table->dateTime('fechaAsignacion');
            $table->string('estado');
        });
    }
}
