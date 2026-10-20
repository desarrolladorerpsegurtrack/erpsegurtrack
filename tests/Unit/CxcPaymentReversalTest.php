<?php

namespace Tests\Unit;

use App\Services\CxcService;
use App\Services\CuentasPorCobrarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CxcPaymentReversalTest extends TestCase
{
    public static function groupedReversalScaleCases(): array
    {
        return [
            '25 vehicles across 15 services' => [25, 15],
            '50 vehicles across 20 services' => [50, 20],
        ];
    }

    public function test_reversal_deletes_unpaid_successor_and_restores_service_expiration(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $this->createSchema();
            $this->createPaidCxc();
            $this->createSuccessor();
            $this->createLegacySuccessorForSecondService();

            $result = (new CxcService())->revertirPagoCancelado(10, 'tester', 'Pago registrado por error');

            $this->assertSame([11, 12], $result['cxcSiguientesEliminados']);
            $this->assertSame(1, DB::table('cuentasporcobrar')->count());
            $this->assertSame(0, DB::table('detallecxc')->whereIn('cuentasPorCobrar_idcuentasPorCobrar', [11, 12])->count());
            $this->assertSame(0, DB::table('cuentasporcobrar_serviciocliente')->whereIn('cuentasPorCobrar_idcuentasPorCobrar', [11, 12])->count());
            $this->assertSame('2026-10-31', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('fecheVencimiento'));
            $this->assertSame('2026-10-31', DB::table('serviciocliente')->where('idservicioCliente', 42)->value('fecheVencimiento'));
            $this->assertNull(DB::table('serviciocliente')->whereIn('idservicioCliente', [41, 42])->whereNotNull('docReferencia')->value('docReferencia'));
            $this->assertStringStartsWith('[ANULADO]', DB::table('bancos')->where('idbancos', 1)->value('informacion'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_individual_vehicle_reversal_only_accepts_a_single_service_cxc(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $this->createSchema();
            $this->createPaidCxc();

            try {
                (new CxcService())->revertirPagoCancelado(10, 'tester', 'Anular un vehículo', 41);
                $this->fail('Expected grouped CXC reversal to be rejected for an individual vehicle.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('no se puede anular como pago individual', $exception->getMessage());
            }

            $this->assertSame('3', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 10)->value('estado'));
            $this->assertSame('1', DB::table('detallecxc')->where('cuentasPorCobrar_idcuentasPorCobrar', 10)->value('estado'));

            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 10)->update([
                'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-41',
            ]);
            DB::table('cuentasporcobrar_serviciocliente')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', 10)
                ->where('servicioCliente_idservicioCliente', 42)
                ->delete();
            $this->createSuccessor();

            $result = (new CxcService())->revertirPagoCancelado(10, 'tester', 'Anular un vehículo', 41);

            $this->assertSame([11], $result['cxcSiguientesEliminados']);
            $this->assertSame(1, DB::table('cuentasporcobrar')->count());
            $this->assertNull(DB::table('serviciocliente')->where('idservicioCliente', 41)->value('docReferencia'));
            $this->assertSame('F001-10', DB::table('serviciocliente')->where('idservicioCliente', 42)->value('docReferencia'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reversal_is_blocked_when_successor_has_a_bank_movement(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $this->createSchema();
            $this->createPaidCxc();
            $this->createSuccessor();
            DB::table('bancos')->insert([
                'idbancos' => 2,
                'informacion' => 'Movimiento del siguiente periodo',
                'tipoMov' => 'I',
                'monto' => 45,
                'entidadBancaria_identidadBancaria' => 1,
            ]);
            DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', 11)
                ->update(['bancos_idbancos' => 2]);

            try {
                (new CxcService())->revertirPagoCancelado(10, 'tester', 'Pago registrado por error');
                $this->fail('Expected reversal to be blocked by successor activity.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('ya tiene pagos, movimientos o un documento asociado', $exception->getMessage());
            }

            $this->assertSame(2, DB::table('cuentasporcobrar')->count());
            $this->assertSame('3', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 10)->value('estado'));
            $this->assertSame('Pago original', DB::table('bancos')->where('idbancos', 1)->value('informacion'));
            $this->assertSame('2026-11-30', DB::table('serviciocliente')->where('idservicioCliente', 41)->value('fecheVencimiento'));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('groupedReversalScaleCases')]
    public function test_reversal_restores_all_grouped_vehicles_and_each_individual_price(
        int $vehicleCount,
        int $serviceCount
    ): void {
        Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $this->createSchema();
            $this->createPaidCxc();
            DB::table('serviciocliente')->delete();
            DB::table('cuentasporcobrar_serviciocliente')->delete();
            $serviceIds = range(1001, 1000 + $vehicleCount);
            $serviceRows = [];
            $successorIds = [];
            $groupedIds = array_fill(0, $serviceCount, []);
            $expectedAmounts = [];

            foreach ($serviceIds as $index => $serviceId) {
                $amount = 25.0 + ($index % 11) * 3.5;
                $serviceType = $index % $serviceCount;
                $expectedAmounts['PLATE-' . $serviceId] = $amount;
                $groupedIds[$serviceType][] = $serviceId;
                $serviceRows[] = [
                    'idservicioCliente' => $serviceId,
                    'cliente_idcliente' => 'CLIENT-1',
                    'estado' => 'activo',
                    'fechaInicio' => '2026-10-01',
                    'fecheVencimiento' => '2026-11-30',
                    'monto' => $amount,
                    'docReferencia' => 'F001-GROUP',
                ];
            }
            DB::table('serviciocliente')->insert($serviceRows);

            $total = round(array_sum($expectedAmounts), 2);
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 10)->update([
                'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-' . implode(',', $serviceIds),
                'montoOriginal' => $total,
                'montoActual' => 0,
            ]);
            DB::table('cuentasporcobrar_serviciocliente')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', 10)
                ->delete();
            DB::table('cuentasporcobrar_serviciocliente')->insert(array_map(
                static fn(int $serviceId): array => [
                    'cuentasPorCobrar_idcuentasPorCobrar' => 10,
                    'servicioCliente_idservicioCliente' => $serviceId,
                ],
                $serviceIds
            ));

            $nextId = 11;
            foreach ($serviceIds as $serviceId) {
                $successorIds[] = $nextId;
                DB::table('cuentasporcobrar')->insert([
                    'idcuentasPorCobrar' => $nextId,
                    'cliente_idcliente' => 'CLIENT-1',
                    'tipoCobro_idtipoCobros' => 5,
                    'moneda_idmoneda' => 1,
                    'descripcion' => 'REN-CXC-10 P 31/10/2026 a 30/11/2026 SC-' . $serviceId,
                    'montoOriginal' => $expectedAmounts['PLATE-' . $serviceId],
                    'montoActual' => $expectedAmounts['PLATE-' . $serviceId],
                    'fechaCancelacion' => '2026-11-30',
                    'estado' => '1',
                    'docReferencia' => null,
                ]);
                DB::table('cuentasporcobrar_serviciocliente')->insert([
                    'cuentasPorCobrar_idcuentasPorCobrar' => $nextId,
                    'servicioCliente_idservicioCliente' => $serviceId,
                ]);
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => 1,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $nextId,
                    'moneda_idmoneda' => 1,
                    'fechaPago' => null,
                    'bancos_idbancos' => null,
                    'estado' => '0',
                ]);
                $nextId++;
            }

            $result = (new CxcService())->revertirPagoCancelado(10, 'tester', 'Prueba de reversión grupal');

            $this->assertCount($vehicleCount, $result['cxcSiguientesEliminados']);
            $this->assertSame($successorIds, $result['cxcSiguientesEliminados']);
            $this->assertSame(1, DB::table('cuentasporcobrar')->count());
            $this->assertSame($serviceIds, DB::table('cuentasporcobrar_serviciocliente')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', 10)
                ->orderBy('servicioCliente_idservicioCliente')
                ->pluck('servicioCliente_idservicioCliente')
                ->map(fn($id): int => (int) $id)
                ->all());

            $restoredServices = DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $serviceIds)
                ->orderBy('idservicioCliente')
                ->get();
            $this->assertCount($vehicleCount, $restoredServices);
            $this->assertSame(
                $expectedAmounts,
                $restoredServices->mapWithKeys(fn($service): array => [
                    'PLATE-' . $service->idservicioCliente => (float) $service->monto,
                ])->all()
            );
            $this->assertSame(
                array_fill(0, $vehicleCount, '2026-10-31'),
                $restoredServices->pluck('fecheVencimiento')->all()
            );
            $this->assertNull($restoredServices->firstWhere('docReferencia', '!=', null));

            $displayRows = $restoredServices->map(function ($service) use ($serviceCount, $total): object {
                $serviceIndex = (int) $service->idservicioCliente - 1001;

                return (object) [
                    'idcuentasPorCobrar' => 10,
                    'idservicioCliente' => (int) $service->idservicioCliente,
                    'cxc_estado' => '1',
                    'cxc_docReferencia' => null,
                    'cxc_montoOriginal' => $total,
                    'cxc_montoActual' => $total,
                    'cxc_tipoCobro_idtipoCobros' => 5,
                    'cxc_descripcion' => 'CXC 01/10/2026 a 31/10/2026',
                    'cxc_fechaRegistro' => null,
                    'cxc_fecha_pago' => null,
                    'cxc_fechaCancelacion' => '2026-10-31',
                    'cliente_idcliente' => 'CLIENT-1',
                    'cliente_nombre' => 'Cliente de prueba',
                    'moneda_idmoneda' => 1,
                    'servicio_moneda_id' => 1,
                    'fechaInicio' => '2026-10-01',
                    'fecheVencimiento' => '2026-10-31',
                    'monto' => (float) $service->monto,
                    'estado' => 'activo',
                    'servicio_detalle' => 'Servicio ' . ($serviceIndex % $serviceCount + 1),
                    'servicio_periodo' => null,
                    'vehiculo_placa' => 'PLATE-' . $service->idservicioCliente,
                    'vehiculo_marca' => 'Marca',
                    'vehiculo_modelo' => 'Modelo',
                    'vehiculo_tracto' => '',
                    'tipo_vehiculo' => 'Unidad',
                    'plataforma' => 'Plataforma',
                    'moneda_simbolo' => 'S/',
                    'dias_restantes' => 15,
                ];
            });
            $groups = (new \ReflectionMethod(CuentasPorCobrarService::class, 'groupServices'))
                ->invoke(new CuentasPorCobrarService(), $displayRows, collect(), [], collect());
            $this->assertCount(1, $groups);
            $this->assertSame($vehicleCount, $groups[0]['num_unidades']);
            $this->assertSame($serviceCount, $groups[0]['num_tservicios']);
            $this->assertSame($total, $groups[0]['monto_total']);
            $displayVehicles = collect($groups[0]['tipos_servicios'])
                ->flatMap(fn(array $type): array => $type['vehiculos'])
                ->keyBy('placa');
            $this->assertCount($vehicleCount, $displayVehicles);
            $displayedAmounts = $displayVehicles->map(fn(array $vehicle): float => $vehicle['monto'])->all();
            $sortedExpectedAmounts = $expectedAmounts;
            ksort($displayedAmounts);
            ksort($sortedExpectedAmounts);
            $this->assertSame(
                $sortedExpectedAmounts,
                $displayedAmounts
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createSchema(): void
    {
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion');
            $table->decimal('montoOriginal', 10, 2);
            $table->decimal('montoActual', 10, 2);
            $table->date('fechaCancelacion')->nullable();
            $table->string('estado');
            $table->string('docReferencia')->nullable();
            $table->string('docfactura')->nullable();
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('formaPago_idformaPago');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('moneda_idmoneda')->nullable();
            $table->dateTime('fechaPago')->nullable();
            $table->integer('bancos_idbancos')->nullable();
            $table->string('estado');
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->string('informacion');
            $table->string('tipoMov')->nullable();
            $table->decimal('monto', 10, 2)->nullable();
            $table->integer('entidadBancaria_identidadBancaria')->nullable();
        });
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('estado')->nullable();
            $table->date('fechaInicio')->nullable();
            $table->date('fecheVencimiento')->nullable();
            $table->decimal('monto', 10, 2)->nullable();
            $table->string('docReferencia')->nullable();
        });
        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
        });
    }

    private function createPaidCxc(): void
    {
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 10,
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-41,42',
            'montoOriginal' => 45,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-10-31',
            'estado' => '3',
            'docReferencia' => 'F001-10',
        ]);
        DB::table('serviciocliente')->insert([
            [
                'idservicioCliente' => 41,
                'cliente_idcliente' => 'CLIENT-1',
                'fecheVencimiento' => '2026-11-30',
                'docReferencia' => 'F001-10',
            ],
            [
                'idservicioCliente' => 42,
                'cliente_idcliente' => 'CLIENT-1',
                'fecheVencimiento' => '2026-11-30',
                'docReferencia' => 'F001-10',
            ],
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => 10, 'servicioCliente_idservicioCliente' => 41],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 10, 'servicioCliente_idservicioCliente' => 42],
        ]);
        DB::table('bancos')->insert([
            'idbancos' => 1,
            'informacion' => 'Pago original',
            'tipoMov' => 'I',
            'monto' => 45,
            'entidadBancaria_identidadBancaria' => 1,
        ]);
        DB::table('detallecxc')->insert([
            'formaPago_idformaPago' => 1,
            'cuentasPorCobrar_idcuentasPorCobrar' => 10,
            'moneda_idmoneda' => 1,
            'fechaPago' => '2026-10-15 10:00:00',
            'bancos_idbancos' => 1,
            'estado' => '1',
        ]);
    }

    private function createSuccessor(): void
    {
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 11,
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'REN-CXC-10 P 31/10/2026 a 30/11/2026 SC-41',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-11-30',
            'estado' => '1',
            'docReferencia' => null,
        ]);
        DB::table('detallecxc')->insert([
            'formaPago_idformaPago' => 1,
            'cuentasPorCobrar_idcuentasPorCobrar' => 11,
            'moneda_idmoneda' => 1,
            'fechaPago' => null,
            'bancos_idbancos' => null,
            'estado' => '0',
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => 11,
            'servicioCliente_idservicioCliente' => 41,
        ]);
    }

    private function createLegacySuccessorForSecondService(): void
    {
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 12,
            'cliente_idcliente' => 'CLIENT-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CXC 31/10/2026 a 30/11/2026 SC-42',
            'montoOriginal' => 45,
            'montoActual' => 45,
            'fechaCancelacion' => '2026-11-30',
            'estado' => '1',
            'docReferencia' => null,
        ]);
        DB::table('detallecxc')->insert([
            'formaPago_idformaPago' => 1,
            'cuentasPorCobrar_idcuentasPorCobrar' => 12,
            'moneda_idmoneda' => 1,
            'fechaPago' => null,
            'bancos_idbancos' => null,
            'estado' => '0',
        ]);
    }
}
