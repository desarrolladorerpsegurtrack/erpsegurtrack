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
    public function test_assignment_import_saves_optional_repeated_plan_and_rejects_duplicate_numbers_and_simcards(): void
    {
        $this->createSchema();
        DB::table('operador')->insert(['idoperador' => 1, 'nombre' => 'CLARO']);
        Storage::fake('local');

        $filePath = tempnam(sys_get_temp_dir(), 'simcard-import-');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Numero', 'SimCard', 'Operador', 'Plan'],
            ['953206113', '895110130143435922F', 'CLARO', '18MB'],
            ['982664579', '895110130143435997F', 'CLARO', '18MB'],
            ['953214229', '895110130143436011F', 'CLARO', ''],
            ['953206113', '895110130143436037F', 'CLARO', '20MB'],
            ['953234466', '895110130143435922F', 'CLARO', '20MB'],
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
            $this->assertSame(3, $preview['newRows']);
            $this->assertSame(2, $preview['fileDuplicateRows']);
            $this->assertSame('18MB', $preview['previewRows'][0]['plan']);
            $this->assertSame('18MB', $preview['previewRows'][1]['plan']);
            $this->assertSame('', $preview['previewRows'][2]['plan']);

            app(LineasChipsController::class)->detallesimcardImportProcess(
                Request::create('/modulos/lineas-chips/detallesimcard/import/process', 'POST', [
                    'importToken' => $preview['token'],
                ])
            );

            $this->assertSame(3, DB::table('detallesimcard')->count());
            $this->assertSame('18MB', DB::table('numerotelefonico')->where('numeroTelefonico', '953206113')->value('plan'));
            $this->assertSame('18MB', DB::table('numerotelefonico')->where('numeroTelefonico', '982664579')->value('plan'));
            $this->assertSame('', DB::table('numerotelefonico')->where('numeroTelefonico', '953214229')->value('plan'));
            $this->assertSame('2', DB::table('numerotelefonico')->where('numeroTelefonico', '953206113')->value('estado'));
            $this->assertSame('2', DB::table('simcard')->where('idsimCard', '895110130143435922F')->value('estado'));
            $this->assertSame('0', DB::table('detallesimcard')->where('numeroTelefonico_numeroTelefonico', '953206113')->value('estado'));
        } finally {
            @unlink($filePath);
        }
    }

    private function createSchema(): void
    {
        Schema::create('operador', function (Blueprint $table): void {
            $table->unsignedInteger('idoperador')->primary();
            $table->string('nombre');
        });

        Schema::create('simcard', function (Blueprint $table): void {
            $table->string('idsimCard')->primary();
            $table->string('estado')->nullable();
            $table->unsignedInteger('operador_idoperador');
        });

        Schema::create('numerotelefonico', function (Blueprint $table): void {
            $table->string('numeroTelefonico')->primary();
            $table->string('plan')->nullable();
            $table->string('estado')->nullable();
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
