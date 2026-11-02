<?php

namespace Tests\Unit;

use App\Services\BancosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BancosMovementStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('entidadbancaria', function (Blueprint $table): void {
            $table->increments('identidadBancaria');
            $table->string('razonSocial')->nullable();
            $table->string('descripcion')->nullable();
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('simbolo')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->decimal('montoActual', 10, 2)->nullable();
            $table->string('estado')->nullable();
            $table->string('descripcion')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->integer('entidadBancaria_identidadBancaria')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->string('informacion');
            $table->decimal('monto', 10, 2);
            $table->string('tipoMov');
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos');
            $table->integer('moneda_idmoneda');
            $table->string('estado');
            $table->string('descripcion')->nullable();
        });
        DB::table('entidadbancaria')->insert([
            'identidadBancaria' => 1,
            'razonSocial' => 'Banco de prueba',
        ]);
        DB::table('cliente')->insert([
            'idcliente' => 'CLIENT-1',
            'razonSocial' => 'Cliente de prueba',
        ]);
        DB::table('moneda')->insert([
            'idmoneda' => 1,
            'simbolo' => 'S/',
        ]);
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 1,
            'cliente_idcliente' => 'CLIENT-1',
            'montoActual' => 0,
            'estado' => 'ANULADO',
            'descripcion' => 'COTIZACION:CT001',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['detallecxc', 'bancos', 'cuentasporcobrar', 'moneda', 'cliente', 'entidadbancaria'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_bulk_destroy_accepts_selected_movement_ids(): void
    {
        $movementId = DB::table('bancos')->insertGetId([
            'entidadBancaria_identidadBancaria' => 1,
            'fechaRegistro' => '2026-10-27 12:00:00',
            'informacion' => 'Movimiento para eliminación masiva',
            'monto' => 25,
            'tipoMov' => 'I',
        ]);

        $response = (new \App\Http\Controllers\BancosController(new BancosService()))
            ->bulkDestroy(Request::create('/modulos/finanzas/bancos/bulk-destroy', 'DELETE', [
                'selectedIds' => [(string) $movementId, 'invalid', (string) $movementId],
            ]));

        $this->assertFalse(DB::table('bancos')->where('idbancos', $movementId)->exists());
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
    }

    public function test_bank_movement_form_accepts_and_lists_database_movement_codes(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());

        $options = (new \ReflectionMethod($controller, 'movementTypeOptions'))->invoke($controller);
        $this->assertSame(['I', 'S', 'A'], array_column($options, 'value'));

        $validateData = new \ReflectionMethod($controller, 'validateData');
        foreach (['I', 'S', 'A'] as $movementType) {
            $request = Request::create('/bancos', 'POST', [
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-27 12:00:00',
                'informacion' => 'Movimiento de prueba',
                'monto' => '25.00',
                'tipoMov' => $movementType,
            ]);

            $data = $validateData->invoke($controller, $request);
            $this->assertSame($movementType, $data['tipoMov']);
        }
    }

    public function test_movements_with_different_cxc_but_same_period_and_comment_are_grouped_visually(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) [
                'idbancos' => 1,
                'cliente_ruc' => '20123456789',
                'cliente_nombre' => 'Cliente de prueba',
                'cxc_id' => 25,
                'servicio' => 'Pago mensual',
                'moneda_simbolo' => 'S/',
                'fechaRegistro' => '2026-10-27 14:05:00',
                'informacion' => 'Pago mensual octubre 2026 - Pago de servicio',
                'monto' => 5.31,
                'tipoMov' => 'I',
                'entidad_bancaria_nombre' => 'Banco de prueba',
            ],
            (object) [
                'idbancos' => 2,
                'cliente_ruc' => '20123456789',
                'cliente_nombre' => 'Cliente de prueba',
                'cxc_id' => 26,
                'servicio' => 'Pago mensual',
                'moneda_simbolo' => 'S/',
                'fechaRegistro' => '2026-10-28 10:30:00',
                'informacion' => 'Pago mensual octubre 2026 - Pago de servicio',
                'monto' => 10.00,
                'tipoMov' => 'I',
                'entidad_bancaria_nombre' => 'Banco de prueba',
            ],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertCount(2, $grouped->first()->relation_groups[0]['records']);
        $this->assertSame('28/10/2026 10:30', $grouped->first()->fechaRegistro_display);
        $this->assertSame('S/ 15.31', $grouped->first()->monto_display);
    }

    public function test_same_service_payment_on_same_date_is_grouped_even_when_vehicle_and_cxc_change(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 372, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 8836, 'servicio' => 'Pago de servicio', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-12-21 14:05:34', 'informacion' => 'Pago SERVICIO DE ALOJAMIENTO WIALON - F3B986 (Se esta cancelando 15.93 dolares)', 'monto' => 5.31, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco de prueba'],
            (object) ['idbancos' => 373, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 8837, 'servicio' => 'Pago de servicio', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-12-21 14:05:34', 'informacion' => 'Pago SERVICIO DE ALOJAMIENTO WIALON - B9L971 (Se esta cancelando 15.93 dolares)', 'monto' => 5.31, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco de prueba'],
            (object) ['idbancos' => 374, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 8838, 'servicio' => 'Pago de servicio', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-12-21 14:05:34', 'informacion' => 'Pago SERVICIO DE ALOJAMIENTO WIALON - B9I976 (Se esta cancelando 15.93 dolares)', 'monto' => 5.31, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco de prueba'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertCount(3, $grouped->first()->relation_groups[0]['records']);
        $this->assertSame('21/12/2026 14:05', $grouped->first()->fechaRegistro_display);
        $this->assertSame('S/ 15.93', $grouped->first()->monto_display);
    }

    public function test_movements_with_different_comments_are_not_grouped_together(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) [
                'idbancos' => 1,
                'cliente_ruc' => '20123456789',
                'cliente_nombre' => 'Cliente de prueba',
                'moneda_simbolo' => 'S/',
                'fechaRegistro' => '2026-10-27 14:05:00',
                'informacion' => 'Octubre 2026 - Cuota vehículo 1',
                'monto' => 100,
                'tipoMov' => 'I',
                'entidad_bancaria_nombre' => 'Banco de prueba',
            ],
            (object) [
                'idbancos' => 2,
                'cliente_ruc' => '20123456789',
                'cliente_nombre' => 'Cliente de prueba',
                'moneda_simbolo' => 'S/',
                'fechaRegistro' => '2026-10-28 10:30:00',
                'informacion' => 'Octubre 2026 - Cuota vehículo 2',
                'monto' => 200,
                'tipoMov' => 'I',
                'entidad_bancaria_nombre' => 'Banco de prueba',
            ],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(2, $grouped);
    }

    public function test_income_and_balance_credit_from_same_cxc_and_date_are_grouped(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 400, 'cliente_ruc' => '20606060606', 'cliente_nombre' => 'Cliente de prueba', 'cxc_id' => 725, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-28 10:10:00', 'informacion' => 'Pago CT00725 (Se esta pagando 100 soles demas.)', 'monto' => 500, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco'],
            (object) ['idbancos' => 401, 'cliente_ruc' => '20606060606', 'cliente_nombre' => 'Cliente de prueba', 'cxc_id' => 725, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-28 10:10:00', 'informacion' => 'Pago CT00725 (Se esta pagando 100 soles demas.)', 'monto' => 100, 'tipoMov' => 'A', 'entidad_bancaria_nombre' => 'Banco'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertCount(2, $grouped->first()->relation_groups[0]['records']);
        $this->assertSame('S/ 500.00', $grouped->first()->monto_display);
    }

    public function test_income_and_usage_of_credit_at_same_minute_are_grouped_by_client(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 209, 'cliente_ruc' => '20555555555', 'cliente_nombre' => 'ARCE ROMERO ANIBAL', 'cxc_id' => 8752, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-11-19 17:37:00', 'informacion' => 'Pago SERVICIO DE RASTREO GPS CORPORATIVO', 'monto' => 25, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco de Crédito del Perú'],
            (object) ['idbancos' => 210, 'cliente_ruc' => '', 'cliente_nombre' => 'ARCE ROMERO ANIBAL', 'cxc_id' => 8752, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-11-19 17:37:00', 'informacion' => 'Aplicación saldo a favor a CXC #8752', 'monto' => 25, 'tipoMov' => 'C', 'entidad_bancaria_nombre' => 'Banco de Crédito del Perú'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertCount(2, $grouped->first()->relation_groups[0]['records']);
        $this->assertSame('ARCE ROMERO ANIBAL', mb_strtoupper(trim((string) $grouped->first()->cliente_nombre), 'UTF-8'));
    }

    public function test_service_expiring_income_and_generated_credit_are_grouped_by_client_and_minute(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 400, 'cliente_ruc' => '20123456789', 'cliente_nombre' => 'EMPRESA DE TRANSPORTES MIGAMA', 'cxc_id' => 901, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-28 10:10:00', 'informacion' => 'Se esta pagando 100 soles demas.', 'monto' => 500, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco de Crédito del Perú'],
            (object) ['idbancos' => 401, 'cliente_ruc' => null, 'cliente_nombre' => '-', 'cxc_id' => 902, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-28 10:10:00', 'informacion' => 'Saldo a favor del cliente por sobrepago de octubre 2026 - EMPRESA DE TRANSPORTES MIGAMA', 'monto' => 100, 'tipoMov' => 'A', 'entidad_bancaria_nombre' => 'Banco de Crédito del Perú'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertCount(2, $grouped->first()->relation_groups[0]['records']);
        $this->assertSame('S/ 500.00', $grouped->first()->monto_display);
    }

    public function test_service_payments_and_generated_credit_at_same_minute_are_grouped(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 277, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 800, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-23 08:34:00', 'informacion' => 'Pago SERVICIO DE ALOJAMIENTO WIALON - B1 (Se paga cuota)', 'monto' => 10.18, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco'],
            (object) ['idbancos' => 278, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 801, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-23 08:34:00', 'informacion' => 'Pago SERVICIO DE ALOJAMIENTO WIALON - B2 (Se paga cuota)', 'monto' => 10.18, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco'],
            (object) ['idbancos' => 279, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 802, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-23 08:34:00', 'informacion' => 'Pago SERVICIO DE ALOJAMIENTO WIALON (Se paga cuota)', 'monto' => 10.64, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco'],
            (object) ['idbancos' => 280, 'cliente_ruc' => '20602308805', 'cliente_nombre' => 'SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'cxc_id' => 802, 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-23 08:34:00', 'informacion' => 'Saldo a favor del cliente por sobrepago de octubre 2026 - SATELITAL TRUCK E.I.R.L. - SATELTRUCK', 'monto' => 0.47, 'tipoMov' => 'A', 'entidad_bancaria_nombre' => 'Banco'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertCount(4, $grouped->first()->relation_groups[0]['records']);
        $this->assertSame('S/ 31.00', $grouped->first()->monto_display);
    }

    public function test_deduction_is_added_to_group_total_but_balance_credit_is_not(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 1, 'cliente_ruc' => '20123456789', 'cliente_nombre' => 'Cliente', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-27 10:00:00', 'informacion' => 'Pago CT00571', 'monto' => 880, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco'],
            (object) ['idbancos' => 2, 'cliente_ruc' => '20123456789', 'cliente_nombre' => 'Cliente', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-27 10:00:00', 'informacion' => 'Detracción aplicada de CT00571', 'monto' => 120, 'tipoMov' => 'T', 'entidad_bancaria_nombre' => 'Banco de la Nación'],
            (object) ['idbancos' => 3, 'cliente_ruc' => '20123456789', 'cliente_nombre' => 'Cliente', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-27 10:00:00', 'informacion' => 'Saldo a favor del cliente por sobrepago de CT00571', 'monto' => 50, 'tipoMov' => 'A', 'entidad_bancaria_nombre' => 'Banco'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertCount(1, $grouped);
        $this->assertSame('S/ 1,000.00', $grouped->first()->monto_display);
    }

    public function test_autodetraccion_is_not_added_to_group_total(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $groupBankMovements = new \ReflectionMethod($controller, 'groupBankMovements');
        $rows = collect([
            (object) ['idbancos' => 407, 'cliente_ruc' => '20606060606', 'cliente_nombre' => 'Cliente prueba', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-26 11:15:00', 'informacion' => 'Pago CT00742 - Ingreso', 'monto' => 1800, 'tipoMov' => 'I', 'entidad_bancaria_nombre' => 'Banco'],
            (object) ['idbancos' => 408, 'cliente_ruc' => '20606060606', 'cliente_nombre' => 'Cliente prueba', 'moneda_simbolo' => 'S/', 'fechaRegistro' => '2026-10-26 11:15:00', 'informacion' => 'Autodetracción pagada de CT00742', 'monto' => 216, 'tipoMov' => 'T', 'entidad_bancaria_nombre' => 'Banco de la Nación'],
        ]);

        $grouped = $groupBankMovements->invoke($controller, $rows);

        $this->assertSame('S/ 1,800.00', $grouped->first()->monto_display);
        $records = $grouped->first()->relation_groups[0]['records'];
        $this->assertSame('Información', $grouped->first()->relation_groups[0]['columns'][2]['label']);
        $this->assertSame('Autodetracción pagada de CT00742', $records[1]['informacion']);
    }

    public function test_group_detail_movement_sort_ranks_are_ordered(): void
    {
        $controller = new \App\Http\Controllers\BancosController(new BancosService());
        $rank = new \ReflectionMethod($controller, 'movementGroupSortRank');

        $this->assertSame(0, $rank->invoke($controller, 'I', 'Pago CT00571'));
        $this->assertSame(1, $rank->invoke($controller, 'T', 'Detracción aplicada'));
        $this->assertSame(1, $rank->invoke($controller, 'T', 'Retención aplicada'));
        $this->assertSame(2, $rank->invoke($controller, 'T', 'Autodetracción pagada'));
        $this->assertSame(3, $rank->invoke($controller, 'A', 'Saldo a favor'));
        $this->assertSame(4, $rank->invoke($controller, 'S', 'Salida'));
        $this->assertSame(5, $rank->invoke($controller, 'C', 'Uso de saldo a favor'));
        $this->assertSame(6, $rank->invoke($controller, 'I', '[ANULADO] Pago'));
    }

    public function test_stats_include_saldo_a_favor_total(): void
    {
        DB::table('bancos')->insert([
            'entidadBancaria_identidadBancaria' => 1,
            'fechaRegistro' => '2026-10-27 12:00:00',
            'informacion' => 'Saldo a favor del cliente c.c.',
            'monto' => 25,
            'tipoMov' => 'A',
        ]);

        $stats = (new BancosService())->getStats(Request::create('/bancos?tipoMov=A'));

        $this->assertSame(25.0, $stats['balances']['S/']['saldo_a_favor']);
    }

    public function test_annulled_movements_are_excluded_from_their_original_type_filter(): void
    {
        $activeId = DB::table('bancos')->insertGetId([
            'entidadBancaria_identidadBancaria' => 1,
            'fechaRegistro' => '2026-10-27 12:00:00',
            'informacion' => 'Saldo a favor del cliente c.c.',
            'monto' => 25,
            'tipoMov' => 'A',
        ]);
        $annulledId = DB::table('bancos')->insertGetId([
            'entidadBancaria_identidadBancaria' => 1,
            'fechaRegistro' => '2026-10-27 12:00:00',
            'informacion' => '[ANULADO] Saldo a favor del cliente c.c.',
            'monto' => 25,
            'tipoMov' => 'A',
        ]);

        $service = new BancosService();
        $applyFilters = new \ReflectionMethod($service, 'applyFilters');

        $balanceQuery = DB::table('bancos as b');
        $applyFilters->invoke($service, $balanceQuery, Request::create('/bancos?tipoMov=A'));
        $this->assertSame([$activeId], array_map('intval', $balanceQuery->pluck('b.idbancos')->all()));

        $annulledQuery = DB::table('bancos as b');
        $applyFilters->invoke($service, $annulledQuery, Request::create('/bancos?tipoMov=ANULADO'));
        $this->assertSame([$annulledId], array_map('intval', $annulledQuery->pluck('b.idbancos')->all()));
    }

    public function test_annulled_autodetraccion_is_filtered_and_not_double_counted_with_its_payment(): void
    {
        $movementIds = [];
        foreach ([
            ['informacion' => '[ANULADO] Anulación de cotización CT001 | Pago de cotización CT001', 'monto' => 801.60, 'tipoMov' => 'I'],
            ['informacion' => '[ANULADO] Anulación de cotización CT001 | Autodetracción pagada de CT001', 'monto' => 96, 'tipoMov' => 'T'],
        ] as $movement) {
            $movementIds[] = DB::table('bancos')->insertGetId($movement + [
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-19 12:00:00',
            ]);
        }
        foreach ($movementIds as $movementId) {
            DB::table('detallecxc')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'bancos_idbancos' => $movementId,
                'moneda_idmoneda' => 1,
                'estado' => '0',
            ]);
        }

        $service = new BancosService();
        $stats = $service->getStats(Request::create('/bancos'));
        $this->assertSame(0.0, $stats['balances']['S/']['balance_positivo']);
        $this->assertSame(0.0, $stats['balances']['S/']['balance_negativo']);
        $this->assertSame(801.6, $stats['balances']['S/']['anulado_revertido']);

        $applyFilters = new \ReflectionMethod($service, 'applyFilters');
        $annulledQuery = DB::table('bancos as b');
        $applyFilters->invoke($service, $annulledQuery, Request::create('/bancos?tipoMov=ANULADO'));
        $this->assertSame($movementIds, array_map('intval', $annulledQuery->pluck('b.idbancos')->all()));

        $autoQuery = DB::table('bancos as b');
        $applyFilters->invoke($service, $autoQuery, Request::create('/bancos?tipoMov=AUTODETRACCION'));
        $this->assertSame([], array_map('intval', $autoQuery->pluck('b.idbancos')->all()));
    }

    public function test_active_autodetraccion_is_a_negative_balance_and_autoretencion_is_included(): void
    {
        foreach ([
            ['informacion' => 'Pago de cotización CT002', 'monto' => 801.60, 'tipoMov' => 'I'],
            ['informacion' => 'Autodetracción pagada de CT002', 'monto' => 96, 'tipoMov' => 'T'],
            ['informacion' => 'Autoretención pagada de CT003', 'monto' => 30, 'tipoMov' => 'T'],
        ] as $movement) {
            $movementId = DB::table('bancos')->insertGetId($movement + [
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-19 12:00:00',
            ]);
            DB::table('detallecxc')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'bancos_idbancos' => $movementId,
                'moneda_idmoneda' => 1,
                'estado' => '1',
            ]);
        }

        $stats = (new BancosService())->getStats(Request::create('/bancos'));
        $this->assertSame(801.6, $stats['balances']['S/']['balance_positivo']);
        $this->assertSame(126.0, $stats['balances']['S/']['balance_negativo']);
        $this->assertSame(0.0, $stats['balances']['S/']['anulado_revertido']);
    }

    public function test_bank_list_shows_one_row_when_a_movement_has_payment_and_pending_details(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('concat', fn(...$values): string => implode('', $values));
        $movementId = DB::table('bancos')->insertGetId([
            'entidadBancaria_identidadBancaria' => 1,
            'fechaRegistro' => '2026-10-21 12:00:00',
            'informacion' => 'Pago de servicio',
            'monto' => 66,
            'tipoMov' => 'I',
        ]);
        foreach (['1', '0'] as $state) {
            DB::table('detallecxc')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => 1,
                'bancos_idbancos' => $movementId,
                'moneda_idmoneda' => 1,
                'estado' => $state,
            ]);
        }

        $items = (new BancosService())->getList(Request::create('/bancos'), 25);

        $this->assertSame(1, $items->total());
        $this->assertSame(1, $items->getCollection()->count());
        $this->assertSame($movementId, (int) $items->getCollection()->first()->idbancos);
        $this->assertSame(66.0, (float) $items->getCollection()->first()->monto);
    }
}
