<?php

namespace Tests\Unit;

use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CotizacionService;
use App\Services\CuentasPorCobrarService;
use App\Services\CxcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CxcPaymentDetailsTest extends TestCase
{
    public function test_payment_details_return_latest_saved_payment_fields(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();

        $response = $this->controller()->getPaymentDetails('1');
        $data = $response->getData(true)['data'];

        $this->assertSame('2026-10-07 10:15:00', $data['fechaPago']);
        $this->assertSame('2026-11-07', $data['fechaFin']);
        $this->assertSame('Cliente de prueba', $data['cliente_nombre']);
        $this->assertSame(20.0, (float) $data['montoOriginal']);
        $this->assertSame('Comentario guardado', $data['detalle_descripcion']);
        $this->assertSame(2, (int) $data['entidadBancaria_identidadBancaria']);
        $this->assertSame(2, (int) $data['formaPago_idformaPago']);
    }

    public function test_group_payment_details_use_the_latest_payment_from_any_cxc_in_the_group(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 2,
            'cliente_idcliente' => 'CLI-1',
            'tipoCobro_idtipoCobros' => 5,
            'docReferencia' => 'F001-000001',
            'montoOriginal' => 30,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-11-07',
            'estado' => '3',
            'descripcion' => 'Servicio agrupado',
            'moneda_idmoneda' => 1,
        ]);
        DB::table('bancos')->insert([
            'idbancos' => 3,
            'entidadBancaria_identidadBancaria' => 2,
            'fechaRegistro' => '2026-10-08 10:15:00',
            'informacion' => 'Pago agrupado',
            'tipoMov' => 'I',
            'monto' => 30,
        ]);
        DB::table('detallecxc')->insert([
            'iddetalleCXC' => 3,
            'formaPago_idformaPago' => 2,
            'cuentasPorCobrar_idcuentasPorCobrar' => 2,
            'moneda_idmoneda' => 1,
            'fechaPago' => '2026-10-08 10:15:00',
            'bancos_idbancos' => 3,
            'estado' => '1',
            'descripcion' => 'Obs: Pago agrupado',
        ]);
        app()->instance('request', Request::create('/?cxc_ids=1,2'));

        $response = $this->controller()->getPaymentDetails('1');
        $data = $response->getData(true)['data'];

        $this->assertSame(2, (int) $data['idcuentasPorCobrar']);
        $this->assertSame('2026-10-08 10:15:00', $data['fechaPago']);
        $this->assertSame(2, (int) $data['entidadBancaria_identidadBancaria']);
        $this->assertSame(2, (int) $data['formaPago_idformaPago']);
        $this->assertSame('F001-000001', $data['docReferencia']);
    }

    public function test_payment_details_show_received_amount_and_currency_for_foreign_currency_payment(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('moneda')->insert(['idmoneda' => 2, 'simbolo' => '$']);
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 1)->update(['moneda_idmoneda' => 2]);
        DB::table('detallecxc')->where('iddetalleCXC', 2)->update(['moneda_idmoneda' => 1]);
        DB::table('bancos')->where('idbancos', 2)->update([
            'monto' => 10.18,
            'fechaRegistro' => '2026-10-07 10:15:00',
        ]);
        DB::table('detallecxc')->where('iddetalleCXC', 2)->update([
            'moneda_idmoneda' => 1,
            'descripcion' => 'Obs: Pago agrupado',
        ]);
        foreach ([2, 3] as $cxcId) {
            DB::table('cuentasporcobrar')->insert([
                'idcuentasPorCobrar' => $cxcId,
                'cliente_idcliente' => 'CLI-1',
                'tipoCobro_idtipoCobros' => 5,
                'docReferencia' => 'F001-000001',
                'montoOriginal' => 2.95,
                'montoActual' => 0,
                'fechaCancelacion' => '2026-11-07',
                'estado' => '3',
                'descripcion' => 'Servicio de prueba ' . $cxcId,
                'moneda_idmoneda' => 2,
            ]);
        }
        DB::table('bancos')->insert([
            ['idbancos' => 4, 'entidadBancaria_identidadBancaria' => 2, 'fechaRegistro' => '2026-10-07 10:15:00', 'informacion' => 'Pago servicio 2', 'tipoMov' => 'I', 'monto' => 10.18],
            ['idbancos' => 5, 'entidadBancaria_identidadBancaria' => 2, 'fechaRegistro' => '2026-10-07 10:15:00', 'informacion' => 'Pago servicio 3', 'tipoMov' => 'I', 'monto' => 10.64],
            ['idbancos' => 6, 'entidadBancaria_identidadBancaria' => 2, 'fechaRegistro' => '2026-10-07 10:15:00', 'informacion' => 'Saldo a favor por sobrepago', 'tipoMov' => 'A', 'monto' => 0.47],
            ['idbancos' => 7, 'entidadBancaria_identidadBancaria' => 2, 'fechaRegistro' => '2026-10-07 10:15:00', 'informacion' => 'Otro pago', 'tipoMov' => 'I', 'monto' => 100],
        ]);
        DB::table('detallecxc')->insert([
            [
                'iddetalleCXC' => 3,
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => 2,
                'fechaPago' => '2026-10-07 10:15:00',
                'bancos_idbancos' => 4,
                'estado' => '1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Obs: Pago agrupado',
            ],
            [
                'iddetalleCXC' => 4,
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => 3,
                'fechaPago' => '2026-10-07 10:15:00',
                'bancos_idbancos' => 5,
                'estado' => '1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Obs: Pago agrupado',
            ],
            [
                'iddetalleCXC' => 5,
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => 3,
                'fechaPago' => '2026-10-07 10:15:00',
                'bancos_idbancos' => 6,
                'estado' => 'A',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Saldo a favor por sobrepago',
            ],
            [
                'iddetalleCXC' => 6,
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => 4,
                'fechaPago' => '2026-10-07 10:15:00',
                'bancos_idbancos' => 7,
                'estado' => '1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'Obs: Otro pago',
            ],
        ]);
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 4,
            'cliente_idcliente' => 'CLI-1',
            'tipoCobro_idtipoCobros' => 5,
            'docReferencia' => 'F001-000001',
            'montoOriginal' => 10,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-11-07',
            'estado' => '3',
            'descripcion' => 'Otro pago',
            'moneda_idmoneda' => 2,
        ]);

        $data = $this->controller()->getPaymentDetails('1')->getData(true)['data'];

        $this->assertTrue($data['mostrar_monto_cancelado']);
        $this->assertSame(31.0, (float) $data['monto_cancelado']);
        $this->assertSame('S/', $data['monto_cancelado_simbolo']);
        $this->assertSame(1, (int) $data['payment_currency_id']);

        $groupedDetails = $this->controller()->getPaymentDetails('3')->getData(true)['data'];
        $this->assertSame(31.0, (float) $groupedDetails['monto_cancelado']);
    }

    public function test_service_payment_details_extract_the_comment_from_bank_information(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('detallecxc')->where('iddetalleCXC', 2)->update([
            'descripcion' => 'Pago Servicio de prueba',
        ]);
        DB::table('bancos')->where('idbancos', 2)->update([
            'informacion' => 'Pago Servicio de prueba (Comentario del cliente)',
        ]);

        $response = $this->controller()->getPaymentDetails('1');
        $data = $response->getData(true)['data'];

        $this->assertSame('Comentario del cliente', $data['detalle_descripcion']);
    }

    public function test_service_payment_details_return_the_saved_observation_even_when_bank_information_is_truncated(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();
        $comment = 'Comentario guardado de forma exacta';
        DB::table('detallecxc')->where('iddetalleCXC', 2)->update([
            'descripcion' => 'Obs: ' . $comment,
        ]);
        DB::table('bancos')->where('idbancos', 2)->update([
            'informacion' => 'Pago Servicio de prueba (' . str_repeat('Texto largo ', 12),
        ]);

        $response = $this->controller()->getPaymentDetails('1');
        $data = $response->getData(true)['data'];

        $this->assertSame($comment, $data['detalle_descripcion']);
    }

    public function test_payment_details_return_separate_withholding_receipt_without_replacing_payment_details(): void
    {
        Storage::fake('public');
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 1)->update(['montoOriginal' => 1000]);
        DB::table('cliente')->where('idcliente', 'CLI-1')->update(['detraccion' => '1']);
        DB::table('bancos')->insert([
            'idbancos' => 3,
            'entidadBancaria_identidadBancaria' => 1,
            'informacion' => 'Retención pagada | Depósito reportado',
            'tipoMov' => 'T',
            'monto' => 30,
        ]);
        DB::table('detallecxc')->insert([
            'iddetalleCXC' => 3,
            'formaPago_idformaPago' => 2,
            'cuentasPorCobrar_idcuentasPorCobrar' => 1,
            'fechaPago' => '2026-10-08 10:15:00',
            'bancos_idbancos' => 3,
            'estado' => '1',
            'descripcion' => 'Retención aplicada',
        ]);
        Storage::disk('public')->put('comprobantes-pago/cxc/detraccion-retencion/retencion.png', 'proof');
        DB::table('evidenciapago')->insert([
            'fechaCarga' => '2026-10-08 10:15:00',
            'archivo' => 'comprobantes-pago/cxc/detraccion-retencion/retencion.png',
            'bancos_idbancos' => 3,
        ]);

        $response = $this->controller()->getPaymentDetails('1');
        $data = $response->getData(true)['data'];

        $this->assertSame('2026-10-07 10:15:00', $data['fechaPago']);
        $this->assertSame('Comentario guardado', $data['detalle_descripcion']);
        $this->assertTrue($data['withholding_applies']);
        $this->assertSame('retención', $data['withholding_type']);
        $this->assertSame('08/10/2026', $data['withholding_fecha']);
        $this->assertSame('S/ 30.00', $data['withholding_monto_display']);
        $this->assertSame('Depósito reportado', $data['withholding_comment']);
        $this->assertNotEmpty($data['withholding_comprobante_url']);
    }

    public function test_payment_details_identify_autodetraccion_and_return_its_comment_and_receipt(): void
    {
        Storage::fake('public');
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 1)->update(['montoOriginal' => 800]);
        DB::table('bancos')->insert([
            'idbancos' => 3,
            'entidadBancaria_identidadBancaria' => 1,
            'informacion' => 'Autodetracción pagada de CXC #1 | Ya se hizo la autodetracción',
            'tipoMov' => 'T',
            'monto' => 96,
        ]);
        DB::table('detallecxc')->insert([
            'iddetalleCXC' => 3,
            'formaPago_idformaPago' => 2,
            'cuentasPorCobrar_idcuentasPorCobrar' => 1,
            'fechaPago' => '2026-10-08 10:15:00',
            'bancos_idbancos' => 3,
            'estado' => '1',
            'descripcion' => 'Detracción aplicada: 96.00',
        ]);
        Storage::disk('public')->put('comprobantes-pago/cxc/autodetraccion.png', 'proof');
        DB::table('evidenciapago')->insert([
            'fechaCarga' => '2026-10-08 10:15:00',
            'archivo' => 'comprobantes-pago/cxc/autodetraccion.png',
            'bancos_idbancos' => 3,
        ]);

        $response = $this->controller()->getPaymentDetails('1');
        $data = $response->getData(true)['data'];

        $this->assertTrue($data['withholding_applies']);
        $this->assertSame('autodetracción', $data['withholding_type']);
        $this->assertSame('08/10/2026', $data['withholding_fecha']);
        $this->assertSame('S/ 96.00', $data['withholding_monto_display']);
        $this->assertSame('Ya se hizo la autodetracción', $data['withholding_comment']);
        $this->assertNotEmpty($data['withholding_comprobante_url']);
    }

    public function test_grouped_service_withholding_details_return_the_total_amount(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 1)->update([
            'montoOriginal' => 960,
            'moneda_idmoneda' => 1,
        ]);
        DB::table('detallecxc')->where('iddetalleCXC', 2)->update([
            'fechaPago' => '2026-10-22 12:00:00',
        ]);

        foreach ([2, 3] as $cxcId) {
            DB::table('cuentasporcobrar')->insert([
                'idcuentasPorCobrar' => $cxcId,
                'cliente_idcliente' => 'CLI-1',
                'tipoCobro_idtipoCobros' => 5,
                'docReferencia' => 'F001-000001',
                'montoOriginal' => 100,
                'montoActual' => 0,
                'fechaCancelacion' => '2026-10-31',
                'estado' => '3',
                'descripcion' => 'CXC 01/10/2026 a 31/10/2026',
                'moneda_idmoneda' => 1,
            ]);
            $paymentBankId = DB::table('bancos')->insertGetId([
                'idbancos' => 10 + $cxcId,
                'entidadBancaria_identidadBancaria' => 2,
                'fechaRegistro' => '2026-10-22 12:00:00',
                'informacion' => 'Pago agrupado',
                'tipoMov' => 'I',
                'monto' => 100,
            ]);
            DB::table('detallecxc')->insert([
                'iddetalleCXC' => 10 + $cxcId,
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'moneda_idmoneda' => 1,
                'fechaPago' => '2026-10-22 12:00:00',
                'bancos_idbancos' => $paymentBankId,
                'estado' => '1',
            ]);
        }

        foreach ([[1, 7.15], [2, 50.00], [3, 57.85]] as [$cxcId, $amount]) {
            $withholdingBankId = DB::table('bancos')->insertGetId([
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-26 12:00:00',
                'informacion' => 'Detracción pagada de octubre 2026',
                'tipoMov' => 'T',
                'monto' => $amount,
            ]);
            DB::table('detallecxc')->insert([
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'moneda_idmoneda' => 1,
                'fechaPago' => '2026-10-26 12:00:00',
                'bancos_idbancos' => $withholdingBankId,
                'estado' => '1',
                'descripcion' => 'Detracción aplicada',
            ]);
        }

        $data = $this->controller()->getPaymentDetails('1')->getData(true)['data'];

        $this->assertSame('26/10/2026', $data['withholding_fecha']);
        $this->assertSame('S/ 115.00', $data['withholding_monto_display']);
    }

    public function test_payment_comment_and_proof_can_be_updated_or_removed_without_creating_a_payment(): void
    {
        Storage::fake('public');
        $this->createSchema();
        $this->createPaymentFixture();
        Storage::disk('public')->put('comprobantes-pago/cxc/old.png', 'old proof');
        DB::table('evidenciapago')->insert([
            'fechaCarga' => '2026-10-07 10:15:00',
            'archivo' => 'comprobantes-pago/cxc/old.png',
            'bancos_idbancos' => 2,
        ]);

        $request = Request::create('/', 'POST', [
            'descripcion' => 'Comentario actualizado',
            'eliminar_comprobante' => '0',
        ], [], [
            'comprobante' => UploadedFile::fake()->image('nuevo.png'),
        ], ['HTTP_ACCEPT' => 'application/json']);
        $updated = $this->controller()->updatePaymentDetails($request, '1');

        $this->assertTrue($updated->getData(true)['success']);
        $this->assertSame('Comentario actualizado', DB::table('bancos')->where('idbancos', 2)->value('informacion'));
        $newPath = DB::table('evidenciapago')->where('bancos_idbancos', 2)->value('archivo');
        $this->assertNotSame('comprobantes-pago/cxc/old.png', $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing('comprobantes-pago/cxc/old.png');
        $this->assertSame(2, DB::table('detallecxc')->count());

        $removeRequest = Request::create('/', 'POST', ['eliminar_comprobante' => '1']);
        $removed = $this->controller()->updatePaymentDetails($removeRequest, '1');

        $this->assertTrue($removed->getData(true)['success']);
        $this->assertSame(0, DB::table('evidenciapago')->where('bancos_idbancos', 2)->count());
        Storage::disk('public')->assertMissing($newPath);
        $this->assertSame(2, DB::table('detallecxc')->count());
    }

    public function test_quotation_payment_proof_stays_in_sync_when_edited_or_removed(): void
    {
        Storage::fake('public');
        $this->createSchema();
        $this->createPaymentFixture();
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('archivoPago')->nullable();
        });
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 1)
            ->update(['descripcion' => 'COTIZACION:CT-001']);
        Storage::disk('public')->put('comprobantes-pago/cxc/cotizacion-original.png', 'original');
        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT-001',
            'archivoPago' => 'comprobantes-pago/cxc/cotizacion-original.png',
        ]);
        DB::table('bancos')->insert([
            'idbancos' => 3,
            'entidadBancaria_identidadBancaria' => 1,
            'informacion' => 'Retención pagada',
            'tipoMov' => 'T',
            'monto' => 0.60,
        ]);
        DB::table('detallecxc')->insert([
            'iddetalleCXC' => 3,
            'formaPago_idformaPago' => 2,
            'cuentasPorCobrar_idcuentasPorCobrar' => 1,
            'fechaPago' => '2026-10-08 10:15:00',
            'bancos_idbancos' => 3,
            'estado' => '1',
            'descripcion' => 'Retención aplicada',
        ]);

        $details = $this->controller()->getPaymentDetails('1')->getData(true)['data'];
        $this->assertStringEndsWith(
            '/comprobantes-pago/cxc/cotizacion-original.png',
            $details['comprobante_url']
        );

        $updated = $this->controller()->updatePaymentDetails(
            Request::create('/', 'POST', ['eliminar_comprobante' => '0'], [], [
                'comprobante' => UploadedFile::fake()->image('cotizacion-nuevo.png'),
            ], ['HTTP_ACCEPT' => 'application/json']),
            '1'
        );

        $this->assertTrue($updated->getData(true)['success']);
        $newPath = DB::table('cotizacion')->where('nroCotizacion', 'CT-001')->value('archivoPago');
        $this->assertNotSame('comprobantes-pago/cxc/cotizacion-original.png', $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing('comprobantes-pago/cxc/cotizacion-original.png');

        $removed = $this->controller()->updatePaymentDetails(
            Request::create('/', 'POST', ['eliminar_comprobante' => '1']),
            '1'
        );

        $this->assertTrue($removed->getData(true)['success']);
        $this->assertNull(DB::table('cotizacion')->where('nroCotizacion', 'CT-001')->value('archivoPago'));
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_editing_a_service_payment_updates_its_saved_observation(): void
    {
        $this->createSchema();
        $this->createPaymentFixture();
        DB::table('detallecxc')->where('iddetalleCXC', 2)->update([
            'descripcion' => 'Obs: Comentario anterior',
        ]);

        $request = Request::create('/', 'POST', [
            'descripcion' => 'Comentario actualizado',
            'eliminar_comprobante' => '0',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $updated = $this->controller()->updatePaymentDetails($request, '1');

        $this->assertTrue($updated->getData(true)['success']);
        $this->assertSame('Obs: Comentario actualizado', DB::table('detallecxc')->where('iddetalleCXC', 2)->value('descripcion'));

        $response = $this->controller()->getPaymentDetails('1');
        $this->assertSame('Comentario actualizado', $response->getData(true)['data']['detalle_descripcion']);
    }

    private function createSchema(): void
    {
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
            $table->string('detraccion')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->string('docReferencia')->nullable();
            $table->decimal('montoOriginal', 10, 2);
            $table->decimal('montoActual', 10, 2);
            $table->date('fechaCancelacion')->nullable();
            $table->string('estado');
            $table->string('descripcion')->nullable();
            $table->integer('moneda_idmoneda');
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('formaPago_idformaPago');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('moneda_idmoneda')->nullable();
            $table->dateTime('fechaPago')->nullable();
            $table->integer('bancos_idbancos')->nullable();
            $table->string('estado')->nullable();
            $table->string('descripcion')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->integer('entidadBancaria_identidadBancaria');
            $table->dateTime('fechaRegistro')->nullable();
            $table->string('informacion');
            $table->string('tipoMov')->nullable();
            $table->decimal('monto', 10, 2)->nullable();
        });
        Schema::create('entidadbancaria', function (Blueprint $table): void {
            $table->increments('identidadBancaria');
            $table->string('razonSocial');
        });
        Schema::create('formapago', function (Blueprint $table): void {
            $table->increments('idformaPago');
            $table->string('detalle');
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('simbolo');
        });
        Schema::create('evidenciapago', function (Blueprint $table): void {
            $table->increments('idevidenciaPago');
            $table->dateTime('fechaCarga');
            $table->string('archivo');
            $table->integer('bancos_idbancos');
        });
    }

    private function createPaymentFixture(): void
    {
        DB::table('cliente')->insert([
            'idcliente' => 'CLI-1',
            'razonSocial' => 'Cliente de prueba',
            'detraccion' => '0',
        ]);
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 1,
            'cliente_idcliente' => 'CLI-1',
            'tipoCobro_idtipoCobros' => 5,
            'docReferencia' => 'F001-000001',
            'montoOriginal' => 20,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-11-07',
            'estado' => '3',
            'descripcion' => 'Periodo de prueba',
            'moneda_idmoneda' => 1,
        ]);
        DB::table('entidadbancaria')->insert([
            ['identidadBancaria' => 1, 'razonSocial' => 'Banco anterior'],
            ['identidadBancaria' => 2, 'razonSocial' => 'Banco guardado'],
        ]);
        DB::table('formapago')->insert([
            ['idformaPago' => 1, 'detalle' => 'Forma anterior'],
            ['idformaPago' => 2, 'detalle' => 'Forma guardada'],
        ]);
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        DB::table('bancos')->insert([
            ['idbancos' => 1, 'entidadBancaria_identidadBancaria' => 1, 'informacion' => 'Pago anterior', 'tipoMov' => 'I', 'monto' => 10],
            ['idbancos' => 2, 'entidadBancaria_identidadBancaria' => 2, 'informacion' => 'Comentario guardado', 'tipoMov' => 'I', 'monto' => 20],
        ]);
        DB::table('detallecxc')->insert([
            [
                'iddetalleCXC' => 1,
                'formaPago_idformaPago' => 1,
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'moneda_idmoneda' => 1,
                'fechaPago' => '2026-10-06 10:15:00',
                'bancos_idbancos' => 1,
                'estado' => '1',
            ],
            [
                'iddetalleCXC' => 2,
                'formaPago_idformaPago' => 2,
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'moneda_idmoneda' => 1,
                'fechaPago' => '2026-10-07 10:15:00',
                'bancos_idbancos' => 2,
                'estado' => '1',
            ],
        ]);
    }

    private function controller(): CuentasPorCobrarController
    {
        return new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
    }
}
