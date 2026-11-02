<?php

namespace Tests\Unit;

use App\Services\CxcService;
use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CuentasPorCobrarService;
use App\Services\CotizacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CxcAutomaticPeriodGenerationTest extends TestCase
{
    public function test_period_filters_limit_historical_charge_query(): void
    {
        Schema::create('period_filter_test', function (Blueprint $table): void {
            $table->increments('id');
            $table->date('fecha');
        });
        DB::table('period_filter_test')->insert([
            ['fecha' => '2026-10-06'],
            ['fecha' => '2026-11-06'],
            ['fecha' => '2025-10-06'],
        ]);

        $service = new CuentasPorCobrarService();
        $query = DB::table('period_filter_test');
        (new \ReflectionMethod($service, 'applyPeriodFilters'))->invoke(
            $service,
            $query,
            'fecha',
            ['service_months' => ['octubre'], 'service_year' => '2026', 'service_date' => '']
        );

        $this->assertSame([1], $query->pluck('id')->all());
    }

    public function test_service_period_filter_is_not_applied_when_all_months_and_years_are_selected(): void
    {
        $service = new CuentasPorCobrarService();
        $hasPeriodFilters = new \ReflectionMethod($service, 'hasServicePeriodFilters');
        $allMonths = [
            'enero',
            'febrero',
            'marzo',
            'abril',
            'mayo',
            'junio',
            'julio',
            'agosto',
            'septiembre',
            'octubre',
            'noviembre',
            'diciembre',
        ];

        $this->assertFalse($hasPeriodFilters->invoke($service, [
            'service_months' => $allMonths,
            'service_year' => '',
        ]));
        $this->assertTrue($hasPeriodFilters->invoke($service, [
            'service_months' => ['octubre'],
            'service_year' => '',
        ]));
        $this->assertTrue($hasPeriodFilters->invoke($service, [
            'service_months' => $allMonths,
            'service_year' => '2025',
        ]));
    }

    public function test_verificar_vencidos_only_changes_pending_receivables(): void
    {
        $this->createSchema();
        $pendingIds = [
            $this->createCxc('CXC pendiente numerica', '1'),
            $this->createCxc('CXC pendiente', 'PENDIENTE'),
        ];
        $otherStates = [
            '2',
            'FACTURADO',
            'NUEVO',
            'PagoCredito',
            'Pendiente Pago parcial',
            'Pendiente a credito',
            'Pendiente Autodetracción',
            'Pendiente Autoretención',
            'Pendiente a Detracción',
            'Pendiente a Retención',
        ];
        $otherStateIds = [];
        foreach ($otherStates as $state) {
            $otherStateIds[$state] = $this->createCxc('CXC ' . $state, $state);
        }
        DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', array_merge($pendingIds, array_values($otherStateIds)))
            ->update(['fechaCancelacion' => '2026-10-15']);

        $updatedCount = (new CxcService())->verificarYActualizarVencidos(Carbon::parse('2026-10-16'));

        $this->assertSame(2, (int) $updatedCount);
        foreach ($pendingIds as $pendingId) {
            $this->assertSame('4', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $pendingId)->value('estado'));
        }
        foreach ($otherStateIds as $state => $id) {
            $this->assertSame((string) $state, DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $id)->value('estado'));
        }
    }

    public function test_grouped_service_marker_matches_each_id_without_partial_matches(): void
    {
        $pattern = '/(^|[^A-Za-z0-9])SC-([0-9]+,)*%d([^0-9]|$)/';
        $description = 'REN-CXC-1440 P 23/10/2026 a 23/11/2026 SC-123,456';

        $this->assertSame(1, preg_match(sprintf($pattern, 123), $description));
        $this->assertSame(1, preg_match(sprintf($pattern, 456), $description));
        $this->assertSame(0, preg_match(sprintf($pattern, 45), $description));
        $this->assertSame(0, preg_match(sprintf($pattern, 456), 'CXC SC-4567'));
    }

    public function test_decorated_service_balances_are_allocated_by_linked_service_amount(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'servicioCliente_idservicioCliente' => 41, 'montoServicio' => 1],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'servicioCliente_idservicioCliente' => 42, 'montoServicio' => 2],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'servicioCliente_idservicioCliente' => 43, 'montoServicio' => 3],
        ]);

        $services = collect();
        foreach ([41, 42, 43] as $serviceId) {
            $service = $this->serviceCxcRow(
                900,
                'CXC SC-41,42,43',
                '2026-10-23',
                '1',
                null
            );
            $service->idservicioCliente = $serviceId;
            $service->cxc_montoOriginal = 100;
            $service->cxc_montoActual = 100;
            $service->monto = 1;
            $services->push($service);
        }

        $decorated = (new \ReflectionMethod(new CuentasPorCobrarService(), 'decorateServices'))
            ->invoke(new CuentasPorCobrarService(), $services, [[], [], []]);

        $this->assertSame([16.67, 33.33, 50.0], $decorated->pluck('service_cxc_monto_actual')->all());
    }

    public function test_vehicle_rows_display_the_cxc_price_instead_of_the_service_base_price(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        $service = $this->serviceCxcRow(
            900,
            'CXC 23/09/2026 a 23/10/2026 SC-41',
            '2026-10-23',
            '1',
            null
        );
        $service->monto = 40;
        $service->cxc_montoOriginal = 20;
        $service->cxc_montoActual = 10;
        $service->dias_restantes = 5;
        $service->moneda_simbolo = 'S/';

        $cxcService = new CuentasPorCobrarService();
        $decorated = (new \ReflectionMethod($cxcService, 'decorateServices'))
            ->invoke($cxcService, collect([$service]), [[], [], []]);
        $types = (new \ReflectionMethod($cxcService, 'serviceTypes'))
            ->invoke(
                $cxcService,
                collect(['Servicio de prueba' => $decorated]),
                'S/',
                0,
                ['1', '4'],
                [],
                collect(),
                []
            );

        $this->assertSame(40.0, (float) $decorated->first()->monto);
        $this->assertSame('S/ 20.00', $decorated->first()->monto_display);
        $this->assertSame(20.0, $types[0]['vehiculos'][0]['monto']);
        $this->assertSame('S/ 20.00', $types[0]['vehiculos'][0]['monto_display']);
    }

    public function test_cancelled_group_displays_prorated_cxc_total_instead_of_linked_base_prices(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });

        $services = collect();
        foreach ([41, 42, 43, 44] as $serviceId) {
            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => 900,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => 40,
            ]);
            $service = $this->serviceCxcRow(
                900,
                'REN-CXC-899 P 07/10/2026 a 30/11/2026 SC-41,42,43,44',
                '2026-11-30',
                '3',
                'F00-36541'
            );
            $service->idservicioCliente = $serviceId;
            $service->monto = 40;
            $service->cxc_montoOriginal = 128;
            $service->cxc_montoActual = 0;
            $service->cxc_fechaCancelacion = '2026-11-30';
            $service->moneda_simbolo = 'S/';
            $services->push($service);
        }

        $cxcService = new CuentasPorCobrarService();
        $decorated = (new \ReflectionMethod($cxcService, 'decorateServices'))
            ->invoke($cxcService, $services, [[], [], []]);
        $types = (new \ReflectionMethod($cxcService, 'serviceTypes'))
            ->invoke(
                $cxcService,
                collect(['Servicio de prueba' => $decorated]),
                'S/',
                0,
                ['1', '4'],
                [],
                collect(),
                [900 => [41, 42, 43, 44]]
            );

        $this->assertSame([32.0, 32.0, 32.0, 32.0], $decorated->pluck('monto')->all());
        $this->assertSame(128.0, (float) collect($types[0]['vehiculos'])->sum('monto'));
        $this->assertSame(
            ['S/ 32.00', 'S/ 32.00', 'S/ 32.00', 'S/ 32.00'],
            collect($types[0]['vehiculos'])->pluck('monto_display')->all()
        );
    }

    public function test_linked_service_ids_are_loaded_in_one_query_for_all_receivables(): void
    {
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
        });
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'servicioCliente_idservicioCliente' => 41],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'servicioCliente_idservicioCliente' => 42],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 901, 'servicioCliente_idservicioCliente' => 43],
        ]);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (
                preg_match('/^\s*select/i', $query->sql) === 1
                && str_contains($query->sql, 'from "cuentasporcobrar_serviciocliente"')
            ) {
                $queries[] = $query->sql;
            }
        });
        $services = collect([
            (object) ['idcuentasPorCobrar' => 900],
            (object) ['idcuentasPorCobrar' => 901],
            (object) ['idcuentasPorCobrar' => 900],
        ]);
        $linkedServiceIds = (new \ReflectionMethod(new CuentasPorCobrarService(), 'loadLinkedServiceIdsByCxc'))
            ->invoke(new CuentasPorCobrarService(), $services);

        $this->assertCount(1, $queries);
        $this->assertSame([41, 42], $linkedServiceIds[900]);
        $this->assertSame([43], $linkedServiceIds[901]);
    }

    public function test_latest_payment_dates_are_loaded_in_batches_and_include_credit_notes(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado');
            $table->dateTime('fechaPago')->nullable();
        });
        Schema::create('nota_credito_aplicacion', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado');
            $table->dateTime('fechaAplicacion')->nullable();
        });
        DB::table('detallecxc')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'estado' => '1', 'fechaPago' => '2026-10-10 10:00:00'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'estado' => '0', 'fechaPago' => '2026-10-30 10:00:00'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 901, 'estado' => '1', 'fechaPago' => '2026-10-12 10:00:00'],
        ]);
        DB::table('nota_credito_aplicacion')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => 900, 'estado' => '1', 'fechaAplicacion' => '2026-10-15 10:00:00'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 901, 'estado' => '0', 'fechaAplicacion' => '2026-10-20 10:00:00'],
        ]);

        $dates = (new \ReflectionMethod(new CuentasPorCobrarService(), 'latestPaymentDates'))
            ->invoke(new CuentasPorCobrarService(), collect([900, 901, 900]));

        $this->assertSame('2026-10-15 10:00:00', $dates[900]);
        $this->assertSame('2026-10-12 10:00:00', $dates[901]);
    }

    public function test_historical_charge_indexes_preserve_matching_and_previous_debt_results(): void
    {
        $charges = collect([
            (object) [
                'idcuentasPorCobrar' => 101,
                'estado' => '1',
                'descripcion' => 'REN-CXC-100 P 01/09/2026 a 01/10/2026 SC-41',
                'fechaCancelacion' => '2026-10-01',
                'montoActual' => 12.5,
                'moneda_idmoneda' => 1,
                'docReferencia' => 'F001-101',
            ],
            (object) [
                'idcuentasPorCobrar' => 102,
                'estado' => 'Pendiente a credito',
                'descripcion' => 'REN-CXC-101 P 01/10/2026 a 01/11/2026 SC-41',
                'fechaCancelacion' => '2026-11-01',
                'montoActual' => 7.5,
                'moneda_idmoneda' => 1,
                'docReferencia' => 'F001-102',
            ],
            (object) [
                'idcuentasPorCobrar' => 103,
                'estado' => '1',
                'descripcion' => 'Otro período 01/10/2026 a 01/11/2026',
                'fechaCancelacion' => '2026-11-01',
                'montoActual' => 100,
                'moneda_idmoneda' => 2,
                'docReferencia' => 'F001-103',
            ],
        ]);
        $service = new CuentasPorCobrarService();
        $indexes = (new \ReflectionMethod($service, 'indexHistoricalCharges'))
            ->invoke($service, collect(['CLIENTE-1' => $charges]));
        $historyIndex = $indexes['CLIENTE-1'];
        $matchedCharge = (new \ReflectionMethod($service, 'matchHistoricalCharge'))
            ->invoke($service, $historyIndex, '01/10/2026 a 01/11/2026', '2026-11-01');
        $previousDebt = (new \ReflectionMethod($service, 'previousOutstandingDebt'))
            ->invoke($service, $historyIndex, (object) [
                'idcuentasPorCobrar' => 103,
                'moneda_idmoneda' => 1,
                'descripcion' => 'REN-CXC-102 P 01/10/2026 a 01/11/2026',
            ]);

        $this->assertSame(102, $matchedCharge->idcuentasPorCobrar);
        $this->assertSame(20.0, $previousDebt);
    }

    public function test_group_payment_maps_each_cxc_to_its_services_and_keeps_legacy_fallback(): void
    {
        $service = new CxcService();
        $mapServices = new \ReflectionMethod($service, 'serviceIdsForCxc');
        $selectedIds = [41, 42, 43];
        $amounts = [41 => 50.0, 42 => 50.0, 43 => 60.0];

        $firstCxcServices = $mapServices->invoke(
            $service,
            (object) ['descripcion' => 'CXC SC-41,42', 'montoOriginal' => 100],
            $selectedIds,
            $amounts,
            [],
            false
        );
        $legacyCxcServices = $mapServices->invoke(
            $service,
            (object) ['descripcion' => 'CXC legado', 'montoOriginal' => 60],
            $selectedIds,
            $amounts,
            $firstCxcServices,
            false
        );
        $singleGroupedCxcServices = $mapServices->invoke(
            $service,
            (object) ['descripcion' => 'CXC agrupada', 'montoOriginal' => 160],
            $selectedIds,
            $amounts,
            [],
            true
        );

        $this->assertSame([41, 42], $firstCxcServices);
        $this->assertSame([43], $legacyCxcServices);
        $this->assertSame($selectedIds, $singleGroupedCxcServices);
    }

    public function test_invoice_link_keeps_all_services_when_group_description_is_replaced(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
            $table->primary(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente']);
        });

        $serviceIds = range(41, 62);
        foreach (array_slice($serviceIds, 1) as $serviceId) {
            DB::table('serviciocliente')->insert([
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-23',
                'fecheVencimiento' => '2026-10-23',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 50,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'ABC-' . $serviceId,
                'docReferencia' => null,
            ]);
        }
        $cxcId = $this->createCxc('REN-CXC-9 P 23/09/2026 a 23/10/2026 SC-41,42', '2');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
            'montoOriginal' => 120,
            'montoActual' => 120,
        ]);

        $cxcService = new CxcService();
        $cxcService->linkServicesToReceivables([$cxcId], $serviceIds, $cxcId);
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
            'descripcion' => 'FACTURA F001-123',
            'estado' => '3',
            'montoActual' => 0,
        ]);
        DB::table('serviciocliente')->update(['monto' => 60]);

        $services = DB::table('serviciocliente')->get()->map(function (object $service): object {
            $service->moneda_simbolo = 'S/';
            $service->servicio_detalle = 'Servicio de prueba';
            $service->servicio_periodo = null;
            $service->dias_restantes = -1;
            return $service;
        });
        $historical = (object) [
            'idcuentasPorCobrar' => $cxcId,
            'cliente_idcliente' => 'CLIENTE-1',
            'fechaCancelacion' => '2026-10-23',
            'descripcion' => 'FACTURA F001-123',
            'montoOriginal' => 120,
            'montoActual' => 0,
            'estado' => '3',
            'docReferencia' => 'F001-123',
            'fechaRegistro' => null,
            'moneda_idmoneda' => 1,
        ];

        $periodServices = (new \ReflectionMethod(CuentasPorCobrarService::class, 'appendHistoricalPeriods'))
            ->invoke(new CuentasPorCobrarService(), $services, collect(['CLIENTE-1' => collect([$historical])]));
        $linkedPeriodServices = $periodServices->where('idcuentasPorCobrar', $cxcId);

        $this->assertCount(22, $linkedPeriodServices);
        $this->assertSame($serviceIds, $linkedPeriodServices->pluck('idservicioCliente')->sort()->values()->all());
        $this->assertSame(1095.0, round((float) $linkedPeriodServices->sum('monto'), 2));
        $this->assertSame([45.0, 50.0], $linkedPeriodServices->pluck('monto')->unique()->sort()->values()->all());
        $this->assertSame(22, DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
            ->count());
    }

    public function test_cancelled_group_recovers_services_from_renewal_charges_after_invoice_replaced_its_marker(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        foreach ([42, 43] as $serviceId) {
            DB::table('serviciocliente')->insert([
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-23',
                'fecheVencimiento' => '2026-11-23',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 50,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'ABC-' . $serviceId,
                'docReferencia' => null,
            ]);
        }
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-23',
        ]);
        $cxcId = $this->createCxc('FACTURA F001-123', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
            'fechaCancelacion' => '2026-10-23',
            'montoOriginal' => 150,
            'montoActual' => 0,
        ]);
        foreach ([41, 42, 43] as $serviceId) {
            DB::table('cuentasporcobrar')->insert([
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 5,
                'moneda_idmoneda' => 1,
                'docReferencia' => null,
                'descripcion' => "REN-CXC-{$cxcId} P 23/10/2026 a 23/11/2026 SC-{$serviceId}",
                'montoOriginal' => 50,
                'montoActual' => 50,
                'canCuotas' => null,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-11-23',
                'estado' => '1',
            ]);
        }
        DB::table('serviciocliente')->update(['monto' => 60]);

        $services = DB::table('serviciocliente')->get()->map(function (object $service): object {
            $service->moneda_simbolo = 'S/';
            $service->servicio_detalle = 'Servicio de prueba';
            $service->servicio_periodo = null;
            $service->dias_restantes = -1;
            return $service;
        });
        $historical = (object) [
            'idcuentasPorCobrar' => $cxcId,
            'cliente_idcliente' => 'CLIENTE-1',
            'fechaCancelacion' => '2026-10-23',
            'descripcion' => 'FACTURA F001-123',
            'montoOriginal' => 150,
            'montoActual' => 0,
            'estado' => '3',
            'docReferencia' => 'F001-123',
            'fechaRegistro' => null,
            'moneda_idmoneda' => 1,
        ];

        $periodServices = (new \ReflectionMethod(CuentasPorCobrarService::class, 'appendHistoricalPeriods'))
            ->invoke(new CuentasPorCobrarService(), $services, collect(['CLIENTE-1' => collect([$historical])]));

        $this->assertCount(3, $periodServices->where('idcuentasPorCobrar', $cxcId));
        $this->assertSame([41, 42, 43], $periodServices
            ->where('idcuentasPorCobrar', $cxcId)
            ->pluck('idservicioCliente')
            ->sort()
            ->values()
            ->all());
        $this->assertSame([50.0], $periodServices
            ->where('idcuentasPorCobrar', $cxcId)
            ->pluck('monto')
            ->unique()
            ->values()
            ->all());
    }

    public function test_truncated_group_marker_still_links_every_service_by_period_total(): void
    {
        Schema::create('period_group_cxc', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->string('descripcion');
            $table->date('fechaCancelacion');
            $table->integer('moneda_idmoneda');
            $table->integer('tipoCobro_idtipoCobros');
            $table->decimal('montoOriginal', 10, 2);
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('estado');
            $table->date('fecheVencimiento');
            $table->integer('moneda_idmoneda');
            $table->decimal('monto', 10, 2);
        });
        DB::table('period_group_cxc')->insert([
            'idcuentasPorCobrar' => 10,
            'cliente_idcliente' => 'CLIENTE-1',
            'descripcion' => 'REN-CXC-9 P 27/11/2026 a 27/12/2026 SC-41,42',
            'fechaCancelacion' => '2026-12-27',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'montoOriginal' => 160,
        ]);
        DB::table('serviciocliente')->insert([
            ['idservicioCliente' => 41, 'cliente_idcliente' => 'CLIENTE-1', 'estado' => 'activo', 'fecheVencimiento' => '2026-12-27', 'moneda_idmoneda' => 1, 'monto' => 50],
            ['idservicioCliente' => 42, 'cliente_idcliente' => 'CLIENTE-1', 'estado' => 'activo', 'fecheVencimiento' => '2026-12-27', 'moneda_idmoneda' => 1, 'monto' => 50],
            ['idservicioCliente' => 43, 'cliente_idcliente' => 'CLIENTE-1', 'estado' => 'activo', 'fecheVencimiento' => '2026-12-27', 'moneda_idmoneda' => 1, 'monto' => 60],
        ]);

        $service = new CuentasPorCobrarService();
        $query = DB::table('period_group_cxc as c')
            ->join('serviciocliente as sc', 'sc.cliente_idcliente', '=', 'c.cliente_idcliente');
        (new \ReflectionMethod($service, 'matchAggregateCxcServices'))->invoke($service, $query);

        $this->assertSame([41, 42, 43], $query->orderBy('sc.idservicioCliente')->pluck('sc.idservicioCliente')->all());
    }

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

    public function test_current_service_does_not_load_older_cxc_with_the_same_service_marker(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado');
            $table->date('fechaPago')->nullable();
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
            $table->string('flag_integrador')->nullable();
            $table->boolean('detraccion')->default(false);
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('tracto')->nullable();
            $table->integer('tipoUnidad_idtable1')->nullable();
        });
        Schema::create('tipovehiculo', function (Blueprint $table): void {
            $table->increments('idtipoVehiculo');
            $table->string('nombre')->nullable();
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->increments('idtipoElemento');
            $table->integer('plataforma_idplataforma')->nullable();
        });
        Schema::create('plataforma', function (Blueprint $table): void {
            $table->increments('idplataforma');
            $table->string('nombrePlataforma')->nullable();
        });
        Schema::table('almacen', function (Blueprint $table): void {
            $table->string('detalle')->nullable();
            $table->integer('tipoElemento_idtipoElemento')->nullable();
        });
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-23',
        ]);
        $currentCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'REN-CXC-2 P 23/09/2026 a 23/10/2026 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-10-23',
            'estado' => '1',
        ]);
        $historicalCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CXC 23/08/2026 a 23/09/2026 SC-41',
            'montoOriginal' => 900,
            'montoActual' => 900,
            'fechaCancelacion' => '2026-09-23',
            'estado' => '1',
        ]);

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$values): string => implode('', array_map(
            static fn($value): string => (string) ($value ?? ''),
            $values
        )));
        $pdo->sqliteCreateFunction('regexp', static fn(string $pattern, string $value): int => preg_match(
            '/' . $pattern . '/',
            $value
        ) ?: 0);

        $service = new CuentasPorCobrarService();
        $services = (new \ReflectionMethod($service, 'loadServices'))->invoke($service);

        $this->assertSame([$currentCxcId], $services->pluck('idcuentasPorCobrar')->all());
        $this->assertNotContains($historicalCxcId, $services->pluck('idcuentasPorCobrar')->all());
    }

    public function test_pending_withholding_cxc_joins_services_by_invoice_without_a_period_date(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado');
            $table->date('fechaPago')->nullable();
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
            $table->string('flag_integrador')->nullable();
            $table->boolean('detraccion')->default(false);
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('tracto')->nullable();
            $table->integer('tipoUnidad_idtable1')->nullable();
        });
        Schema::create('tipovehiculo', function (Blueprint $table): void {
            $table->increments('idtipoVehiculo');
            $table->string('nombre')->nullable();
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->increments('idtipoElemento');
            $table->integer('plataforma_idplataforma')->nullable();
        });
        Schema::create('plataforma', function (Blueprint $table): void {
            $table->increments('idplataforma');
            $table->string('nombrePlataforma')->nullable();
        });
        Schema::table('almacen', function (Blueprint $table): void {
            $table->string('detalle')->nullable();
            $table->integer('tipoElemento_idtipoElemento')->nullable();
        });
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'docReferencia' => 'F001-RET',
        ]);
        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => 'F001-RET',
            'descripcion' => 'Factura emitida',
            'montoOriginal' => 45,
            'montoActual' => 5.40,
            'fechaCancelacion' => null,
            'estado' => 'Pendiente Autoretención',
        ]);

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$values): string => implode('', array_map(
            static fn($value): string => (string) ($value ?? ''),
            $values
        )));
        $pdo->sqliteCreateFunction('regexp', static fn(string $pattern, string $value): int => preg_match(
            '/' . $pattern . '/',
            $value
        ) ?: 0);

        $service = new CuentasPorCobrarService();
        $services = (new \ReflectionMethod($service, 'loadServices'))->invoke($service);
        $decorated = (new \ReflectionMethod($service, 'decorateServices'))->invoke($service, $services, [[], [], []]);
        $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
            $service,
            $decorated,
            collect(),
            [],
            collect()
        );

        $this->assertSame([$cxcId], $services->pluck('idcuentasPorCobrar')->all());
        $this->assertSame([41], $services->pluck('idservicioCliente')->all());
        $this->assertSame('Pendiente Autoretención', $services->first()->cxc_estado);
        $this->assertSame('Pendiente Autoretención', $groups[0]['estado_pago']);
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

    public function test_cancelled_period_stays_in_its_month_and_shows_payment_date_from_next_month(): void
    {
        $service = new CuentasPorCobrarService();
        $currentService = $this->serviceCxcRow(
            101,
            'REN-CXC-100 P 23/10/2026 a 23/11/2026 SC-41',
            '2026-11-23',
            '1',
            null
        );
        $currentService->dias_restantes = 48;
        $history = collect(['CLIENTE-1' => collect([
            (object) [
                'idcuentasPorCobrar' => 100,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '3',
                'descripcion' => 'REN-CXC-99 P 23/09/2026 a 23/10/2026 SC-41',
                'docReferencia' => 'F001-123',
                'montoOriginal' => 45,
                'montoActual' => 0,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-10-23',
                'fecha_pago' => '2026-11-02 10:15:00',
            ],
        ])]);

        $rows = (new \ReflectionMethod($service, 'appendHistoricalPeriods'))->invoke(
            $service,
            collect([$currentService]),
            $history
        );
        $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
            $service,
            $rows,
            $history,
            [],
            collect()
        );
        $octoberGroup = collect($groups)->firstWhere('mes', 'octubre');

        $this->assertNotNull($octoberGroup);
        $this->assertSame('Cancelado', $octoberGroup['estado_pago']);
        $this->assertSame('-', $octoberGroup['fecha_registro']);
    }

    public function test_historical_service_price_comes_from_its_cxc_not_its_successor(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-23',
            'monto' => 50,
        ]);
        $historicalCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $historicalCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-10-23',
        ]);
        $this->createCxc('REN-CXC-' . $historicalCxcId . ' P 23/10/2026 a 23/11/2026 SC-41', '1');
        $service = DB::table('serviciocliente')->first();
        $service->moneda_simbolo = 'S/';
        $service->servicio_detalle = 'Servicio de prueba';
        $service->servicio_periodo = null;
        $historical = (object) [
            'idcuentasPorCobrar' => $historicalCxcId,
            'cliente_idcliente' => 'CLIENTE-1',
            'fechaCancelacion' => '2026-10-23',
            'descripcion' => 'CXC 23/09/2026 a 23/10/2026 SC-41',
            'montoOriginal' => 60,
            'montoActual' => 0,
            'estado' => '3',
            'docReferencia' => null,
            'fechaRegistro' => null,
            'moneda_idmoneda' => 1,
        ];

        $periodServices = (new \ReflectionMethod(CuentasPorCobrarService::class, 'appendHistoricalPeriods'))
            ->invoke(
                new CuentasPorCobrarService(),
                collect([$service]),
                collect(['CLIENTE-1' => collect([$historical])])
            );

        $historicalService = $periodServices->firstWhere('idcuentasPorCobrar', $historicalCxcId);
        $this->assertNotNull($historicalService);
        $this->assertSame(60.0, (float) $historicalService->monto);
    }

    public function test_editing_current_service_price_keeps_cancelled_period_price_in_service_listing(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-28',
            'monto' => 40,
        ]);
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado')->nullable();
            $table->integer('nCuota')->nullable();
        });
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });
        $cancelledCxcId = $this->createCxc('REN-CXC-9 P 23/09/2026 a 23/10/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cancelledCxcId)->update([
            'montoOriginal' => 40,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-10-23',
        ]);
        $pendingCxcId = $this->createCxc('REN-CXC-10 P 23/10/2026 a 28/11/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $pendingCxcId)->update([
            'montoOriginal' => 40,
            'montoActual' => 40,
            'fechaCancelacion' => '2026-11-28',
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $cancelledCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 40,
            ],
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $pendingCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 40,
            ],
        ]);

        try {
            $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
            $response = $controller->updateServicePrice(Request::create('/', 'PATCH', ['monto' => '50.00']), 41);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(40.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cancelledCxcId)->value('montoOriginal'));
            $this->assertSame(50.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $pendingCxcId)->value('montoOriginal'));

            $historicalRow = $this->serviceCxcRow(
                $cancelledCxcId,
                'REN-CXC-9 P 23/09/2026 a 23/10/2026 SC-41',
                '2026-10-23',
                '3',
                null
            );
            $historicalRow->monto = 50;
            $historicalRow->cxc_montoOriginal = 40;
            $historicalRow->cxc_montoActual = 0;
            $displayRows = (new \ReflectionMethod(CuentasPorCobrarService::class, 'decorateServices'))
                ->invoke(new CuentasPorCobrarService(), collect([$historicalRow]), [[], [], []]);

            $this->assertSame(40.0, (float) $displayRows->first()->monto);
            $this->assertSame('S/ 40.00', $displayRows->first()->monto_display);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_historical_cxcs_with_repeated_unit_amounts_link_to_distinct_services(): void
    {
        $service = new CuentasPorCobrarService();
        $firstService = $this->serviceCxcRow(201, 'CXC noviembre', '2026-11-27', '1', null);
        $firstService->monto = 50;
        $secondService = clone $firstService;
        $secondService->idservicioCliente = 42;
        $secondService->vehiculo_placa = 'XYZ-987';
        $secondService->dias_restantes = 51;
        $history = collect(['CLIENTE-1' => collect([
            (object) [
                'idcuentasPorCobrar' => 302,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '3',
                'descripcion' => 'Listo',
                'docReferencia' => 'F001-123',
                'montoOriginal' => 50,
                'montoActual' => 0,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fecha_pago' => null,
                'fechaCancelacion' => '2026-11-26',
            ],
            (object) [
                'idcuentasPorCobrar' => 301,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '3',
                'descripcion' => 'Listo',
                'docReferencia' => 'F001-123',
                'montoOriginal' => 50,
                'montoActual' => 0,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fecha_pago' => null,
                'fechaCancelacion' => '2026-11-27',
            ],
        ])]);

        $rows = (new \ReflectionMethod($service, 'appendHistoricalPeriods'))->invoke(
            $service,
            collect([$firstService, $secondService]),
            $history
        );
        $historicalRows = $rows->whereIn('idcuentasPorCobrar', [301, 302])->values();

        $this->assertSame([41, 42], $historicalRows->pluck('idservicioCliente')->sort()->values()->all());
        $this->assertNotContains(0, $historicalRows->pluck('idservicioCliente')->all());
    }

    public function test_unmatched_historical_cxc_does_not_create_a_placeholder_service(): void
    {
        $service = new CuentasPorCobrarService();
        $currentService = $this->serviceCxcRow(100, 'Current service', '2026-11-27', '1', null);
        $secondService = clone $currentService;
        $secondService->idservicioCliente = 42;
        $secondService->monto = 46;
        $history = collect(['CLIENTE-1' => collect([
            (object) [
                'idcuentasPorCobrar' => 101,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'Pendiente Autodetracción',
                'descripcion' => 'Legacy charge without a service marker',
                'docReferencia' => 'F001-101',
                'montoOriginal' => 999,
                'montoActual' => 120,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => null,
                'fechaCancelacion' => '2026-11-26',
                'fecha_pago' => null,
            ],
        ])]);

        $rows = (new \ReflectionMethod($service, 'appendHistoricalPeriods'))->invoke(
            $service,
            collect([$currentService, $secondService]),
            $history
        );

        $this->assertCount(2, $rows);
        $this->assertNotContains(0, $rows->pluck('idservicioCliente')->all());
        $this->assertNotContains(101, $rows->pluck('idcuentasPorCobrar')->all());
    }

    public function test_autoretencion_pending_state_is_presented_as_a_manageable_status(): void
    {
        $service = new CuentasPorCobrarService();
        $effectiveState = (new \ReflectionMethod($service, 'effectivePaymentState'))->invoke(
            $service,
            'Pendiente Autoretención',
            '2026-11-27',
            null,
            'Retención pendiente: 12.00'
        );
        $label = (new \ReflectionMethod($service, 'paymentState'))->invoke(
            $service,
            $effectiveState,
            false
        );

        $this->assertSame('PENDIENTE_AUTORETENCION', $effectiveState);
        $this->assertSame('Pendiente Autoretención', $label);
    }

    public function test_partial_and_credit_states_are_not_replaced_by_overdue_after_the_due_date(): void
    {
        $service = new CuentasPorCobrarService();
        $effectiveStateMethod = new \ReflectionMethod($service, 'effectivePaymentState');
        $paymentStateMethod = new \ReflectionMethod($service, 'paymentState');

        foreach ([
            ['Pendiente Pago parcial', 'PENDIENTE_PAGO_PARCIAL', 'Pendiente Pago parcial'],
            ['Pendiente a credito: 1/2', 'PENDIENTE_A_CREDITO', 'Pendiente a credito'],
            ['PagoCredito', 'PAGO_CREDITO', 'Pendiente cuota crédito'],
            ['Pendiente cuota crédito', 'PAGO_CREDITO', 'Pendiente cuota crédito'],
        ] as [$storedState, $expectedEffectiveState, $expectedLabel]) {
            $effectiveState = $effectiveStateMethod->invoke(
                $service,
                $storedState,
                '2020-01-01',
                null
            );

            $this->assertSame($expectedEffectiveState, $effectiveState);
            $this->assertSame($expectedLabel, $paymentStateMethod->invoke($service, $effectiveState, true));
        }
    }

    public function test_pending_withholding_status_is_not_overridden_by_an_uninvoiced_group_charge(): void
    {
        $invoicedService = $this->serviceCxcRow(901, 'Current period', '2026-11-27', '2', null);
        $invoicedService->idservicioCliente = 41;
        $invoicedService->cxc_docReferencia = null;
        $invoicedService->cxc_montoOriginal = 60;
        $invoicedService->cxc_montoActual = 60;
        $invoicedService->dias_restantes = 10;

        $pendingService = clone $invoicedService;
        $pendingService->idservicioCliente = 42;
        $pendingService->idcuentasPorCobrar = 902;
        $pendingService->cxc_estado = 'Pendiente Autodetracción';
        $pendingService->cxc_docReferencia = 'F001-902';
        $pendingService->cxc_montoOriginal = 100;
        $pendingService->cxc_montoActual = 12;
        $pendingService->dias_restantes = 10;

        $groups = (new \ReflectionMethod(new CuentasPorCobrarService(), 'groupServices'))->invoke(
            new CuentasPorCobrarService(),
            collect([$invoicedService, $pendingService]),
            collect(),
            [],
            collect()
        );

        $this->assertSame('Pendiente Autodetracción', $groups[0]['estado_pago']);
        $this->assertSame('Pendiente Autodetracción', $groups[0]['cxc_estado']);
        $this->assertSame(902, $groups[0]['cxc_id']);
        $this->assertSame(12.0, $groups[0]['monto_saldo']);
        $this->assertSame(12.0, $groups[0]['monto_total']);
        $this->assertSame('S/ 12.00', $groups[0]['monto_total_display']);
    }

    public function test_deferred_monthly_proration_is_not_capped_at_service_base_price_in_group_total(): void
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        Schema::create('tipocobro', function (Blueprint $table): void {
            $table->increments('idtipoCobros');
            $table->string('recurrencia');
            $table->integer('tiempo');
        });
        DB::table('tipocobro')->insert([
            'idtipoCobros' => 5,
            'recurrencia' => 'M',
            'tiempo' => 1,
        ]);
        try {
            $service = $this->serviceCxcRow(
                920,
                'REN-CXC-919 P 30/11/2026 a 31/12/2026 SC-41',
                '2026-12-31',
                '1',
                null
            );
            $service->fechaInicio = '2026-11-30';
            $service->fecheVencimiento = '2026-12-31';
            $service->monto = 8.85;
            $service->cxc_montoOriginal = 15.93;
            $service->cxc_montoActual = 15.93;
            $service->cxc_mod_monto = CxcService::MONTHLY_PRORATION_PRICE_MARKER;
            $service->servicio_periodo = '1 mes';
            $service->dias_restantes = 65;
            $service->moneda_simbolo = '$';

            $groups = (new \ReflectionMethod(new CuentasPorCobrarService(), 'groupServices'))->invoke(
                new CuentasPorCobrarService(),
                collect([$service]),
                collect(),
                [],
                collect(),
                [],
                [920 => [41]]
            );

            $this->assertSame(15.93, $groups[0]['monto_total']);
            $this->assertSame(15.93, $groups[0]['monto_saldo']);
            $this->assertSame('$ 15.93', $groups[0]['monto_total_display']);
            $this->assertSame(15.93, $groups[0]['tipos_servicios'][0]['monto_subtotal']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_service_groups_keep_the_requested_payment_status_order_after_filtering(): void
    {
        $statuses = [
            'Pendiente',
            'Cancelado',
            'Pendiente a Retención',
            'Vencido',
            'Pendiente Autoretención',
            'Facturado',
            'Pendiente cuota crédito',
            'Pendiente a credito',
            'Pendiente Autodetracción',
            'Pendiente Pago parcial',
            'Pendiente a Detracción',
        ];
        $groups = array_map(
            static fn(string $status): array => [
                'estado_pago' => $status,
                'cliente_nombre' => $status,
                'cliente_id' => '',
                'mes' => 'octubre',
                'anio' => 2026,
                'documento' => '-',
                'fecha_fin_iso' => '2026-10-15',
            ],
            $statuses
        );
        $controller = (new \ReflectionClass(CuentasPorCobrarController::class))->newInstanceWithoutConstructor();
        $orderedGroups = (new \ReflectionMethod($controller, 'filterServiceGroups'))->invoke(
            $controller,
            $groups,
            [
                'service_client' => '',
                'service_document' => '',
                'service_months' => [],
                'service_year' => '',
                'service_date' => '',
                'service_expiration_date' => '',
                'service_status' => '',
            ]
        );

        $this->assertSame([
            'Facturado',
            'Pendiente Pago parcial',
            'Pendiente a credito',
            'Pendiente cuota crédito',
            'Pendiente Autodetracción',
            'Pendiente Autoretención',
            'Pendiente a Detracción',
            'Pendiente a Retención',
            'Pendiente',
            'Vencido',
            'Cancelado',
        ], array_column($orderedGroups, 'estado_pago'));
    }

    public function test_service_date_filters_match_registration_and_expiration_dates_independently(): void
    {
        $groups = [
            [
                'estado_pago' => 'Pendiente',
                'cliente_nombre' => 'Cliente 1',
                'cliente_id' => 'CLIENTE-1',
                'mes' => 'noviembre',
                'anio' => 2026,
                'documento' => 'F001-1',
                'fecha_registro_iso' => '2026-11-16',
                'fecha_fin_iso' => '2026-11-23',
            ],
            [
                'estado_pago' => 'Pendiente',
                'cliente_nombre' => 'Cliente 2',
                'cliente_id' => 'CLIENTE-2',
                'mes' => 'noviembre',
                'anio' => 2026,
                'documento' => 'F001-2',
                'fecha_registro_iso' => '2026-11-17',
                'fecha_fin_iso' => '2026-11-23',
            ],
        ];
        $controller = (new \ReflectionClass(CuentasPorCobrarController::class))->newInstanceWithoutConstructor();
        $filteredGroups = (new \ReflectionMethod($controller, 'filterServiceGroups'))->invoke(
            $controller,
            $groups,
            [
                'service_client' => '',
                'service_document' => '',
                'service_months' => [],
                'service_year' => '',
                'service_date' => '2026-11-16',
                'service_expiration_date' => '2026-11-23',
                'service_status' => '',
            ]
        );

        $this->assertCount(1, $filteredGroups);
        $this->assertSame('CLIENTE-1', $filteredGroups[0]['cliente_id']);
    }

    public function test_payment_date_is_saved_to_the_successor_cxc_registration_date(): void
    {
        $this->createSchema();
        $nextCxcId = $this->createCxc('REN-CXC-100 P 23/10/2026 a 23/11/2026 SC-41', '1');
        $service = new CxcService();

        (new \ReflectionMethod($service, 'registrarFechaPagoSiguienteCxc'))->invoke(
            $service,
            $nextCxcId,
            '2026-11-02 10:15:00'
        );

        $this->assertSame(
            '2026-11-02',
            Carbon::parse(DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $nextCxcId)->value('fechaRegistro'))->toDateString()
        );
    }

    public function test_withholding_payment_date_is_saved_when_renewal_creates_a_new_cxc(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-23',
        ]);
        $currentCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'fechaCancelacion' => '2026-10-23',
        ]);

        $nextCxcId = (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first(),
            0,
            Carbon::parse('2026-11-02'),
            [41],
            5,
            null,
            false,
            null,
            '2026-11-02 10:15:00'
        );

        $this->assertNotNull($nextCxcId);
        $this->assertSame(
            '2026-11-02',
            Carbon::parse(DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $nextCxcId)->value('fechaRegistro'))->toDateString()
        );
    }

    public function test_successor_group_shows_its_stored_payment_date_as_registration_date(): void
    {
        $service = new CuentasPorCobrarService();
        $nextPeriodService = $this->serviceCxcRow(
            201,
            'REN-CXC-200 P 27/10/2026 a 27/11/2026 SC-41',
            '2026-11-27',
            '1',
            null
        );
        $nextPeriodService->cxc_fechaRegistro = '2026-11-02 10:15:00';
        $nextPeriodService->dias_restantes = 52;

        $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
            $service,
            collect([$nextPeriodService]),
            collect(),
            [],
            collect()
        );

        $this->assertSame('02/11/2026', $groups[0]['fecha_registro']);
    }

    public function test_cancelled_service_group_uses_payment_date_for_reversal_window_without_registration_date(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $service = new CuentasPorCobrarService();
            $cancelledService = $this->serviceCxcRow(
                202,
                'CXC 23/09/2026 a 23/10/2026 SC-41',
                '2026-10-23',
                '3',
                'F001-202'
            );
            $cancelledService->cxc_fechaRegistro = null;
            $cancelledService->cxc_fecha_pago = '2026-10-15 10:15:00';
            $cancelledService->dias_restantes = 1;

            $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
                $service,
                collect([$cancelledService]),
                collect(),
                [],
                collect()
            );

            $this->assertSame('-', $groups[0]['fecha_registro']);
            $this->assertTrue($groups[0]['puede_revertir']);

            $cancelledService->cxc_fecha_pago = '2026-10-08 10:15:00';
            $expiredGroups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
                $service,
                collect([$cancelledService]),
                collect(),
                [],
                collect()
            );
            $this->assertFalse($expiredGroups[0]['puede_revertir']);
        } finally {
            Carbon::setTestNow();
        }
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

    public function test_inactive_services_keep_cancelled_group_cxc_total_without_duplicating_vehicle_amounts(): void
    {
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('nCuota')->nullable();
            $table->string('estado')->nullable();
        });
        $first = $this->serviceCxcRow(
            300,
            'CXC 23/09/2026 a 23/10/2026 SC-41,42',
            '2026-10-23',
            'CANCELADO',
            'F001-300'
        );
        $first->estado = 'inactivo';
        $first->cxc_montoOriginal = 45;
        $first->cxc_montoActual = 0;
        $first->monto = 45;
        $second = clone $first;
        $second->idservicioCliente = 42;
        $second->vehiculo_placa = 'XYZ-987';
        $rows = (new \ReflectionMethod(new CuentasPorCobrarService(), 'decorateServices'))
            ->invoke(new CuentasPorCobrarService(), collect([$first, $second]), [[], [], []]);
        $groups = (new \ReflectionMethod(new CuentasPorCobrarService(), 'groupServices'))
            ->invoke(new CuentasPorCobrarService(), $rows, collect(), [], collect());

        $this->assertCount(0, $groups);
    }

    public function test_group_omits_stale_cxc_with_zero_balance_but_keeps_payable_receivables(): void
    {
        $payableService = $this->serviceCxcRow(
            301,
            'CXC 23/09/2026 a 23/10/2026 SC-41',
            '2026-10-23',
            '4',
            null
        );
        $staleService = clone $payableService;
        $staleService->idcuentasPorCobrar = 302;
        $staleService->idservicioCliente = 42;
        $staleService->cxc_montoActual = 0;
        $staleService->vehiculo_placa = 'XYZ-987';
        $zeroValueService = clone $payableService;
        $zeroValueService->idcuentasPorCobrar = 303;
        $zeroValueService->idservicioCliente = 43;
        $zeroValueService->cxc_montoOriginal = 0;
        $zeroValueService->cxc_montoActual = 0;
        $zeroValueService->monto = 0;
        $payableService->dias_restantes = 1;
        $staleService->dias_restantes = 1;
        $zeroValueService->dias_restantes = 1;

        $service = new CuentasPorCobrarService();
        $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
            $service,
            collect([$payableService, $staleService, $zeroValueService]),
            collect(),
            [],
            collect()
        );

        $this->assertCount(1, $groups);
        $this->assertSame([303, 301], $groups[0]['cxc_ids']);
        $this->assertSame([303, 301], $groups[0]['tipos_servicios'][0]['cxc_ids']);
        $this->assertSame([41, 43], $groups[0]['payment_service_ids']);
        $this->assertSame([41, 43], $groups[0]['tipos_servicios'][0]['service_ids']);
        $this->assertSame(45.0, (float) $groups[0]['monto_saldo']);
    }

    public function test_cancelled_group_preserves_each_service_price_instead_of_allocating_cxc_total(): void
    {
        $serviceRows = collect();
        foreach ([41 => 'ABC-123', 42 => 'XYZ-987', 43 => 'DEF-456'] as $serviceId => $plate) {
            $row = $this->serviceCxcRow(
                400,
                'REN-CXC-399 P 30/11/2026 a 30/12/2026 SC-41,42,43',
                '2026-12-30',
                '1',
                null
            );
            $row->idservicioCliente = $serviceId;
            $row->vehiculo_placa = $plate;
            $row->monto = 60;
            $row->cxc_montoOriginal = 180;
            $row->cxc_montoActual = 180;
            $row->dias_restantes = 1;
            $serviceRows->push($row);
        }

        $history = collect(['CLIENTE-1' => collect([
            (object) [
                'idcuentasPorCobrar' => 399,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => '3',
                'descripcion' => 'REN-CXC-398 P 30/10/2026 a 30/11/2026 SC-41,42,43',
                'docReferencia' => 'F001-399',
                'montoOriginal' => 120,
                'montoActual' => 0,
                'moneda_idmoneda' => 1,
                'fechaRegistro' => '2026-11-02',
                'fechaCancelacion' => '2026-11-30',
                'fecha_pago' => '2026-11-02',
            ],
        ])]);
        $service = new CuentasPorCobrarService();
        $rows = (new \ReflectionMethod($service, 'appendHistoricalPeriods'))->invoke(
            $service,
            $serviceRows,
            $history
        );
        $groups = (new \ReflectionMethod($service, 'groupServices'))->invoke(
            $service,
            $rows,
            $history,
            [],
            collect()
        );
        $november = collect($groups)->firstWhere('mes', 'noviembre');

        $this->assertNotNull($november);
        $this->assertSame(180.0, $november['monto_total']);
        $this->assertSame(180.0, $november['tipos_servicios'][0]['monto_subtotal']);
        $this->assertSame([60.0, 60.0, 60.0], array_column($november['tipos_servicios'][0]['vehiculos'], 'monto'));
        $this->assertSame(
            ['S/ 60.00', 'S/ 60.00', 'S/ 60.00'],
            array_column($november['tipos_servicios'][0]['vehiculos'], 'monto_display')
        );
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
        $nextCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $octoberCxcId)
            ->first();
        $this->assertSame(12.0, (float) $nextCxc->montoOriginal);
        $this->assertSame('2026-11-30', $nextCxc->fechaCancelacion);
        $this->assertSame(CxcService::MONTHLY_PRORATION_PRICE_MARKER, $nextCxc->mod_monto);
        $this->assertSame('2026-11-30', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_automatic_generation_only_advances_cxcs_ending_in_the_run_month(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2026-10-23',
            'fecheVencimiento' => '2026-11-23',
        ]);
        $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', 'PENDIENTE');
        $novemberCxcId = $this->createCxc('CXC 23/10/2026 a 23/11/2026 SC-41', 'FACTURADO');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $novemberCxcId)->update([
            'fechaCancelacion' => '2026-11-23',
        ]);

        $generated = (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-11-01'));

        $successors = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'REN-CXC-' . $novemberCxcId . ' P %')
            ->get();
        $this->assertSame(1, $generated);
        $this->assertCount(1, $successors);
        $this->assertSame('2026-12-31', $successors->first()->fechaCancelacion);
        $this->assertSame(3, DB::table('cuentasporcobrar')->count());
    }

    public function test_automatic_generation_uses_the_service_interval_and_skips_credit_children(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'tipoCobro_idtipoCobros' => 6,
            'fechaInicio' => '2026-08-15',
            'fecheVencimiento' => '2026-11-15',
        ]);
        $sourceCxcId = $this->createCxc('CXC 15/08/2026 a 15/11/2026 SC-41', 'Pendiente Pago parcial');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->update([
            'tipoCobro_idtipoCobros' => 6,
            'fechaCancelacion' => '2026-11-15',
        ]);
        DB::table('cuentasporcobrar')->insert([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 6,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CCXC:' . $sourceCxcId . ':2 SC-41',
            'montoOriginal' => 15,
            'montoActual' => 15,
            'fechaCancelacion' => '2026-11-15',
            'estado' => 'Pendiente cuota crédito',
        ]);

        $generated = (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-11-01'));

        $successor = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'REN-CXC-' . $sourceCxcId . ' P %')
            ->first();
        $this->assertSame(1, $generated);
        $this->assertNotNull($successor);
        $this->assertSame('2027-02-15', $successor->fechaCancelacion);
        $this->assertSame(3, DB::table('cuentasporcobrar')->count());
    }

    public function test_payment_updates_existing_successor_and_adds_new_services_without_resetting_payments(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2026-10-15',
            'fecheVencimiento' => '2026-12-31',
        ]);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-11-01',
            'fecheVencimiento' => '2026-11-15',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 60,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'XYZ-987',
            'docReferencia' => null,
        ]);
        $sourceCxcId = $this->createCxc('REN-CXC-0 P 15/10/2026 a 15/11/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->update([
            'fechaCancelacion' => '2026-11-15',
        ]);
        $existingSuccessorId = $this->createCxc(
            'REN-CXC-' . $sourceCxcId . ' P 15/11/2026 a 31/12/2026 SC-41',
            'Pendiente Pago parcial'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $existingSuccessorId)->update([
            'montoOriginal' => 45,
            'montoActual' => 25,
            'docReferencia' => 'F001-0001',
            'fechaRegistro' => '2026-11-01',
            'fechaCancelacion' => '2026-12-31',
        ]);

        $returnedId = (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->first(),
            0,
            Carbon::parse('2026-11-10'),
            [41, 42],
            5,
            null,
            false,
            null,
            '2026-11-10'
        );

        $existingSuccessor = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $existingSuccessorId)
            ->first();
        $newSuccessor = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'REN-CXC-' . $sourceCxcId . ' P %SC-42')
            ->first();
        $this->assertSame(3, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-11-10', $existingSuccessor->fechaRegistro);
        $this->assertSame('2026-12-31', $existingSuccessor->fechaCancelacion);
        $this->assertSame('F001-0001', $existingSuccessor->docReferencia);
        $this->assertSame('Pendiente Pago parcial', $existingSuccessor->estado);
        $this->assertSame(45.0, (float) $existingSuccessor->montoOriginal);
        $this->assertSame(25.0, (float) $existingSuccessor->montoActual);
        $this->assertNotNull($newSuccessor);
        $this->assertSame((int) $newSuccessor->idcuentasPorCobrar, $returnedId);
        $this->assertSame(30.0, (float) $newSuccessor->montoOriginal);
        $this->assertSame('2026-12-31', $newSuccessor->fechaCancelacion);
        $this->assertSame('2026-11-10', $newSuccessor->fechaRegistro);
    }

    public function test_payment_updates_amount_of_grouped_successor_without_resetting_payments(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2026-10-15',
            'fecheVencimiento' => '2026-11-15',
            'monto' => 60,
        ]);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-10-15',
            'fecheVencimiento' => '2026-11-15',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 60,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'XYZ-987',
            'docReferencia' => null,
        ]);
        $sourceCxcId = $this->createCxc('CXC 15/10/2026 a 15/11/2026 SC-41,42', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->update([
            'fechaCancelacion' => '2026-11-15',
        ]);
        $successorId = $this->createCxc(
            'REN-CXC-' . $sourceCxcId . ' P 15/11/2026 a 31/12/2026 SC-41,42',
            'Pendiente a credito'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $successorId)->update([
            'montoOriginal' => 110,
            'montoActual' => 80,
            'fechaCancelacion' => '2026-12-31',
        ]);

        $returnedId = (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->first(),
            0,
            Carbon::parse('2026-11-10'),
            [41, 42],
            5,
            null,
            false,
            null,
            '2026-11-10'
        );

        $successor = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $successorId)->first();
        $this->assertSame($successorId, $returnedId);
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertSame(120.0, (float) $successor->montoOriginal);
        $this->assertSame(90.0, (float) $successor->montoActual);
        $this->assertSame('Pendiente a credito', $successor->estado);
        $this->assertSame('2026-11-10', $successor->fechaRegistro);
        $this->assertSame('2026-12-31', $successor->fechaCancelacion);
    }

    public function test_new_monthly_service_prorates_its_second_charge_and_anchors_end_of_month(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-10',
            'fecheVencimiento' => '2026-10-10',
            'monto' => 60,
        ]);
        $firstCxcId = $this->createCxc('CXC 10/09/2026 a 10/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2026-10-10',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $secondCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->first();
        $this->assertNotNull($secondCxc);
        $this->assertSame(42.0, (float) $secondCxc->montoOriginal);
        $this->assertSame('2026-11-30', $secondCxc->fechaCancelacion);
        $this->assertStringContainsString('10/10/2026 a 30/11/2026', $secondCxc->descripcion);
        $this->assertSame('2026-11-30', DB::table('serviciocliente')->value('fecheVencimiento'));

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-11-01'));

        $thirdCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->where('idcuentasPorCobrar', '!=', $secondCxc->idcuentasPorCobrar)
            ->first();
        $this->assertNotNull($thirdCxc);
        $this->assertSame(60.0, (float) $thirdCxc->montoOriginal);
        $this->assertSame('2026-12-31', $thirdCxc->fechaCancelacion);
        $this->assertSame('2026-12-31', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_monthly_type_prorates_each_vehicle_when_warehouse_period_is_empty(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-09-07',
            'fecheVencimiento' => '2026-10-07',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 45,
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2026-09-07',
            'fecheVencimiento' => '2026-10-07',
            'monto' => 45,
        ]);
        $firstCxcId = $this->createCxc('CXC 07/09/2026 a 07/10/2026 SC-41,42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 90,
            'montoActual' => 90,
            'fechaCancelacion' => '2026-10-07',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-29'));

        $successors = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->orderBy('idcuentasPorCobrar')
            ->get();
        $this->assertCount(2, $successors);
        $this->assertSame([36.0, 36.0], $successors
            ->pluck('montoOriginal')
            ->map(fn($amount): float => (float) $amount)
            ->all());
        $this->assertSame(['2026-11-30'], $successors->pluck('fechaCancelacion')->unique()->values()->all());
        $this->assertSame(
            [CxcService::MONTHLY_PRORATION_PRICE_MARKER],
            $successors->pluck('mod_monto')->unique()->values()->all()
        );
        $this->assertSame(
            ['2026-11-30'],
            DB::table('serviciocliente')->orderBy('idservicioCliente')->pluck('fecheVencimiento')->unique()->values()->all()
        );
    }

    public function test_monthly_proration_at_or_below_five_is_added_to_following_month_charge(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-29',
            'fecheVencimiento' => '2026-10-29',
            'monto' => 75,
        ]);
        $firstCxcId = $this->createCxc('CXC 29/09/2026 a 29/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 75,
            'montoActual' => 75,
            'fechaCancelacion' => '2026-10-29',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $secondCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->first();
        $this->assertNotNull($secondCxc);
        $this->assertSame(80.0, (float) $secondCxc->montoOriginal);
        $this->assertSame('2026-12-31', $secondCxc->fechaCancelacion);
        $this->assertSame('2026-12-31', DB::table('serviciocliente')->value('fecheVencimiento'));
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
    }

    public function test_monthly_service_already_ending_on_month_end_renews_at_normal_price(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-10-01',
            'fecheVencimiento' => '2026-10-31',
            'monto' => 60,
        ]);
        $firstCxcId = $this->createCxc('CXC 01/10/2026 a 31/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2026-10-31',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $secondCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->first();
        $this->assertNotNull($secondCxc);
        $this->assertSame(60.0, (float) $secondCxc->montoOriginal);
        $this->assertSame('2026-11-30', $secondCxc->fechaCancelacion);
    }

    public function test_monthly_service_ending_on_day_thirty_does_not_prorate(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-30',
            'fecheVencimiento' => '2026-10-30',
            'monto' => 60,
        ]);
        $firstCxcId = $this->createCxc('CXC 30/09/2026 a 30/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2026-10-30',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $nextCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->first();
        $this->assertNotNull($nextCxc);
        $this->assertSame(60.0, (float) $nextCxc->montoOriginal);
        $this->assertSame('2026-11-30', $nextCxc->fechaCancelacion);
        $this->assertNull($nextCxc->mod_monto);
    }

    public function test_monthly_end_of_month_renewal_handles_leap_february(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2028-01-01',
            'fecheVencimiento' => '2028-01-31',
            'monto' => 60,
        ]);
        $firstCxcId = $this->createCxc('CXC 01/01/2028 a 31/01/2028 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2028-01-31',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2028-01-01'));

        $secondCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->first();
        $this->assertNotNull($secondCxc);
        $this->assertSame(60.0, (float) $secondCxc->montoOriginal);
        $this->assertSame('2028-02-29', $secondCxc->fechaCancelacion);
        $this->assertSame('2028-02-29', DB::table('serviciocliente')->value('fecheVencimiento'));

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2028-02-01'));

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2028-03-01'));

        $thirdCxc = DB::table('cuentasporcobrar')
            ->whereNotIn('idcuentasPorCobrar', [$firstCxcId, $secondCxc->idcuentasPorCobrar])
            ->first();
        $this->assertNotNull($thirdCxc);
        $this->assertSame(60.0, (float) $thirdCxc->montoOriginal);
        $this->assertSame('2028-03-31', $thirdCxc->fechaCancelacion);
    }

    public function test_monthly_proration_does_not_repeat_after_the_initial_successor(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-10',
            'fecheVencimiento' => '2026-10-10',
            'monto' => 60,
        ]);
        $currentCxcId = $this->createCxc('REN-CXC-41 P 10/09/2026 a 10/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2026-10-10',
        ]);

        (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));

        $nextCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $currentCxcId)
            ->first();
        $this->assertNotNull($nextCxc);
        $this->assertSame(60.0, (float) $nextCxc->montoOriginal);
        $this->assertSame('2026-11-10', $nextCxc->fechaCancelacion);
    }

    public function test_monthly_group_payment_prorates_each_vehicle_when_default_end_date_is_submitted(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-07',
            'fecheVencimiento' => '2026-10-07',
            'monto' => 40,
        ]);
        foreach ([42, 43, 44] as $serviceId) {
            DB::table('serviciocliente')->insert([
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-07',
                'fecheVencimiento' => '2026-10-07',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 40,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => 'ABC-' . $serviceId,
                'docReferencia' => null,
            ]);
        }
        $currentCxcId = $this->createCxc(
            'CXC 07/09/2026 a 07/10/2026 SC-41,42,43,44',
            '1'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 160,
            'montoActual' => 160,
            'fechaCancelacion' => '2026-10-07',
        ]);

        (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first(),
            0,
            Carbon::parse('2026-10-22'),
            [41, 42, 43, 44],
            5,
            null,
            false,
            '2026-11-07',
            '2026-10-22'
        );

        $successors = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $currentCxcId)
            ->orderBy('idcuentasPorCobrar')
            ->get();
        $this->assertCount(4, $successors);
        $this->assertSame([32.0, 32.0, 32.0, 32.0], $successors
            ->pluck('montoOriginal')
            ->map(fn($amount): float => (float) $amount)
            ->all());
        $this->assertSame(['2026-11-30'], $successors->pluck('fechaCancelacion')->unique()->values()->all());
        $this->assertSame(['2026-10-22'], $successors->pluck('fechaRegistro')->unique()->values()->all());
        $this->assertSame(
            ['2026-11-30'],
            DB::table('serviciocliente')->orderBy('idservicioCliente')->pluck('fecheVencimiento')->unique()->values()->all()
        );

        $cxcService = new CxcService();
        foreach ($successors as $index => $successor) {
            $serviceId = 41 + $index;
            $isolated = $cxcService->isolateSelectedServiceReceivables(
                [(int) $successor->idcuentasPorCobrar],
                [$serviceId]
            );
            $charge = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $isolated['cxc_ids'][0])
                ->first();

            $this->assertSame(32.0, (float) $charge->montoOriginal);
            $this->assertSame(CxcService::MONTHLY_PRORATION_PRICE_MARKER, $charge->mod_monto);
        }
    }

    public function test_monthly_group_services_with_different_end_days_prorate_independently(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-15',
            'fecheVencimiento' => '2026-10-21',
            'monto' => 48,
        ]);
        DB::table('serviciocliente')->insert([
            [
                'idservicioCliente' => 42,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-18',
                'fecheVencimiento' => '2026-10-22',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 48,
                'almacen_idalmacen' => 1,
            ],
            [
                'idservicioCliente' => 43,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-08-31',
                'fecheVencimiento' => '2026-10-19',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 82,
                'almacen_idalmacen' => 1,
            ],
        ]);
        $currentCxcId = $this->createCxc(
            'CXC 19/09/2026 a 22/10/2026 SC-41,42,43',
            '1'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 178,
            'montoActual' => 178,
            'fechaCancelacion' => '2026-10-22',
        ]);

        (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first(),
            0,
            Carbon::parse('2026-10-29'),
            [41, 42, 43],
            5
        );

        $successors = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $currentCxcId)
            ->orderBy('idcuentasPorCobrar')
            ->get();
        $amountsByService = $successors->mapWithKeys(function (object $charge): array {
            preg_match('/SC-(\d+)/', (string) $charge->descripcion, $matches);

            return [(int) ($matches[1] ?? 0) => (float) $charge->montoOriginal];
        })->all();
        ksort($amountsByService);
        $this->assertSame([41 => 16.0, 42 => 14.4, 43 => 32.8], $amountsByService);
        $this->assertSame(['2026-11-30'], $successors->pluck('fechaCancelacion')->unique()->values()->all());
        $this->assertSame(
            [CxcService::MONTHLY_PRORATION_PRICE_MARKER],
            $successors->pluck('mod_monto')->unique()->values()->all()
        );
        $this->assertSame(
            ['2026-11-30'],
            DB::table('serviciocliente')->whereIn('idservicioCliente', [41, 42, 43])
                ->pluck('fecheVencimiento')->unique()->values()->all()
        );
    }

    public function test_editing_one_vehicle_price_syncs_unpaid_grouped_cxc_to_660(): void
    {
        $this->createSchema();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado')->nullable();
        });
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });

        for ($serviceId = 41; $serviceId <= 58; $serviceId++) {
            DB::table('serviciocliente')->insert([
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-23',
                'fecheVencimiento' => '2026-10-23',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => $serviceId === 41 ? 36 : 36,
                'vehiculo_placa' => 'V-' . $serviceId,
            ]);
        }

        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'CXC 23/09/2026 a 23/10/2026 SC-' . implode(',', range(41, 58)),
            'montoOriginal' => 648,
            'montoActual' => 648,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => '2026-10-23',
            'estado' => '1',
        ]);
        $links = [];
        for ($serviceId = 41; $serviceId <= 58; $serviceId++) {
            $links[] = [
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => 36,
            ];
        }
        DB::table('cuentasporcobrar_serviciocliente')->insert($links);

        DB::table('serviciocliente')->where('idservicioCliente', 41)->update(['monto' => 48]);
        $oldService = (object) ['monto' => 36, 'estado' => 'activo'];

        $updated = (new CxcService())->synchronizeCurrentServiceChargeAmount(
            41,
            $oldService,
            ['monto' => 48]
        );

        $this->assertTrue($updated);
        $cxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame(660.0, (float) $cxc->montoOriginal);
        $this->assertSame(660.0, (float) $cxc->montoActual);
        $this->assertSame(48.0, (float) DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
            ->where('servicioCliente_idservicioCliente', 41)
            ->value('montoServicio'));
    }

    public function test_service_price_edit_updates_only_the_current_period_cxc(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });
        $currentCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');
        $futureCxcId = $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->update([
            'fechaCancelacion' => '2026-11-23',
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $currentCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 45,
            ],
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $futureCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 45,
            ],
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update(['monto' => 50]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeAmount(
            41,
            (object) [
                'monto' => 45,
                'estado' => 'activo',
                'fecheVencimiento' => '2026-10-23',
            ],
            ['monto' => 50],
            true
        );

        $this->assertTrue($updated);
        $this->assertSame(50.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $currentCxcId)
            ->value('montoOriginal'));
        $this->assertSame(50.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $currentCxcId)
            ->value('montoActual'));
        $this->assertSame(45.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $futureCxcId)
            ->value('montoOriginal'));
        $this->assertSame(45.0, (float) DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $futureCxcId)
            ->value('montoServicio'));
    }

    public function test_editing_service_price_does_not_overwrite_paid_or_cancelled_cxc(): void
    {
        $this->createSchema();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado')->nullable();
        });
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 41,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 48,
            'vehiculo_placa' => 'ABC-123',
        ]);

        $paidCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');
        $cancelledCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '2');
        DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', [$paidCxcId, $cancelledCxcId])->update([
            'montoOriginal' => 36,
            'montoActual' => 36,
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => $paidCxcId, 'servicioCliente_idservicioCliente' => 41, 'montoServicio' => 36],
            ['cuentasPorCobrar_idcuentasPorCobrar' => $cancelledCxcId, 'servicioCliente_idservicioCliente' => 41, 'montoServicio' => 36],
        ]);
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $paidCxcId,
            'estado' => '1',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeAmount(
            41,
            (object) ['monto' => 36, 'estado' => 'activo'],
            ['monto' => 48]
        );

        $this->assertFalse($updated);
        foreach ([$paidCxcId, $cancelledCxcId] as $cxcId) {
            $cxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
            $this->assertSame(36.0, (float) $cxc->montoOriginal);
            $this->assertSame(36.0, (float) $cxc->montoActual);
        }
    }

    public function test_editing_service_price_recalculates_prorated_future_cxc_amount(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-10',
            'fecheVencimiento' => '2026-10-10',
            'monto' => 60,
        ]);
        $firstCxcId = $this->createCxc('CXC 10/09/2026 a 10/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2026-10-10',
        ]);

        try {
            (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));
            $proratedCxc = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', '!=', $firstCxcId)
                ->first();
            $this->assertNotNull($proratedCxc);
            $this->assertSame(42.0, (float) $proratedCxc->montoOriginal);
            $this->assertSame(CxcService::MONTHLY_PRORATION_PRICE_MARKER, $proratedCxc->mod_monto);
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $proratedCxc->idcuentasPorCobrar)
                ->update(['mod_monto' => null]);

            $controller = new CuentasPorCobrarController(
                new CxcService(),
                new CuentasPorCobrarService(),
                new CotizacionService()
            );
            $response = $controller->updateServicePrice(
                Request::create('/', 'PATCH', ['monto' => '80.00']),
                41
            );

            $this->assertSame(200, $response->getStatusCode());
            $updatedCxc = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $proratedCxc->idcuentasPorCobrar)
                ->first();
            $this->assertSame(56.0, (float) $updatedCxc->montoOriginal);
            $this->assertSame(56.0, (float) $updatedCxc->montoActual);
            $this->assertSame(CxcService::MONTHLY_PRORATION_PRICE_MARKER, $updatedCxc->mod_monto);
            $this->assertSame(80.0, (float) DB::table('serviciocliente')
                ->where('idservicioCliente', 41)
                ->value('monto'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_editing_prorated_cxc_for_one_period_does_not_change_base_service_price(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-30',
            'monto' => 60,
        ]);
        $sourceCxcId = $this->createCxc('CXC 10/09/2026 a 10/10/2026 SC-41', '1');
        $proratedCxcId = $this->createCxc(
            'REN-CXC-' . $sourceCxcId . ' P 10/10/2026 a 30/11/2026 SC-41',
            '1'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $proratedCxcId)->update([
            'montoOriginal' => 42,
            'montoActual' => 42,
            'fechaCancelacion' => '2026-11-30',
            'mod_monto' => CxcService::MONTHLY_PRORATION_PRICE_MARKER,
        ]);

        try {
            $controller = new CuentasPorCobrarController(
                new CxcService(),
                new CuentasPorCobrarService(),
                new CotizacionService()
            );
            $response = $controller->updateServicePrice(Request::create('/', 'PATCH', [
                'monto' => '80.00',
                'mantener_precio_siguiente' => '0',
            ]), 41);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(60.0, (float) DB::table('serviciocliente')
                ->where('idservicioCliente', 41)
                ->value('monto'));
            $this->assertSame(45.0, (float) DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $sourceCxcId)
                ->value('montoOriginal'));
            $this->assertSame(80.0, (float) DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $proratedCxcId)
                ->value('montoOriginal'));
            $this->assertSame(CxcService::MONTHLY_PRORATION_PRICE_MARKER, DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $proratedCxcId)
                ->value('mod_monto'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_paid_first_period_reuses_prorated_automatic_successor(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert(['idalmacen' => 1, 'periodo' => '1']);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-09-10',
            'fecheVencimiento' => '2026-10-10',
            'monto' => 60,
        ]);
        $firstCxcId = $this->createCxc('CXC 10/09/2026 a 10/10/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'montoOriginal' => 60,
            'montoActual' => 60,
            'fechaCancelacion' => '2026-10-10',
        ]);
        $cxcService = new CxcService();
        $cxcService->generarPeriodosAutomaticos(Carbon::parse('2026-10-01'));
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->update([
            'estado' => '3',
            'montoActual' => 0,
        ]);
        $successorId = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $firstCxcId)
            ->value('idcuentasPorCobrar');

        $result = $cxcService->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $firstCxcId)->first(),
            0,
            Carbon::parse('2026-10-10'),
            [41],
            5,
            null,
            false,
            null,
            '2026-11-01'
        );

        $this->assertSame((int) $successorId, $result);
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertSame(42.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $successorId)
            ->value('montoOriginal'));
        $this->assertSame('2026-11-30', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $successorId)
            ->value('fechaCancelacion'));
        $this->assertSame('2026-11-01', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $successorId)
            ->value('fechaRegistro'));
        $this->assertSame('2026-11-30', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('servicePeriodCases')]
    public function test_automatic_renewal_respects_service_period_in_months(int $periodMonths, string $expectedEnd): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert([
            'idalmacen' => 1,
            'periodo' => (string) $periodMonths,
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
            'monthly' => [1, '2026-11-30'],
            'every three months' => [3, '2027-01-23'],
            'every six months' => [6, '2027-04-23'],
            '13 months' => [13, '2027-11-23'],
            'yearly' => [12, '2027-10-23'],
            'every two years' => [24, '2028-10-23'],
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
        $successor = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', '!=', 1)->first();
        $this->assertSame(12.0, (float) $successor->montoOriginal);
        $this->assertSame('2026-11-30', $successor->fechaCancelacion);
        $this->assertSame('2026-11-30', DB::table('serviciocliente')->value('fecheVencimiento'));
    }

    public function test_paying_october_reuses_the_already_generated_november_successor(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $currentCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        $successorId = $this->createCxc(
            'REN-CXC-' . $currentCxcId . ' P 23/10/2026 a 23/11/2026 SC-41',
            '1'
        );
        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $successorId)
            ->update(['fechaCancelacion' => '2026-11-23']);

        $result = (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first(),
            0,
            Carbon::parse('2026-10-23'),
            [41],
            5
        );

        $this->assertSame($successorId, $result);
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertSame(12.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $successorId)
            ->value('montoOriginal'));
        $this->assertSame('2026-11-30', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $successorId)
            ->value('fechaCancelacion'));
        $this->assertSame('2026-11-30', DB::table('serviciocliente')
            ->where('idservicioCliente', 41)
            ->value('fecheVencimiento'));
    }

    public function test_same_client_services_with_different_periods_share_one_visual_group(): void
    {
        Schema::create('tipocobro', function (Blueprint $table): void {
            $table->increments('idtipoCobros');
            $table->string('recurrencia');
            $table->integer('tiempo');
        });
        DB::table('tipocobro')->insert([
            ['idtipoCobros' => 6, 'recurrencia' => 'M', 'tiempo' => 3],
            ['idtipoCobros' => 7, 'recurrencia' => 'M', 'tiempo' => 6],
        ]);

        $threeMonthService = $this->serviceCxcRow(
            101,
            'CXC 23/08/2026 a 23/11/2026 SC-41',
            '2026-11-23',
            '1',
            null
        );
        $threeMonthService->servicio_detalle = 'Servicio trimestral';
        $threeMonthService->servicio_periodo = '3 meses';
        $threeMonthService->cxc_tipoCobro_idtipoCobros = 6;
        $threeMonthService->tipoCobro_idtipoCobros = 6;
        $threeMonthService->idservicioCliente = 41;
        $threeMonthService->fechaInicio = '2026-08-23';
        $threeMonthService->dias_restantes = 1;

        $sixMonthService = $this->serviceCxcRow(
            102,
            'CXC 28/05/2026 a 28/11/2026 SC-42',
            '2026-11-28',
            '1',
            null
        );
        $sixMonthService->servicio_detalle = 'Servicio semestral';
        $sixMonthService->servicio_periodo = '6 meses';
        $sixMonthService->cxc_tipoCobro_idtipoCobros = 7;
        $sixMonthService->tipoCobro_idtipoCobros = 7;
        $sixMonthService->idservicioCliente = 42;
        $sixMonthService->fechaInicio = '2026-05-28';
        $sixMonthService->fecheVencimiento = '2026-11-28';
        $sixMonthService->dias_restantes = 6;

        $groups = (new \ReflectionMethod(CuentasPorCobrarService::class, 'groupServices'))->invoke(
            new CuentasPorCobrarService(),
            collect([$threeMonthService, $sixMonthService]),
            collect(),
            [],
            DB::table('tipocobro')->get()->keyBy('idtipoCobros')
        );
        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['num_unidades']);
        $this->assertSame(2, $groups[0]['num_tservicios']);
        $this->assertSame('2027-02-23', $groups[0]['fecha_fin_siguiente_iso']);
        $this->assertSame(
            ['Servicio trimestral', 'Servicio semestral'],
            array_column($groups[0]['tipos_servicios'], 'servicio_nombre')
        );
        $this->assertSame([6, 7], array_column($groups[0]['tipos_servicios'], 'tipo_cobro_id'));
        $this->assertSame(
            ['2027-02-23', '2027-05-28'],
            array_column($groups[0]['tipos_servicios'], 'fecha_fin_siguiente_iso')
        );
        $this->assertSame('2027-02-23', $groups[0]['tipos_servicios'][0]['vehiculos'][0]['fecha_fin_siguiente_iso']);
        $this->assertSame('2027-05-28', $groups[0]['tipos_servicios'][1]['vehiculos'][0]['fecha_fin_siguiente_iso']);
        $this->assertSame('2026-11-28', $groups[0]['fecha_fin_iso']);
    }

    public function test_previous_unpaid_period_is_available_when_managing_its_successor(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $previousCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026', '4');
        $currentCxcId = $this->createCxc('REN-CXC-' . $previousCxcId . ' P 23/10/2026 a 23/11/2026 SC-41', '1');
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
        });
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $previousCxcId,
            'servicioCliente_idservicioCliente' => 41,
        ]);

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

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
        request()->query->set('current_cxc_id', (string) $previousCxcId);
        $octoberResponse = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame([], $octoberResponse['cxcs']);

        request()->query->set('current_cxc_id', (string) $currentCxcId);
        $response = $controller->getDeudasCliente('CLIENTE-1')->getData(true);

        $this->assertSame([$previousCxcId], array_column($response['cxcs'], 'idcuentasPorCobrar'));
        $this->assertSame('ABC-123', $response['cxcs'][0]['placa_display']);
        $this->assertSame(1, $response['moneda_idmoneda']);
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
        $sameMonthEarlierCxcId = $this->createCxc('Descripción escrita para octubre anterior', '4');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sameMonthEarlierCxcId)->update([
            'fechaCancelacion' => '2026-10-01',
            'fechaRegistro' => '2026-09-22',
        ]);
        request()->query->set('service_ids', ['41']);

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
        request()->query->set('current_cxc_id', (string) $periodIds['noviembre']);
        $novemberDebts = $controller->getDeudasCliente('CLIENTE-1')->getData(true);
        $this->assertSame(
            [$periodIds['octubre'], $sameMonthEarlierCxcId, $periodIds['septiembre'], $periodIds['agosto']],
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

    public function test_single_service_advance_keeps_service_end_aligned_with_next_cxc(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $currentCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        $currentCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $currentCxcId)
            ->first();

        (new CxcService())->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-10-01'),
            [41],
            5,
            '2027-02-23',
            true
        );

        $nextCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $currentCxcId)
            ->first();

        $this->assertSame('2027-03-23', $nextCxc->fechaCancelacion);
        $this->assertSame('2027-03-23', DB::table('serviciocliente')
            ->where('idservicioCliente', 41)
            ->value('fecheVencimiento'));
    }

    public function test_advance_and_cancelled_cxcs_remain_visible_before_service_end_is_aligned(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::table('almacen', function (Blueprint $table): void {
            $table->string('detalle')->nullable();
            $table->integer('tipoElemento_idtipoElemento')->nullable();
        });
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
            $table->string('flag_integrador')->nullable();
            $table->string('detraccion')->nullable();
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('tracto')->nullable();
            $table->integer('tipoUnidad_idtable1')->nullable();
        });
        Schema::create('tipovehiculo', function (Blueprint $table): void {
            $table->increments('idtipoVehiculo');
            $table->string('nombre')->nullable();
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->increments('idtipoElemento');
            $table->integer('plataforma_idplataforma')->nullable();
        });
        Schema::create('plataforma', function (Blueprint $table): void {
            $table->increments('idplataforma');
            $table->string('nombrePlataforma')->nullable();
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado')->nullable();
            $table->dateTime('fechaPago')->nullable();
        });
        DB::table('cliente')->insert([
            'idcliente' => 'CLIENTE-1',
            'razonSocial' => 'Cliente de prueba',
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2027-04-20',
        ]);
        $successorId = $this->createCxc('REN-CXC-10 P 20/04/2027 a 20/05/2027 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $successorId)->update([
            'fechaCancelacion' => '2027-05-20',
        ]);
        $numericCancelledId = $this->createCxc('REN-CXC-0 P 20/04/2027 a 20/05/2027 SC-41', '3');
        $textCancelledId = $this->createCxc('REN-CXC-0 P 20/04/2027 a 20/05/2027 SC-41', 'CANCELADO');
        DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', [$numericCancelledId, $textCancelledId])
            ->update(['fechaCancelacion' => '2027-05-20']);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'inactivo',
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 45,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'XYZ-987',
            'docReferencia' => null,
        ]);
        $inactiveServiceCancelledId = $this->createCxc(
            'REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-42',
            'CANCELADO'
        );
        $octoberCancelledId = $this->createCxc(
            'REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41',
            'CANCELADO'
        );

        if (DB::getDriverName() === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->sqliteCreateFunction('CONCAT', static fn (...$parts): string => implode('', $parts), -1);
            $pdo->sqliteCreateFunction('REGEXP', static fn (string $pattern, string $value): int =>
                preg_match('/' . $pattern . '/', $value) === 1 ? 1 : 0, 2);
            $pdo->sqliteCreateFunction('SUBSTRING_INDEX', static function (string $value, string $delimiter, int $count): string {
                $parts = explode($delimiter, $value);
                return $count < 0
                    ? implode($delimiter, array_slice($parts, $count))
                    : implode($delimiter, array_slice($parts, 0, $count));
            }, 3);
            $pdo->sqliteCreateFunction('STR_TO_DATE', static function (string $date): ?string {
                $parsed = \DateTime::createFromFormat('!d/m/Y', $date);
                return $parsed ? $parsed->format('Y-m-d') : null;
            }, 2);
        }

        $service = new CuentasPorCobrarService();
        $loadServices = new \ReflectionMethod($service, 'loadServices');
        $services = $loadServices->invoke($service);

        $servicesById = $services->keyBy('idcuentasPorCobrar');
        $this->assertEqualsCanonicalizing(
            [$successorId, $numericCancelledId, $textCancelledId, $inactiveServiceCancelledId, $octoberCancelledId],
            $services->pluck('idcuentasPorCobrar')->all()
        );
        $this->assertSame('3', (string) $servicesById[$numericCancelledId]->cxc_estado);
        $this->assertSame('CANCELADO', (string) $servicesById[$textCancelledId]->cxc_estado);
        $this->assertSame(42, (int) $servicesById[$inactiveServiceCancelledId]->idservicioCliente);
        $this->assertSame('inactivo', (string) $servicesById[$inactiveServiceCancelledId]->estado);
        foreach ([$successorId, $numericCancelledId, $textCancelledId] as $cxcId) {
            $this->assertSame('2027-05-20', (string) $servicesById[$cxcId]->cxc_fechaCancelacion);
            $this->assertSame(45.0, (float) $servicesById[$cxcId]->cxc_montoOriginal);
        }
        $this->assertSame('2026-10-23', (string) $servicesById[$inactiveServiceCancelledId]->cxc_fechaCancelacion);
        $this->assertSame(45.0, (float) $servicesById[$inactiveServiceCancelledId]->cxc_montoOriginal);

        $octoberServices = $loadServices->invoke($service, [
            'service_months' => ['octubre'],
            'service_year' => '2026',
        ]);
        $this->assertContains(
            $octoberCancelledId,
            $octoberServices->pluck('idcuentasPorCobrar')->all()
        );
        $octoberCancelled = $octoberServices->firstWhere('idcuentasPorCobrar', $octoberCancelledId);
        $this->assertNotNull($octoberCancelled);
        $this->assertSame(41, (int) $octoberCancelled->idservicioCliente);
        $this->assertSame('2026-10-23', (string) $octoberCancelled->cxc_fechaCancelacion);
    }

    public function test_group_renewal_creates_one_unit_cxc_per_service_and_is_idempotent(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2026-10-27',
            'fecheVencimiento' => '2026-11-27',
            'monto' => 50,
        ]);
        DB::table('serviciocliente')->insert([
            [
                'idservicioCliente' => 42,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-10-27',
                'fecheVencimiento' => '2026-11-27',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 50,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'XYZ-987',
                'docReferencia' => null,
            ],
            [
                'idservicioCliente' => 43,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-10-27',
                'fecheVencimiento' => '2026-11-27',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 60,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'DEF-456',
                'docReferencia' => null,
            ],
        ]);
        $currentCxcId = $this->createCxc('REN-CXC-9 P 27/10/2026 a 27/11/2026 SC-41,42,43', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 160,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-11-27',
        ]);
        $legacyNextCxcId = $this->createCxc('REN-CXC-' . $currentCxcId . ' P 27/11/2026 a 27/12/2026 SC-41,42,43', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $legacyNextCxcId)->update([
            'montoOriginal' => 160,
            'montoActual' => 160,
            'fechaCancelacion' => '2026-12-27',
        ]);
        $currentCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first();
        $service = new CxcService();

        $service->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-11-02'),
            [41, 42, 43],
            5,
            null,
            false,
            null,
            '2026-11-02 10:15:00'
        );
        $successors = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', '!=', $currentCxcId)
            ->orderBy('montoOriginal')
            ->get();

        $this->assertCount(3, $successors);
        $this->assertSame([50.0, 50.0, 60.0], $successors->pluck('montoOriginal')->map(fn ($amount): float => (float) $amount)->all());
        $this->assertSame(
            ['2026-11-02', '2026-11-02', '2026-11-02'],
            $successors->map(fn ($cxc): string => Carbon::parse($cxc->fechaRegistro)->toDateString())->all()
        );

        $service->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-11-02'),
            [41, 42, 43],
            5,
            null,
            false,
            null,
            '2026-11-02 10:15:00'
        );

        $this->assertSame(4, DB::table('cuentasporcobrar')->count());
    }

    public function test_group_invoice_amount_does_not_overwrite_individual_cxc_amounts(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update(['monto' => 50]);
        DB::table('serviciocliente')->insert([
            [
                'idservicioCliente' => 42,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-23',
                'fecheVencimiento' => '2026-10-23',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 50,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'XYZ-987',
                'docReferencia' => null,
            ],
            [
                'idservicioCliente' => 43,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-23',
                'fecheVencimiento' => '2026-10-23',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 60,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'DEF-456',
                'docReferencia' => null,
            ],
        ]);
        $cxcIds = [
            $this->createCxc('CXC octubre SC-41', '1'),
            DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 5,
                'moneda_idmoneda' => 1,
                'docReferencia' => null,
                'descripcion' => 'CXC octubre SC-42',
                'montoOriginal' => 50,
                'montoActual' => 50,
                'fechaCancelacion' => '2026-10-23',
                'estado' => '1',
            ]),
            DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 5,
                'moneda_idmoneda' => 1,
                'docReferencia' => null,
                'descripcion' => 'CXC octubre SC-43',
                'montoOriginal' => 60,
                'montoActual' => 60,
                'fechaCancelacion' => '2026-10-23',
                'estado' => '1',
            ]),
        ];
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcIds[0])->update([
            'montoOriginal' => 50,
            'montoActual' => 50,
        ]);

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
        $request = Request::create('/', 'POST', [
            'servicio_ids' => [41, 42, 43],
            'cxc_ids' => $cxcIds,
            'docReferencia' => 'F001-180',
            'realizar_cobro' => '0',
            'monto_factura' => '180.00',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $controller->registrarFactura($request, (string) $cxcIds[0]);
        $cxcs = DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $cxcIds)->orderBy('idcuentasPorCobrar')->get();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([50.0, 50.0, 60.0], $cxcs->pluck('montoOriginal')->map(fn ($amount): float => (float) $amount)->all());
        $this->assertSame(160.0, $cxcs->sum(fn ($cxc): float => (float) $cxc->montoOriginal));
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

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
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

    public function test_vehicle_price_update_reprices_pending_current_and_future_cxc(): void
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
        $currentCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41,42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 115,
            'montoActual' => 115,
        ]);
        $futureCxcId = $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41,42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->update([
            'montoOriginal' => 115,
            'montoActual' => 115,
            'fechaCancelacion' => '2026-11-23',
        ]);
        $historicalCxcId = $this->createCxc('CXC 23/08/2026 a 23/09/2026 SC-41,42', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $historicalCxcId)->update([
            'montoOriginal' => 115,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-09-23',
        ]);

        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
        $response = $controller->updateServicePrice(Request::create('/', 'PATCH', ['monto' => '80.00']), 41);

        $this->assertSame(200, $response->getStatusCode());
        foreach ([$currentCxcId, $futureCxcId] as $cxcId) {
            $cxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
            $this->assertSame(150.0, (float) $cxc->montoOriginal);
            $this->assertSame(150.0, (float) $cxc->montoActual);
        }
        $historicalCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $historicalCxcId)->first();
        $this->assertSame(115.0, (float) $historicalCxc->montoOriginal);
        $this->assertSame(0.0, (float) $historicalCxc->montoActual);
        $this->assertSame('No mantener precio pasado', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $currentCxcId)
            ->value('mod_monto'));
        $history = DB::table('historial_servicio')->where('servicioCliente_idservicioCliente', 41)->first();
        $this->assertSame('Cambio de precio', $history->motivo);
        $this->assertSame('Cambio de precio de S/ 45 a S/ 80 para los siguientes meses', $history->descripcion);
        $this->assertSame('anonimo', $history->usuario_usuario);
    }

    public function test_vehicle_price_update_can_be_limited_to_the_current_cxc(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update(['docReferencia' => 'DOC-REF-41']);
        DB::table('detalle_serviciodispositivo')->insert([
            'servicioCliente_idservicioCliente' => 41,
            'dispositivoCliente_iddispositivoCliente' => 7,
        ]);
        Schema::create('detnumerosdispositivo', function (Blueprint $table): void {
            $table->increments('iddetNumerosDispositivo');
            $table->integer('dispositivoCliente_iddispositivoCliente');
            $table->string('numeroTelefonico_numeroTelefonico');
        });
        DB::table('detnumerosdispositivo')->insert([
            'dispositivoCliente_iddispositivoCliente' => 7,
            'numeroTelefonico_numeroTelefonico' => '999123456',
        ]);
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        $currentCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '1');
        $futureCxcId = $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', [$currentCxcId, $futureCxcId])->update([
            'montoOriginal' => 45,
            'montoActual' => 45,
        ]);
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $currentCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 45,
            ],
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $futureCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 45,
            ],
        ]);

        try {
            $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
            $response = $controller->updateServicePrice(Request::create('/', 'PATCH', [
                'monto' => '80.00',
                'mantener_precio_siguiente' => '0',
            ]), 41);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(45.0, (float) DB::table('serviciocliente')->where('idservicioCliente', 41)->value('monto'));
            $this->assertSame(80.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->value('montoOriginal'));
            $this->assertSame(80.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->value('montoActual'));
            $this->assertSame('Si mantener precio pasado', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->value('mod_monto'));
            $this->assertSame(80.0, (float) DB::table('cuentasporcobrar_serviciocliente')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $currentCxcId)
                ->value('montoServicio'));
            $this->assertSame(45.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->value('montoOriginal'));
            $this->assertSame(45.0, (float) DB::table('cuentasporcobrar_serviciocliente')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $futureCxcId)
                ->value('montoServicio'));
            $history = DB::table('historial_servicio')->where('servicioCliente_idservicioCliente', 41)->first();
            $this->assertSame('anonimo', $history->usuario_usuario);
            $this->assertSame('Cambio de precio', $history->motivo);
            $this->assertSame('Cambio de precio de S/ 45 a S/ 80 solo para el CXC Octubre', $history->descripcion);
            $this->assertSame('DOC-REF-41', $history->doc_referencia);
            $this->assertSame(7, $history->dispositivo);
            $this->assertSame('999123456', $history->numerotelefono);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_only_price_update_targets_the_cxc_displayed_when_service_end_date_differs(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-15',
        ]);
        $currentCxcId = $this->createCxc('CXC 15/09/2026 a 15/10/2026 SC-41', '4');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-10-15',
        ]);
        $futureCxcId = $this->createCxc('REN-CXC-' . $currentCxcId . ' P 15/10/2026 a 15/11/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->update([
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-11-15',
        ]);

        try {
            $controller = new CuentasPorCobrarController(
                new CxcService(),
                new CuentasPorCobrarService(),
                new CotizacionService()
            );
            $response = $controller->updateServicePrice(Request::create('/', 'PATCH', [
                'monto' => '80.00',
                'mantener_precio_siguiente' => '0',
                'cuentasPorCobrar_idcuentasPorCobrar' => $currentCxcId,
            ]), 41);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(45.0, (float) DB::table('serviciocliente')
                ->where('idservicioCliente', 41)
                ->value('monto'));
            $this->assertSame(80.0, (float) DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $currentCxcId)
                ->value('montoOriginal'));
            $this->assertSame(45.0, (float) DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $futureCxcId)
                ->value('montoOriginal'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_vehicle_price_update_reprices_current_overdue_cxc_without_changing_older_periods(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->createSchema(true);
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-15',
            'monto' => 40,
        ]);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-07-16',
            'fecheVencimiento' => '2026-10-15',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 920,
            'almacen_idalmacen' => null,
            'vehiculo_placa' => 'XYZ-987',
            'docReferencia' => null,
        ]);
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
        });
        $overdueCxcId = $this->createCxc('REN-CXC-0 P 16/07/2026 a 15/10/2026 SC-41,42', '4');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $overdueCxcId)->update([
            'montoOriginal' => 960,
            'montoActual' => 960,
            'fechaCancelacion' => '2026-10-15',
        ]);
        $historicalCxcId = $this->createCxc('CXC 16/08/2026 a 15/09/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $historicalCxcId)->update([
            'montoOriginal' => 40,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-09-15',
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $overdueCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 40,
            ],
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $overdueCxcId,
                'servicioCliente_idservicioCliente' => 42,
                'montoServicio' => 920,
            ],
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => $historicalCxcId,
                'servicioCliente_idservicioCliente' => 41,
                'montoServicio' => 40,
            ],
        ]);

        try {
            $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
            $response = $controller->updateServicePrice(Request::create('/', 'PATCH', ['monto' => '60.00']), 41);

            $this->assertSame(200, $response->getStatusCode());
            $overdueCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $overdueCxcId)->first();
            $this->assertSame(980.0, (float) $overdueCxc->montoOriginal);
            $this->assertSame(980.0, (float) $overdueCxc->montoActual);
            $this->assertSame(
                [60.0, 920.0],
                DB::table('cuentasporcobrar_serviciocliente')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $overdueCxcId)
                    ->orderBy('servicioCliente_idservicioCliente')
                    ->pluck('montoServicio')
                    ->map(fn($amount): float => (float) $amount)
                    ->all()
            );
            $historicalCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $historicalCxcId)->first();
            $this->assertSame(40.0, (float) $historicalCxc->montoOriginal);
            $this->assertSame(
                40.0,
                (float) DB::table('cuentasporcobrar_serviciocliente')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $historicalCxcId)
                    ->value('montoServicio')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_vehicle_price_update_keeps_past_cancelled_cxc_amount_unchanged(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-07-23',
        ]);
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        $currentCxcId = $this->createCxc('REN-CXC-0 P 23/06/2026 a 23/07/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-07-23',
        ]);
        $futureCxcId = $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->update([
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-11-23',
        ]);

        try {
            $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());
            $response = $controller->updateServicePrice(Request::create('/', 'PATCH', ['monto' => '80.00']), 41);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(80.0, (float) DB::table('serviciocliente')->where('idservicioCliente', 41)->value('monto'));
            $historicalCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first();
            $this->assertSame(45.0, (float) $historicalCxc->montoOriginal);
            $this->assertSame(45.0, (float) $historicalCxc->montoActual);
            $futureCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->first();
            $this->assertSame(80.0, (float) $futureCxc->montoOriginal);
            $this->assertSame(80.0, (float) $futureCxc->montoActual);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_vehicle_price_update_rejects_a_cancelled_current_cxc(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('moneda')->insert(['idmoneda' => 1, 'simbolo' => 'S/']);
        $cancelledCxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');
        $controller = new CuentasPorCobrarController(new CxcService(), new CuentasPorCobrarService(), new CotizacionService());

        try {
            $controller->updateServicePrice(Request::create('/', 'PATCH', ['monto' => '80.00']), 41);
            $this->fail('Expected price updates to be rejected for a cancelled current CXC.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(45.0, (float) DB::table('serviciocliente')->where('idservicioCliente', 41)->value('monto'));
        $this->assertSame(45.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cancelledCxcId)->value('montoOriginal'));
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

    public function test_scheduled_deactivation_reprices_only_the_selected_service_in_future_grouped_cxc(): void
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
            'monto' => 45,
        ]);
        $futureId = $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41,42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureId)->update([
            'fechaCancelacion' => '2026-11-23',
            'montoOriginal' => 90,
            'montoActual' => 90,
        ]);
        $this->createBajaHistorySchema();

        $processed = (new CxcService())->darDeBajaServicios(
            [41],
            'periodo',
            'Cliente solicita baja al final de su periodo.',
            'no',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $futureCharge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureId)->first();
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        $this->assertSame(1, $processed);
        $this->assertSame('activo', $service->estado);
        $scheduledHistory = DB::table('historial_servicio')
            ->where('servicioCliente_idservicioCliente', 41)
            ->where('motivo', 'Baja programada al finalizar periodo')
            ->first();
        $this->assertStringContainsString('Cliente solicita baja al final de su periodo.', $scheduledHistory->descripcion);
        $this->assertStringContainsString('(Mantener SIM/Número: No)', $scheduledHistory->descripcion);
        $this->assertSame('1', $futureCharge->estado);
        $this->assertSame(45.0, (float) $futureCharge->montoOriginal);
        $this->assertSame(45.0, (float) $futureCharge->montoActual);
        $this->assertStringContainsString('SC-42', $futureCharge->descripcion);
        $this->assertStringNotContainsString('SC-41', $futureCharge->descripcion);
    }

    public function test_immediate_deactivation_reprices_current_unpaid_grouped_cxc(): void
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
            'monto' => 45,
        ]);
        $cxcId = $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41,42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
            'fechaCancelacion' => '2026-10-23',
            'montoOriginal' => 90,
            'montoActual' => 90,
        ]);
        $this->createBajaHistorySchema();

        $processed = (new CxcService())->darDeBajaServicios(
            [41],
            'ahora',
            'Baja inmediata.',
            'si',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame(1, $processed);
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame(45.0, (float) $charge->montoOriginal);
        $this->assertSame(45.0, (float) $charge->montoActual);
        $this->assertStringContainsString('SC-42', $charge->descripcion);
        $this->assertStringNotContainsString('SC-41', $charge->descripcion);
    }

    public function test_immediate_deactivation_deletes_only_the_current_unpaid_cxc_for_the_inactive_service(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $currentCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');
        $pastCxcId = $this->createCxc('CXC 23/08/2026 a 23/09/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'fechaCancelacion' => '2026-10-23',
        ]);
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $pastCxcId)->update([
            'fechaCancelacion' => '2026-09-23',
        ]);

        (new CxcService())->adjustOpenChargesForServiceDeactivation(
            DB::table('serviciocliente')->where('idservicioCliente', 41)->first(),
            Carbon::parse('2026-10-28'),
            true,
            true
        );

        $this->assertDatabaseMissing('cuentasporcobrar', ['idcuentasPorCobrar' => $currentCxcId]);
        $this->assertDatabaseHas('cuentasporcobrar', [
            'idcuentasPorCobrar' => $pastCxcId,
            'estado' => '1',
            'montoActual' => 45,
        ]);
    }

    public function test_immediate_deactivation_preserves_a_current_cxc_with_payment_activity(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->dateTime('fechaPago')->nullable();
            $table->integer('bancos_idbancos')->nullable();
        });
        $cxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'fechaPago' => '2026-10-20 10:00:00',
            'bancos_idbancos' => 15,
        ]);

        (new CxcService())->adjustOpenChargesForServiceDeactivation(
            DB::table('serviciocliente')->where('idservicioCliente', 41)->first(),
            Carbon::parse('2026-10-28'),
            true,
            true
        );

        $this->assertDatabaseHas('cuentasporcobrar', ['idcuentasPorCobrar' => $cxcId]);
        $this->assertDatabaseHas('detallecxc', [
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'bancos_idbancos' => 15,
        ]);
    }

    public function test_immediate_deactivation_of_multiple_services_in_large_fleet_keeps_only_active_amount_in_cxc(): void
    {
        $this->createSchema();
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
            $table->primary(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente']);
        });

        $services = [];
        for ($serviceId = 41; $serviceId <= 60; $serviceId++) {
            $services[] = [
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-09-23',
                'fecheVencimiento' => '2026-10-23',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 52,
            ];
        }
        DB::table('serviciocliente')->insert($services);

        $serviceIds = range(41, 60);
        $cxcId = $this->createCxc(
            'REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-' . implode(',', $serviceIds),
            '1'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
            'montoOriginal' => 1040,
            'montoActual' => 1040,
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert(array_map(
            fn(int $serviceId): array => [
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => 52,
            ],
            $serviceIds
        ));

        $processed = (new CxcService())->darDeBajaServicios(
            [41, 42],
            'ahora',
            'Baja inmediata.',
            'si',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame(2, $processed);
        $this->assertSame(18, DB::table('serviciocliente')->where('estado', 'activo')->count());
        $this->assertSame(2, DB::table('serviciocliente')->where('estado', 'inactivo')->count());
        $this->assertSame('1', (string) $charge->estado);
        $this->assertSame(936.0, (float) $charge->montoOriginal);
        $this->assertSame(936.0, (float) $charge->montoActual);
        $this->assertStringNotContainsString('41', (string) $charge->descripcion);
        $this->assertStringNotContainsString('42', (string) $charge->descripcion);
        $this->assertDatabaseMissing('cuentasporcobrar_serviciocliente', [
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'servicioCliente_idservicioCliente' => 41,
        ]);
        $this->assertDatabaseMissing('cuentasporcobrar_serviciocliente', [
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'servicioCliente_idservicioCliente' => 42,
        ]);
    }

    public function test_grouped_service_renewal_preserves_each_vehicle_expiration_day(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-21',
            'tipoCobro_idtipoCobros' => 6,
        ]);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2025-11-21',
            'fecheVencimiento' => '2026-10-22',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 6,
            'monto' => 45,
        ]);
        $cxcId = $this->createCxc('CXC 23/09/2026 a 22/10/2026 SC-41,42', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
            'fechaCancelacion' => '2026-10-22',
            'montoOriginal' => 90,
            'montoActual' => 0,
        ]);

        (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first(),
            0,
            Carbon::parse('2026-10-28')
        );

        $successors = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'REN-CXC-' . $cxcId . ' P %')
            ->get()
            ->keyBy(function (object $charge): int {
                preg_match('/SC-(\d+)/', (string) $charge->descripcion, $matches);
                return (int) ($matches[1] ?? 0);
            });
        $this->assertSame('2027-01-21', (string) $successors[41]->fechaCancelacion);
        $this->assertSame('2027-01-22', (string) $successors[42]->fechaCancelacion);
        $this->assertSame('2027-01-21', (string) DB::table('serviciocliente')->where('idservicioCliente', 41)->value('fecheVencimiento'));
        $this->assertSame('2027-01-22', (string) DB::table('serviciocliente')->where('idservicioCliente', 42)->value('fecheVencimiento'));
    }

    public function test_scheduled_deactivation_waits_for_each_service_end_then_inactivates_service(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createBajaHistorySchema();

        $service = new CxcService();
        $service->darDeBajaServicios(
            [41],
            'periodo',
            'Fin de contrato.',
            'si',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );
        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));

        $processed = $service->procesarBajasProgramadas(Carbon::parse('2026-10-22'));
        $this->assertSame(0, $processed);
        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));

        $processed = $service->procesarBajasProgramadas(Carbon::parse('2026-10-23'));
        $this->assertSame(1, $processed);
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame(1, DB::table('historial_servicio')->where('motivo', 'Dado de baja')->count());
        $this->assertSame(0, $service->procesarBajasProgramadas(Carbon::parse('2026-10-24')));
    }

    public function test_grouped_scheduled_deactivation_respects_each_service_end_date(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-10-23',
            'fecheVencimiento' => '2026-11-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 45,
        ]);
        $this->createBajaHistorySchema();
        $service = new CxcService();
        $service->darDeBajaServicios(
            [41, 42],
            'periodo',
            'Solicitud grupal.',
            'no',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $this->assertSame(1, $service->procesarBajasProgramadas(Carbon::parse('2026-10-23')));
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 42)->value('estado'));
        $this->assertSame(1, $service->procesarBajasProgramadas(Carbon::parse('2026-11-23')));
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 42)->value('estado'));
    }

    public function test_scheduled_service_is_not_renewed_into_another_period(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('historial_servicio')->insert([
            'usuario_usuario' => 'usuario-prueba',
            'serv_usuario' => 'usuario-prueba',
            'servicioCliente_idservicioCliente' => 41,
            'fecha_accion' => '2026-10-01 10:00:00',
            'motivo' => 'Baja programada al finalizar periodo',
            'descripcion' => 'Baja al fin de periodo (Mantener SIM/Número: Sí)',
        ]);
        $this->createCxc('REN-CXC-0 P 23/09/2026 a 23/10/2026 SC-41', '3');

        $generated = (new CxcService())->generarPeriodosAutomaticos(Carbon::parse('2026-11-01'));

        $this->assertSame(0, $generated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
    }

    public function test_deactivation_is_rejected_when_future_cxc_is_invoiced(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $futureId = $this->createCxc('REN-CXC-1 P 23/10/2026 a 23/11/2026 SC-41', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureId)->update([
            'fechaCancelacion' => '2026-11-23',
            'docReferencia' => 'F001-0001',
        ]);
        $this->createBajaHistorySchema();

        try {
            (new CxcService())->darDeBajaServicios(
                [41],
                'ahora',
                'Baja inmediata.',
                'si',
                'usuario-prueba',
                Carbon::parse('2026-10-01')
            );
            $this->fail('Se esperaba impedir la baja por existir una CXC futura facturada.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('CXC facturada', $exception->getMessage());
        }

        $futureCharge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureId)->first();
        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame('1', $futureCharge->estado);
        $this->assertSame(45.0, (float) $futureCharge->montoOriginal);
        $this->assertSame('F001-0001', $futureCharge->docReferencia);
    }

    public function test_deactivation_is_rejected_when_cxc_has_payment_activity(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->dateTime('fechaPago')->nullable();
            $table->integer('bancos_idbancos')->nullable();
        });
        $cxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'fechaPago' => '2026-10-01 10:00:00',
            'bancos_idbancos' => 15,
        ]);

        try {
            (new CxcService())->darDeBajaServicios(
                [41],
                'ahora',
                'Baja inmediata.',
                'si',
                'usuario-prueba',
                Carbon::parse('2026-10-01')
            );
            $this->fail('Se esperaba impedir la baja por existir pagos registrados.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('pagos/gestiones registradas', $exception->getMessage());
        }

        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame('1', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));
        $this->assertSame('2026-10-01 10:00:00', DB::table('detallecxc')->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)->value('fechaPago'));
    }

    public function test_unmanaged_overdue_cxc_can_be_deactivated(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $cxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '4');

        $processed = (new CxcService())->darDeBajaServicios(
            [41],
            'ahora',
            'Baja de servicio vencido sin gestiones.',
            'si',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $this->assertSame(1, $processed);
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertDatabaseMissing('cuentasporcobrar', ['idcuentasPorCobrar' => $cxcId]);
        $this->assertSame(1, DB::table('historial_servicio')->where('motivo', 'Dado de baja')->count());
    }

    public function test_new_pending_service_can_be_deactivated_without_changing_another_services_invoiced_cxc(): void
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
            'monto' => 45,
        ]);
        $pendingCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');
        $invoicedCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $invoicedCxcId)->update([
            'docReferencia' => 'F001-0002',
        ]);

        $processed = (new CxcService())->darDeBajaServicios(
            [41],
            'ahora',
            'Baja de servicio nuevo pendiente.',
            'si',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $this->assertSame(1, $processed);
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 42)->value('estado'));
        $this->assertDatabaseMissing('cuentasporcobrar', ['idcuentasPorCobrar' => $pendingCxcId]);
        $this->assertDatabaseHas('cuentasporcobrar', [
            'idcuentasPorCobrar' => $invoicedCxcId,
            'docReferencia' => 'F001-0002',
            'estado' => '1',
        ]);
    }

    public function test_mixed_multi_service_deactivation_rolls_back_when_one_service_is_invoiced(): void
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
            'monto' => 45,
        ]);
        $pendingCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '4');
        $invoicedCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-42', '1');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $invoicedCxcId)->update([
            'docReferencia' => 'F001-0002',
        ]);

        try {
            (new CxcService())->darDeBajaServicios(
                [41, 42],
                'ahora',
                'Baja múltiple.',
                'si',
                'usuario-prueba',
                Carbon::parse('2026-10-01')
            );
            $this->fail('Se esperaba impedir todo el lote por existir una CXC facturada.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('CXC facturada o con pagos/gestiones', $exception->getMessage());
        }

        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 42)->value('estado'));
        $this->assertDatabaseHas('cuentasporcobrar', [
            'idcuentasPorCobrar' => $pendingCxcId,
            'estado' => '4',
        ]);
        $this->assertDatabaseHas('cuentasporcobrar', [
            'idcuentasPorCobrar' => $invoicedCxcId,
            'docReferencia' => 'F001-0002',
        ]);
        $this->assertSame(0, DB::table('historial_servicio')->where('motivo', 'Dado de baja')->count());
    }

    public function test_deactivation_is_rejected_for_managed_pending_cxc_states(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $cxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', 'Pendiente a Detracción');

        try {
            (new CxcService())->darDeBajaServicios(
                [41],
                'ahora',
                'Baja inmediata.',
                'si',
                'usuario-prueba',
                Carbon::parse('2026-10-01')
            );
            $this->fail('Se esperaba impedir la baja por el estado gestionado de la CXC.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('CXC facturada o con pagos/gestiones', $exception->getMessage());
        }

        $this->assertSame('activo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        $this->assertSame('Pendiente a Detracción', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->value('estado'));
    }

    public function test_immediate_deactivation_without_service_observacion_updates_grouped_current_and_future_cxcs(): void
    {
        $this->createSchema();
        Schema::table('serviciocliente', function (Blueprint $table): void {
            $table->dropColumn('observacion');
        });
        $this->createOctoberService();
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => 42,
            'cliente_idcliente' => 'CLIENTE-1',
            'estado' => 'activo',
            'fechaInicio' => '2026-09-23',
            'fecheVencimiento' => '2026-10-23',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'monto' => 45,
        ]);
        $currentId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41,42', '1');
        $futureId = $this->createCxc('REN-CXC-' . $currentId . ' P 23/10/2026 a 23/11/2026 SC-41,42', '1');
        foreach ([$currentId, $futureId] as $cxcId) {
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->update([
                'montoOriginal' => 90,
                'montoActual' => 90,
                'fechaCancelacion' => $cxcId === $currentId ? '2026-10-23' : '2026-11-23',
            ]);
        }
        $this->createBajaHistorySchema();

        $processed = (new CxcService())->darDeBajaServicios(
            [41],
            'ahora',
            'Baja inmediata del vehículo.',
            'si',
            'usuario-prueba',
            Carbon::parse('2026-10-01')
        );

        $this->assertSame(1, $processed);
        $this->assertSame('inactivo', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('estado'));
        foreach ([$currentId, $futureId] as $cxcId) {
            $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
            $this->assertSame(45.0, (float) $charge->montoOriginal);
            $this->assertSame(45.0, (float) $charge->montoActual);
            $this->assertStringContainsString('SC-42', $charge->descripcion);
            $this->assertStringNotContainsString('SC-41', $charge->descripcion);
        }
        $history = DB::table('historial_servicio')
            ->where('servicioCliente_idservicioCliente', 41)
            ->where('motivo', 'Dado de baja')
            ->first();
        $this->assertSame('Baja inmediata del vehículo. (Mantener SIM/Número: Sí)', $history->descripcion);
    }

    public function test_advance_cancels_existing_cxcs_only_through_the_last_paid_period(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $this->createCxc('REN-CXC-0 P 15/10/2026 a 15/11/2026 SC-41', '3');
        $this->createCxc('Factura emitida del periodo de diciembre', '1');
        $taxStates = [
            'Pendiente a Detracción',
            'Pendiente Autodetracción',
            'Pendiente a Retención',
            'Pendiente Autoretención',
        ];
        foreach ($taxStates as $index => $state) {
            $this->createCxc(
                'REN-CXC-' . ($index + 2) . ' P 15/11/2026 a 15/12/2026 SC-41',
                $state
            );
        }
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
        $this->assertSame(
            ['3', 'CANCELADO', 'CANCELADO', 'CANCELADO', 'CANCELADO', 'CANCELADO', '1'],
            $states
        );
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('estado', 'CANCELADO')->sum('montoActual'));
    }

    public function test_advance_renews_all_services_after_the_last_paid_period(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2026-10-27',
            'fecheVencimiento' => '2026-11-27',
        ]);

        $serviceIds = [41];
        for ($serviceId = 42; $serviceId <= 50; $serviceId++) {
            $serviceIds[] = $serviceId;
            DB::table('serviciocliente')->insert([
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-10-27',
                'fecheVencimiento' => '2026-11-27',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => 45,
                'almacen_idalmacen' => null,
                'vehiculo_placa' => 'PLACA-' . $serviceId,
                'docReferencia' => null,
            ]);
        }

        $serviceMarker = 'SC-' . implode(',', $serviceIds);
        $currentCxcId = $this->createCxc(
            'REN-CXC-0 P 27/10/2026 a 27/11/2026 ' . $serviceMarker,
            '3'
        );
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'montoOriginal' => 450,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-11-27',
        ]);

        foreach ($serviceIds as $serviceId) {
            $futureCxcId = $this->createCxc(
                'REN-CXC-' . $currentCxcId . ' P 27/11/2026 a 27/12/2026 SC-' . $serviceId,
                '1'
            );
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $futureCxcId)->update([
                'fechaCancelacion' => '2026-12-27',
            ]);
        }

        $advanceUntil = CxcService::advancePeriodEnd('2026-11-27', 2)->toDateString();
        $currentCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $currentCxcId)
            ->first();
        $cancelAdvance = new \ReflectionMethod(CxcService::class, 'cancelarCxcsCubiertasPorAdelanto');
        $cancelAdvance->invoke(new CxcService(), $currentCxc, $serviceIds, '2026-11-27', $advanceUntil);

        $service = new CxcService();
        $service->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-11-27'),
            $serviceIds,
            5,
            $advanceUntil,
            true
        );

        $successors = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'REN-CXC-' . $currentCxcId . ' P 27/12/2026 a 27/01/2027%')
            ->orderBy('idcuentasPorCobrar')
            ->get();

        $this->assertSame('2026-12-27', $advanceUntil);
        $this->assertSame(10, DB::table('cuentasporcobrar')->where('estado', 'CANCELADO')->count());
        $this->assertCount(10, $successors);
        $this->assertSame(
            array_fill(0, 10, '2027-01-27'),
            $successors->pluck('fechaCancelacion')->all()
        );
        $this->assertSame(
            array_fill(0, 10, '2027-01-27'),
            DB::table('serviciocliente')->orderBy('idservicioCliente')->pluck('fecheVencimiento')->all()
        );

        $service->renovarServicioVinculado(
            $currentCxc,
            0,
            Carbon::parse('2026-11-27'),
            $serviceIds,
            5,
            $advanceUntil,
            true
        );

        $this->assertSame(21, DB::table('cuentasporcobrar')->count());
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
        $firstCxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', 'NUEVO');
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

    public function test_changing_end_date_creates_cxc_when_the_previous_service_date_had_no_charge(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fechaInicio' => '2024-01-20',
            'fecheVencimiento' => '2024-06-20',
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2027-06-20',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2027-06-20']
        );

        $this->assertTrue($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $charge = DB::table('cuentasporcobrar')->first();
        $this->assertSame('2027-06-20', $charge->fechaCancelacion);
        $this->assertSame('CXC 20/01/2024 a 20/06/2027 SC-41', $charge->descripcion);
    }

    public function test_changing_end_date_does_not_update_or_duplicate_a_cxc_with_payment_history(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->string('estado');
        });
        $chargeId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', 'NUEVO');
        DB::table('detallecxc')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $chargeId,
            'estado' => '1',
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2027-06-20']
        );

        $this->assertFalse($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-10-23', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $chargeId)
            ->value('fechaCancelacion'));
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $chargeId)
            ->value('descripcion'));
    }

    public function test_adding_end_date_to_active_service_creates_first_cxc_when_none_exists(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-23',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-10-23']
        );

        $this->assertTrue($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $charge = DB::table('cuentasporcobrar')->first();
        $this->assertSame('2026-10-23', $charge->fechaCancelacion);
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', $charge->descripcion);
    }

    public function test_adding_end_date_updates_existing_undated_cxc_without_duplicate(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-23',
        ]);
        $chargeId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'Cobro por servicio ABC-123 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-10-23']
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $chargeId)->first();
        $this->assertTrue($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-10-23', $charge->fechaCancelacion);
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', $charge->descripcion);
    }

    public function test_adding_end_date_updates_marked_cxc_with_existing_period_and_keeps_renewal_prefix(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-23',
        ]);
        $chargeId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'REN-CXC-8111 P 23/09/2026 a 23/10/2026 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-11-23']
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $chargeId)->first();
        $this->assertTrue($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-11-23', $charge->fechaCancelacion);
        $this->assertSame('REN-CXC-8111 P 23/09/2026 a 23/11/2026 SC-41', $charge->descripcion);
    }

    public function test_adding_end_date_updates_marked_cxc_period_in_description(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-23',
        ]);
        $chargeId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'CXC 23/09/2026 a 23/10/2026 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-11-23']
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $chargeId)->first();
        $this->assertTrue($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-11-23', $charge->fechaCancelacion);
        $this->assertSame('CXC 23/09/2026 a 23/11/2026 SC-41', $charge->descripcion);
    }

    public function test_adding_end_date_does_not_require_service_type_charge_column(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-23',
        ]);
        $chargeId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'CXC 23/09/2026 a 23/10/2026 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);
        Schema::table('serviciocliente', function (Blueprint $table): void {
            $table->dropColumn('tipoCobro_idtipoCobros');
        });

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-11-23']
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $chargeId)->first();
        $this->assertTrue($updated);
        $this->assertSame('2026-11-23', $charge->fechaCancelacion);
        $this->assertSame('CXC 23/09/2026 a 23/11/2026 SC-41', $charge->descripcion);
    }

    public function test_adding_end_date_updates_matching_legacy_undated_cxc_without_service_marker(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-23',
        ]);
        $chargeId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'Cobro por servicio ABC-123',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-10-23']
        );

        $charge = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $chargeId)->first();
        $this->assertTrue($updated);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-10-23', $charge->fechaCancelacion);
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', $charge->descripcion);
    }

    public function test_adding_end_date_does_not_reuse_marked_cxc_with_different_start_date(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        $service = DB::table('serviciocliente')->where('idservicioCliente', 41)->first();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-11-23',
        ]);
        DB::table('cuentasporcobrar')->insert([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'CXC 23/10/2026 a 23/11/2026 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);

        $updated = (new CxcService())->synchronizeCurrentServiceChargeDates(
            41,
            $service,
            ['fecheVencimiento' => '2026-11-23']
        );

        $this->assertTrue($updated);
        $this->assertSame(2, DB::table('cuentasporcobrar')->count());
        $this->assertSame(
            'CXC 23/10/2026 a 23/11/2026 SC-41',
            DB::table('cuentasporcobrar')->whereNull('fechaCancelacion')->value('descripcion')
        );
        $this->assertSame(
            'CXC 23/09/2026 a 23/11/2026 SC-41',
            DB::table('cuentasporcobrar')->whereNotNull('fechaCancelacion')->value('descripcion')
        );
    }

    public function test_missing_service_cxc_command_preview_and_execution_are_idempotent(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        $service = new CxcService();

        $preview = $service->registrarCxcsFaltantesServicios();
        $this->assertSame(1, $preview['eligible']);
        $this->assertSame(0, $preview['created']);
        $this->assertSame([41], $preview['service_ids']);
        $this->assertSame(0, DB::table('cuentasporcobrar')->count());

        $execution = $service->registrarCxcsFaltantesServicios(null, true);
        $this->assertSame(1, $execution['created']);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', DB::table('cuentasporcobrar')->value('descripcion'));

        $secondExecution = $service->registrarCxcsFaltantesServicios(null, true);
        $this->assertSame(0, $secondExecution['created']);
        $this->assertSame(1, $secondExecution['existing']);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
    }

    public function test_missing_service_cxc_command_includes_scheduled_deactivation_service(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('historial_servicio')->insert([
            'usuario_usuario' => 'usuario-prueba',
            'serv_usuario' => 'usuario-prueba',
            'servicioCliente_idservicioCliente' => 41,
            'fecha_accion' => '2026-10-01 10:00:00',
            'motivo' => 'Baja programada al finalizar periodo',
            'descripcion' => 'Baja al fin de periodo (Mantener SIM/Número: Sí)',
        ]);

        $summary = (new CxcService())->registrarCxcsFaltantesServicios(41);

        $this->assertSame(1, $summary['eligible']);
        $this->assertSame(1, $summary['to_create']);
        $this->assertSame([41], $summary['service_ids']);
    }

    public function test_missing_service_cxc_command_links_existing_undated_charge(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => null,
        ]);
        DB::table('cuentasporcobrar')->insert([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'docReferencia' => null,
            'descripcion' => 'Cobro por servicio ABC-123 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => null,
            'estado' => '1',
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'fecheVencimiento' => '2026-10-23',
        ]);

        $summary = (new CxcService())->registrarCxcsFaltantesServicios(null, true);

        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('2026-10-23', DB::table('cuentasporcobrar')->value('fechaCancelacion'));
        $this->assertSame('CXC 23/09/2026 a 23/10/2026 SC-41', DB::table('cuentasporcobrar')->value('descripcion'));
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

    public function test_new_service_cxc_uses_the_service_period_type_instead_of_stale_type_id(): void
    {
        $this->createSchema();
        DB::table('almacen')->insert([
            'idalmacen' => 1,
            'periodo' => '3 Meses',
        ]);

        $cxcId = (new CxcService())->crearCobroDesdeServicio([
            'cliente_idcliente' => 'CLIENTE-1',
            'monto' => 45,
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'almacen_idalmacen' => 1,
            'fechaInicio' => '2026-07-23',
            'fecheVencimiento' => '2026-10-23',
            'idservicioCliente' => 41,
        ]);

        $this->assertSame(6, (int) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $cxcId)
            ->value('tipoCobro_idtipoCobros'));
    }

    public function test_missing_cxc_reconciliation_corrects_type_for_existing_service_charge(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert([
            'idalmacen' => 1,
            'periodo' => '3 Meses',
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'tipoCobro_idtipoCobros' => 5,
        ]);
        $cxcId = $this->createCxc('CXC 23/09/2026 a 23/10/2026 SC-41', '1');

        $summary = (new CxcService())->registrarCxcsFaltantesServicios(41, true);

        $this->assertSame(1, $summary['existing']);
        $this->assertSame(6, (int) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $cxcId)
            ->value('tipoCobro_idtipoCobros'));
    }

    public function test_service_renewal_uses_the_service_period_type(): void
    {
        $this->createSchema();
        $this->createOctoberService();
        DB::table('almacen')->insert([
            'idalmacen' => 1,
            'periodo' => '3 Meses',
        ]);
        DB::table('serviciocliente')->where('idservicioCliente', 41)->update([
            'almacen_idalmacen' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'fecheVencimiento' => '2026-10-23',
        ]);
        $currentCxcId = $this->createCxc('CXC 23/07/2026 a 23/10/2026 SC-41', '3');
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->update([
            'fechaCancelacion' => '2026-10-23',
        ]);

        (new CxcService())->renovarServicioVinculado(
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $currentCxcId)->first(),
            0,
            Carbon::parse('2026-10-23')
        );

        $successor = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'REN-CXC-' . $currentCxcId . ' P %')
            ->first();
        $this->assertNotNull($successor);
        $this->assertSame(6, (int) $successor->tipoCobro_idtipoCobros);
        $this->assertSame('2027-01-23', (string) $successor->fechaCancelacion);
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

    private function createSchema(bool $serviceExpirationDateTime = false): void
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
        Schema::create('serviciocliente', function (Blueprint $table) use ($serviceExpirationDateTime): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('estado');
            $table->text('observacion')->nullable();
            $table->date('fechaInicio');
            if ($serviceExpirationDateTime) {
                $table->dateTime('fecheVencimiento')->nullable();
            } else {
                $table->date('fecheVencimiento')->nullable();
            }
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
            $table->string('mod_monto', 30)->nullable();
        });
        $this->createBajaHistorySchema();

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

    private function createBajaHistorySchema(): void
    {
        if (!Schema::hasTable('historial_servicio')) {
            Schema::create('historial_servicio', function (Blueprint $table): void {
                $table->increments('idhistorial_servicio');
                $table->string('usuario_usuario')->nullable();
                $table->string('serv_usuario')->nullable();
                $table->integer('servicioCliente_idservicioCliente');
                $table->dateTime('fecha_accion');
                $table->string('motivo');
                $table->text('descripcion');
                $table->string('doc_referencia')->nullable();
                $table->integer('dispositivo')->nullable();
                $table->string('numerotelefono')->nullable();
            });
        }
        if (!Schema::hasTable('detalle_serviciodispositivo')) {
            Schema::create('detalle_serviciodispositivo', function (Blueprint $table): void {
                $table->increments('iddetalle_serviciodispositivo');
                $table->integer('servicioCliente_idservicioCliente');
                $table->integer('dispositivoCliente_iddispositivoCliente');
            });
        }
    }
}