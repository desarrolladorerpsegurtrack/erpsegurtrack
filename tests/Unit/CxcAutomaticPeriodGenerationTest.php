<?php

namespace Tests\Unit;

use App\Services\CxcService;
use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CuentasPorCobrarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CxcAutomaticPeriodGenerationTest extends TestCase
{
    public function test_period_matching_does_not_use_a_document_shared_with_future_periods(): void
    {
        $history = collect([
            (object) [
                'idcuentasPorCobrar' => 1722,
                'estado' => '1',
                'descripcion' => 'REN-CXC-1440 P 23/10/2026 a 23/11/2026 SC-41',
            ],
            (object) [
                'idcuentasPorCobrar' => 1440,
                'estado' => '4',
                'descripcion' => 'CXC 23/09/2026 a 23/10/2026 SC-41',
            ],
        ]);
        $service = new CuentasPorCobrarService();
        $matcher = new \ReflectionMethod($service, 'matchHistoricalCharge');
        $activeStates = ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];

        $octoberCxc = $matcher->invoke($service, $history, $activeStates, '23/09/2026 a 23/10/2026');
        $novemberCxc = $matcher->invoke($service, $history, $activeStates, '23/10/2026 a 23/11/2026');

        $this->assertSame(1440, $octoberCxc->idcuentasPorCobrar);
        $this->assertSame(1722, $novemberCxc->idcuentasPorCobrar);
    }

    public function test_historical_cxc_month_comes_from_end_date_not_user_description(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCxc');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        $service = new CuentasPorCobrarService();
        $rows = collect([
            $this->serviceCxcRow(
                101,
                'REN-CXC-100 P 23/10/2026 a 23/11/2026 SC-41',
                '2026-11-23',
                '1',
                null
            ),
        ]);

        $decorate = new \ReflectionMethod($service, 'decorateServices');
        $decoratedRows = $decorate->invoke($service, $rows, [[], [], []]);
        $history = collect([
            (object) [
                'idcuentasPorCobrar' => 100,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '4',
                'descripcion' => 'Descripción escrita por el usuario para la factura',
                'docReferencia' => 'FAC-OCT',
                'montoOriginal' => 45,
                'montoActual' => 45,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-10-23',
                'fecha_pago' => null,
            ],
            (object) [
                'idcuentasPorCobrar' => 101,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '1',
                'descripcion' => 'REN-CXC-100 P 23/10/2026 a 23/11/2026 SC-41',
                'docReferencia' => null,
                'montoOriginal' => 45,
                'montoActual' => 45,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-11-23',
                'fecha_pago' => null,
            ],
        ]);
        $appendHistory = new \ReflectionMethod($service, 'appendHistoricalPeriods');
        $decoratedRows = $appendHistory->invoke(
            $service,
            $decoratedRows,
            collect(['CLIENTE-1' => $history])
        );
        $group = new \ReflectionMethod($service, 'groupServices');
        $groups = $group->invoke(
            $service,
            $decoratedRows,
            collect(['CLIENTE-1' => $history]),
            [],
            collect()
        );
        $groupsByMonth = collect($groups)->keyBy('mes');

        $this->assertCount(2, $groups);
        $this->assertSame('octubre', $groupsByMonth->get('octubre')['mes']);
        $this->assertSame(100, $groupsByMonth->get('octubre')['cxc_id']);
        $this->assertSame('FAC-OCT', $groupsByMonth->get('octubre')['documento']);
        $this->assertSame(101, $groupsByMonth->get('noviembre')['cxc_id']);
        $this->assertSame('-', $groupsByMonth->get('noviembre')['documento']);
        $this->assertSame('2026-10-23', $groupsByMonth->get('octubre')['fecha_fin_iso']);
        $this->assertSame('2026-11-23', $groupsByMonth->get('noviembre')['fecha_fin_iso']);
        $this->assertSame(
            '-',
            $groupsByMonth->get('noviembre')['tipos_servicios'][0]['vehiculos'][0]['doc_referencia']
        );
    }

    public function test_grouped_cxc_totals_and_historical_services_keep_their_own_devices(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCxc');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        Schema::create('detalle_serviciodispositivo', function (Blueprint $table): void {
            $table->increments('iddetalle_serviciodispositivo');
            $table->integer('servicioCliente_idservicioCliente');
            $table->integer('dispositivoCliente_iddispositivoCliente');
        });
        Schema::create('detnumerosdispositivo', function (Blueprint $table): void {
            $table->increments('iddetNumerosDispositivo');
            $table->integer('dispositivoCliente_iddispositivoCliente');
            $table->string('numeroTelefonico_numeroTelefonico');
        });
        DB::table('detalle_serviciodispositivo')->insert([
            ['servicioCliente_idservicioCliente' => 41, 'dispositivoCliente_iddispositivoCliente' => 7000],
            ['servicioCliente_idservicioCliente' => 41, 'dispositivoCliente_iddispositivoCliente' => 7001],
            ['servicioCliente_idservicioCliente' => 42, 'dispositivoCliente_iddispositivoCliente' => 8002],
        ]);
        DB::table('detnumerosdispositivo')->insert([
            ['dispositivoCliente_iddispositivoCliente' => 7000, 'numeroTelefonico_numeroTelefonico' => '999-000-000'],
            ['dispositivoCliente_iddispositivoCliente' => 7001, 'numeroTelefonico_numeroTelefonico' => '999-111-111'],
            ['dispositivoCliente_iddispositivoCliente' => 8002, 'numeroTelefonico_numeroTelefonico' => '999-222-222'],
        ]);
        $service = new CuentasPorCobrarService();
        $firstService = $this->serviceCxcRow(
            301,
            'REN-CXC-201 P 13/08/2026 a 13/09/2026 SC-41',
            '2026-09-13',
            '1',
            null
        );
        $firstService->idservicioCliente = 41;
        $firstService->cxc_montoOriginal = 100;
        $firstService->cxc_montoActual = 100;
        $firstService->monto = 100;
        $firstService->vehiculo_placa = 'ABC-123';
        $secondService = clone $firstService;
        $secondService->idcuentasPorCobrar = 302;
        $secondService->idservicioCliente = 42;
        $secondService->cxc_descripcion = 'REN-CXC-202 P 13/08/2026 a 13/09/2026 SC-42';
        $secondService->cxc_montoOriginal = 95;
        $secondService->cxc_montoActual = 95;
        $secondService->monto = 95;

        $maps = (new \ReflectionMethod($service, 'loadDeviceMaps'))->invoke(
            $service,
            collect([$firstService, $secondService])
        );
        $rows = (new \ReflectionMethod($service, 'decorateServices'))->invoke(
            $service,
            collect([$firstService, $secondService]),
            $maps
        );
        $history = collect([
            (object) [
                'idcuentasPorCobrar' => 201,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '4',
                'descripcion' => 'CXC 13/07/2026 a 13/08/2026',
                'docReferencia' => null,
                'montoOriginal' => 100,
                'montoActual' => 100,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-08-13',
                'fecha_pago' => null,
            ],
            (object) [
                'idcuentasPorCobrar' => 202,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '4',
                'descripcion' => 'CXC 13/07/2026 a 13/08/2026',
                'docReferencia' => null,
                'montoOriginal' => 95,
                'montoActual' => 95,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-08-13',
                'fecha_pago' => null,
            ],
        ]);
        $rows = (new \ReflectionMethod($service, 'appendHistoricalPeriods'))
            ->invoke($service, $rows, collect(['CLIENTE-1' => $history]));
        $historicalRows = $rows->whereIn('idcuentasPorCobrar', [201, 202])->keyBy('idcuentasPorCobrar');

        $this->assertSame(41, $historicalRows->get(201)->idservicioCliente);
        $this->assertSame(42, $historicalRows->get(202)->idservicioCliente);
        $this->assertSame(7001, $historicalRows->get(201)->id_dispositivo);
        $this->assertSame(8002, $historicalRows->get(202)->id_dispositivo);
        $this->assertSame('999-111-111', $historicalRows->get(201)->numero_sim);
        $this->assertSame('999-222-222', $historicalRows->get(202)->numero_sim);

        $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
            $service,
            $rows,
            collect(['CLIENTE-1' => $history]),
            [],
            collect()
        );
        $august = collect($groups)->firstWhere('mes', 'agosto');

        $this->assertSame(195.0, $august['monto_total']);
        $this->assertSame(195.0, $august['monto_saldo']);
        $this->assertSame([202, 201], $august['cxc_ids']);
        $this->assertCount(2, $august['tipos_servicios'][0]['vehiculos']);
        $this->assertSame(202, $august['cxc_id']);
        $this->assertSame(195.0, $august['tipos_servicios'][0]['monto_subtotal']);
        $this->assertSame([202, 201], $august['tipos_servicios'][0]['cxc_ids']);
    }

    public function test_yearly_cxc_appears_in_the_month_of_its_stored_due_date_only(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCxc');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        $service = new CuentasPorCobrarService();
        $cxc = $this->serviceCxcRow(
            125,
            'REN-CXC-124 P 12/08/2024 a 12/08/2025 SC-41',
            '2025-09-12',
            '4',
            'FAC-ANUAL'
        );
        $rows = (new \ReflectionMethod($service, 'decorateServices'))
            ->invoke($service, collect([$cxc]), [[], [], []]);
        $history = collect([
            (object) [
                'idcuentasPorCobrar' => 125,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '4',
                'descripcion' => 'REN-CXC-124 P 12/08/2024 a 12/08/2025 SC-41',
                'docReferencia' => 'FAC-ANUAL',
                'montoOriginal' => 45,
                'montoActual' => 45,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2025-09-12',
                'fecha_pago' => null,
            ],
        ]);
        $rows = (new \ReflectionMethod($service, 'appendHistoricalPeriods'))
            ->invoke($service, $rows, collect(['CLIENTE-1' => $history]));
        $groups = (new \ReflectionMethod($service, 'groupServices'))
            ->invoke($service, $rows, collect(['CLIENTE-1' => $history]), [], collect());

        $this->assertCount(1, $groups);
        $this->assertSame('septiembre', $groups[0]['mes']);
        $this->assertSame(2025, $groups[0]['anio']);
        $this->assertSame('2025-09-12', $groups[0]['fecha_fin_iso']);
        $this->assertSame(125, $groups[0]['cxc_id']);
    }

    public function test_pending_october_cxc_creates_november_period_and_updates_service(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $octoberCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '1');

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $octoberCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $octoberCxcId)->first();
        $this->assertSame('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', $octoberCxc->descripcion);
        $this->assertSame('2026-10-23', $octoberCxc->fechaCancelacion);
        $this->assertNull(
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', '!=', $octoberCxcId)
                ->value('docReferencia')
        );
        $this->assertSame('2026-11-23', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('servicePeriodCases')]
    public function test_automatic_renewal_respects_service_period(int $periodDays, string $expectedEnd): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert([
            'idalmacen' => 1,
            'periodo' => (string) $periodDays,
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
        ]);
        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '1');

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $nextCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', '!=', 1)->first();
        $this->assertNotNull($nextCxc);
        $this->assertStringContainsString('23/10/2026 a ' . Carbon::parse($expectedEnd)->format('d/m/Y'), $nextCxc->descripcion);
        $this->assertSame($expectedEnd, DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public static function servicePeriodCases(): array
    {
        return [
            'monthly' => [30, '2026-11-23'],
            'every three months' => [90, '2027-01-23'],
            'every six months' => [180, '2027-04-23'],
            'yearly' => [365, '2027-10-23'],
            'every two years' => [730, '2028-10-23'],
        ];
    }

    public function test_existing_november_cxc_still_updates_service_from_october(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '1');
        $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41', '1');

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-11-23', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_previous_unpaid_period_is_available_when_managing_its_successor(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $previousCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026', '4');
        $currentCxcId = $this->createCxc('REN-CXC-' . $previousCxcId . ' P 23/10/2026 a 23/11/2026 SC-41', '1');

        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('detraccion')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->string('tipoMov')->nullable();
            $table->string('informacion')->nullable();
            $table->decimal('monto', 10, 2)->default(0);
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCxc');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos')->nullable();
            $table->integer('moneda_idmoneda')->nullable();
            $table->string('estado')->nullable();
            $table->date('fechaPago')->nullable();
        });
        DB::table('cliente')->insert(['idcliente' => 'CLIENTE-1', 'detraccion' => '0']);
        request()->query->set('service_ids', ['41']);
        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $currentCxcId)
            ->update(['fechaCancelacion' => '2026-11-23']);

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService());
        request()->query->set('current_cxc_id', (string) $previousCxcId);
        $octoberResponse = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame([], $octoberResponse['cxcs']);

        request()->query->set('current_cxc_id', (string) $currentCxcId);
        $response = $controller->getDeudasCliente('CLIENTE-1')->getData(true);

        $this->assertSame([$previousCxcId], array_column($response['cxcs'], 'idcuentasPorCobrar'));
    }

    public function test_previous_debts_are_selected_by_due_dates_not_description_chain_or_today(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('detraccion')->nullable();
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->string('tipoMov')->nullable();
            $table->string('informacion')->nullable();
            $table->decimal('monto', 10, 2)->default(0);
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCxc');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos')->nullable();
            $table->integer('moneda_idmoneda')->nullable();
            $table->string('estado')->nullable();
            $table->date('fechaPago')->nullable();
        });
        DB::table('cliente')->insert(['idcliente' => 'CLIENTE-1', 'detraccion' => '0']);
        $periodIds = [];
        foreach ([
            ['agosto', '2026-08-15', '4'],
            ['septiembre', '2026-09-15', '4'],
            ['octubre', '2026-10-15', '2'],
            ['noviembre', '2026-11-15', '1'],
        ] as [$period, $periodEnd, $state]) {
            $cxcId = $this->createCxc('Descripción escrita para ' . $period, $state);
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
                'fechaCancelacion' => $periodEnd,
                'fechaRegistro' => '2026-09-22',
            ]);
            $periodIds[$period] = $cxcId;
        }
        request()->query->set('service_ids', ['41']);

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService());
        request()->query->set('current_cxc_id', (string) $periodIds['noviembre']);
        $novemberDebts = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame(
            [$periodIds['octubre'], $periodIds['septiembre'], $periodIds['agosto']],
            array_column($novemberDebts['cxcs'], 'idcuentasPorCobrar')
        );

        request()->query->set('current_cxc_id', (string) $periodIds['octubre']);
        $octoberDebts = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame(
            [$periodIds['septiembre'], $periodIds['agosto']],
            array_column($octoberDebts['cxcs'], 'idcuentasPorCobrar')
        );

        request()->query->set('current_cxc_id', (string) $periodIds['septiembre']);
        $septemberDebts = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame(
            [$periodIds['agosto']],
            array_column($septemberDebts['cxcs'], 'idcuentasPorCobrar')
        );

        request()->query->set('current_cxc_id', (string) $periodIds['agosto']);
        $augustDebts = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame([], $augustDebts['cxcs']);
    }

    public function test_renewal_uses_the_edited_period_end_day(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        $currentCxc = DB::table('cuentasporcobrar')->first();

        (new CxcService())->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-10-01'),
            [41],
            5,
            '2026-10-25'
        );

        $nextCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', '!=', $currentCxc->idcuentasPorCobrar)->first();
        $this->assertStringContainsString('25/10/2026 a 25/11/2026', $nextCxc->descripcion);
        $this->assertSame('2026-11-25', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_edited_next_period_end_updates_existing_successor_instead_of_duplicating_it(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41', '1');
        $currentCxc = DB::table('cuentasporcobrar')->where('estado', '3')->first();
        $successorId = DB::table('cuentasporcobrar')->where('estado', '1')->value('idcuentasPorCobrar');

        $result = (new CxcService())->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-10-01'),
            [41],
            5,
            null,
            false,
            '2026-11-25'
        );

        $successor = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $successorId)->first();
        $this->assertSame((int) $successorId, $result);
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertStringContainsString('23/10/2026 a 25/11/2026', $successor->descripcion);
        $this->assertSame('2026-11-25', $successor->fechaCancelacion);
        $this->assertSame('2026-11-25', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_vehicle_price_update_only_changes_that_service_and_future_cxc_use_it(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 70,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'XYZ-987',
            'docReferencia' => null,
        ]);

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService());
        $response = $controller->updateServicePrice(Request::create('/', 'PATCH', ['monto' => '80.00']), 41);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(80.0, (float) DB::table('serviciocliente')->where('idservicioCliente', 41)->value('monto'));
        $this->assertSame(70.0, (float) DB::table('serviciocliente')->where('idservicioCliente', 42)->value('monto'));

        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        $currentCxc = DB::table('cuentasporcobrar')->first();
        (new CxcService())->renovarServicioVinculado($currentCxc, 0, Carbon::parse('2026-10-01'), [41], 5);

        $nextCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', '!=', $currentCxc->idcuentasPorCobrar)->first();
        $this->assertSame(80.0, (float) $nextCxc->montoOriginal);
    }

    public function test_cancelled_cxc_is_not_renewed_by_monthly_scheduler(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-10-23', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_advance_cancels_existing_cxcs_only_through_the_last_paid_period(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createCxc('REN-CXC-0 P 15/10/2026 a 15/11/2026 SC-41', '3');
        $this->createCxc('Factura emitida del periodo de diciembre', '1');
        $this->createCxc('REN-CXC-2 P 15/12/2026 a 15/01/2027 SC-41', '1');
        $this->createCxc('REN-CXC-3 P 15/01/2027 a 15/02/2027 SC-41', '1');
        DB::table('cuentasporcobrar')->where('estado', '1')->where('descripcion', 'Factura emitida del periodo de diciembre')
            ->update(['fechaCancelacion' => '2026-12-15']);
        $currentCxc = DB::table('cuentasporcobrar')->where('estado', '3')->first();
        $cancelAdvance = new \ReflectionMethod(CxcService::class, 'cancelarCxcsCubiertasPorAdelanto');

        $cancelAdvance->invoke(
            new CxcService(),
            $currentCxc,
            [41],
            '2026-11-15',
            '2027-01-15'
        );

        $states = DB::table('cuentasporcobrar')->orderBy('idcuentasPorCobrar')->pluck('estado')->all();
        $this->assertSame(['3', 'CANCELADO', 'CANCELADO', '1'], $states);
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('estado', 'CANCELADO')->sum('montoActual'));
    }

    public function test_editing_service_dates_updates_only_its_unpaid_cxc(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 70,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'XYZ-987',
            'docReferencia' => null,
        ]);
        $firstCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026', '1');
        $secondCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'CXC 23/09/2026 a 23/10/2026',
            'montoOriginal' => 70,
            'montoActual' => 70,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => '2026-10-23',
            'estado' => '1',
        ]);

        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-11-23']
        );

        $this->assertTrue($updated);
        $this->assertSame('2026-11-23', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->value('fechaCancelacion'));
        $this->assertSame('CXC 23/09/2026 a 23/11/2026 SC-41', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->value('descripcion'));
        $this->assertSame('2026-10-23', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $secondCxcId)->value('fechaCancelacion'));
        $this->assertSame('CXC 23/09/2026 a 23/10/2026', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $secondCxcId)->value('descripcion'));
    }

    public function test_service_cxc_with_payments_is_not_rewritten_when_service_dates_change(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $cxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '2');
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-11-23']
        );

        $this->assertFalse($updated);
        $this->assertSame('2026-10-23', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('fechaCancelacion'));
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('descripcion'));
    }

    public function test_ambiguous_legacy_cxcs_are_not_modified(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $firstCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026', '1');
        $secondCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026', '1');
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();

        try {
            (new CxcService())->synchronizeCurrentServiceChargeDates(
                41,
                $service,
                ['fecheVencimiento' => '2026-11-23']
            );
            $this->fail('Expected an ambiguity error for multiple matching CXC records.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ambiguous_service_cxc', $exception->getMessage());
        }

        $this->assertSame('2026-10-23', DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', [$firstCxcId, $secondCxcId])->value('fechaCancelacion'));
        $this->assertSame(0, DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', [$firstCxcId, $secondCxcId])->where('descripcion', 'like', '%SC-%')->count());
    }

    public function test_new_service_cxc_description_includes_service_marker(): void
    {
        $this->createSchema();

        $cxcId = (new CxcService())->crearCobroDesdeServicio([
            'cliente_idcliente' => 'CLIENTE-1',
            'monto' => 45,
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'idservicioCliente' => 41,
        ]);

        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('descripcion'));
    }

    public function test_same_period_and_amount_for_different_services_creates_distinct_cxcs(): void
    {
        $this->createSchema();
        $serviceData = [
            'cliente_idcliente' => 'CLIENTE-1',
            'monto' => 45,
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
        ];
        $cxcService = new CxcService();
        $firstId = $cxcService->crearCobroDesdeServicio($serviceData + ['idservicioCliente' => 41]);
        $secondId = $cxcService->crearCobroDesdeServicio($serviceData + ['idservicioCliente' => 42]);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstId)->value('descripcion'));
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-42', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $secondId)->value('descripcion'));
    }

    private function createSchema(): void
    {
        Schema::create('almacen', function (Blueprint $table): void {
            $table->increments('idalmacen');
            $table->string('periodo')->nullable();
        });
        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('simbolo')->nullable();
        });
        Schema::create('tipocobro', function (Blueprint $table): void {
            $table->increments('idtipoCobros');
            $table->string('recurrencia');
            $table->integer('tiempo');
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('estado');
            $table->date('fechaInicio');
            $table->date('fecheVencimiento');
            $table->integer('moneda_idmoneda');
            $table->integer('tipoCobro_idtipoCobros');
            $table->decimal('monto', 10, 2);
            $table->integer('almacen_idalmacen')->nullable();
            $table->string('vehiculo_placa')->nullable();
            $table->string('docReferencia')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('docReferencia')->nullable();
            $table->string('descripcion', 50);
            $table->decimal('montoOriginal', 10, 2);
            $table->decimal('montoActual', 10, 2);
            $table->integer('canCuotas')->nullable();
            $table->date('fechaRegistro')->nullable();
            $table->date('fechaCancelacion')->nullable();
            $table->string('estado');
        });

        DB::table('tipocobro')->insert([
            ['idtipoCobros' => 5, 'recurrencia' => 'M', 'tiempo' => 1],
            ['idtipoCobros' => 6, 'recurrencia' => 'M', 'tiempo' => 3],
            ['idtipoCobros' => 7, 'recurrencia' => 'M', 'tiempo' => 6],
            ['idtipoCobros' => 8, 'recurrencia' => 'M', 'tiempo' => 12],
            ['idtipoCobros' => 9, 'recurrencia' => 'M', 'tiempo' => 24],
        ]);
    }

    private function serviceCxcRow(int $cxcId, string $description, string $periodEnd, string $state, ?string $cxcDocument): object
    {
        return (object) [
            'idcuentasPorCobrar' => $cxcId,
            'cxc_estado' => $state,
            'cxc_docReferencia' => $cxcDocument,
            'cxc_montoOriginal' => 45,
            'cxc_montoActual' => 45,
            'cxc_tipoCobro_idtipoCobros' => 5,
            'cxc_descripcion' => $description,
            'cxc_canCuotas' => null,
            'cxc_fecha_pago' => null,
            'moneda_idmoneda' => 1,
            'cxc_fechaRegistro' => null,
            'cxc_fechaCancelacion' => $periodEnd,
            'idservicioCliente' => 41,
            'cliente_idcliente' => 'CLIENTE-1',
            'almacen_idalmacen' => null,
            'servicio_moneda_id' => 1,
            'docReferencia' => 'FAC-OCT',
            'cliente_nombre' => 'Cliente de prueba',
            'flag_integrador' => null,
            'vehiculo_placa' => 'ABC-123',
            'fechaInicio' => '2026-10-23',
            'fecheVencimiento' => '2026-11-23',
            'monto' => 45,
            'estado' => 'activo',
            'servicio_detalle' => 'Servicio de prueba',
            'servicio_periodo' => null,
            'vehiculo_marca' => '',
            'vehiculo_modelo' => '',
            'vehiculo_tracto' => '',
            'tipo_vehiculo' => '',
            'plataforma' => '',
            'moneda_simbolo' => 'S/',
        ];
    }

    private function createOctoberService(): void
    {
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 41,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 45,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'ABC-123',
            'docReferencia' => null,
        ]);
    }

    private function createCxc(string $description, string $state): int
    {
        return DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => $description,
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => '2026-10-23',
            'estado' => $state,
        ]);
    }
}