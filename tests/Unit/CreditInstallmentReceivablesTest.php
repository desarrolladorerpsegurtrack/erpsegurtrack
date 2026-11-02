<?php

namespace Tests\Unit;

use App\Services\CuentasPorCobrarService;
use App\Services\CxcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreditInstallmentReceivablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->bigIncrements('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->unsignedInteger('tipoCobro_idtipoCobros')->nullable();
            $table->unsignedInteger('moneda_idmoneda');
            $table->string('docReferencia')->nullable();
            $table->string('descripcion', 50)->nullable();
            $table->decimal('montoOriginal', 12, 2);
            $table->decimal('montoActual', 12, 2);
            $table->unsignedInteger('canCuotas')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->date('fechaCancelacion')->nullable();
            $table->string('estado');
        });
    }

    public function test_credit_installments_are_created_as_receivables_with_their_own_due_dates(): void
    {
        DB::table('cuentasporcobrar')->insert([
            'idcuentasPorCobrar' => 10,
            'cliente_idcliente' => 'C-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'docReferencia' => 'FAC-10',
            'descripcion' => 'Servicio SC-101',
            'montoOriginal' => 150,
            'montoActual' => 100,
            'estado' => 'Pendiente a credito',
        ]);

        $parent = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', 10)->first();
        $method = new \ReflectionMethod(CxcService::class, 'createServiceCreditInstallmentReceivables');
        $method->setAccessible(true);
        $method->invoke(new CxcService(), $parent, [
            ['numero' => 1, 'monto' => 50, 'fecha' => '2026-08-20'],
            ['numero' => 2, 'monto' => 50, 'fecha' => '2026-09-10'],
            ['numero' => 3, 'monto' => 50, 'fecha' => '2026-10-14'],
        ], ['fechaPago' => '2026-08-20']);

        $installments = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'CCXC:10:%')
            ->orderBy('fechaCancelacion')
            ->get();

        $this->assertCount(2, $installments);
        $this->assertSame(['2026-09-10', '2026-10-14'], $installments->pluck('fechaCancelacion')->all());
        $this->assertSame([50.0, 50.0], $installments->pluck('montoActual')->map(fn($amount): float => (float) $amount)->all());
        $this->assertSame(['Pendiente cuota crédito', 'Pendiente cuota crédito'], $installments->pluck('estado')->all());
        $this->assertSame(['CCXC:10:2 SC-101', 'CCXC:10:3 SC-101'], $installments->pluck('descripcion')->all());
    }

    public function test_month_filter_limits_visible_installments_but_preserves_all_parent_installments(): void
    {
        DB::table('cuentasporcobrar')->insert([
            [
                'cliente_idcliente' => 'C-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'CCXC:10:2 SC-101',
                'montoOriginal' => 50,
                'montoActual' => 50,
                'fechaCancelacion' => '2026-09-10',
                'estado' => 'PagoCredito',
            ],
            [
                'cliente_idcliente' => 'C-1',
                'moneda_idmoneda' => 1,
                'descripcion' => 'CCXC:10:3 SC-101',
                'montoOriginal' => 50,
                'montoActual' => 50,
                'fechaCancelacion' => '2026-10-14',
                'estado' => 'PagoCredito',
            ],
        ]);
        $service = (object) [
            'idservicioCliente' => 101,
            'cliente_idcliente' => 'C-1',
            'monto' => 150,
            'moneda_idmoneda' => 1,
            'moneda_simbolo' => 'S/',
        ];
        $method = new \ReflectionMethod(CuentasPorCobrarService::class, 'appendCreditInstallmentReceivables');
        $method->setAccessible(true);

        [$visibleServices, $creditChildrenByParent] = $method->invoke(
            new CuentasPorCobrarService(),
            new Collection([$service]),
            ['service_year' => '2026', 'service_months' => ['septiembre']]
        );
        $visibleInstallments = $visibleServices->filter(
            fn($row): bool => (bool) ($row->service_credit_installment ?? false)
        );

        $this->assertCount(1, $visibleInstallments);
        $this->assertSame('2026-09-10', $visibleInstallments->first()->cxc_fechaCancelacion);
        $this->assertCount(2, $creditChildrenByParent[10]);
        $this->assertSame(3, $creditChildrenByParent[10][1]['number']);
    }

    public function test_period_total_includes_regular_service_and_due_credit_installment(): void
    {
        $service = (object) [
            'cliente_idcliente' => 'C-1',
            'moneda_idmoneda' => 1,
            'moneda_simbolo' => 'S/',
            'fechaInicio' => '2026-09-01',
            'fecheVencimiento' => '2026-09-30',
            'idservicioCliente' => 101,
            'idcuentasPorCobrar' => 10,
            'servicio_detalle' => 'Servicio mensual',
            'servicio_periodo' => null,
            'monto' => 150,
            'dias_restantes' => 20,
            'cxc_estado' => 'Pendiente a credito',
            'cxc_montoOriginal' => 150,
            'cxc_montoActual' => 100,
            'cxc_docReferencia' => 'FAC-10',
            'cxc_descripcion' => 'Servicio SC-101',
            'vehiculo_placa' => 'ABC-123',
            'tipo_vehiculo' => '',
            'vehiculo_marca' => '',
            'vehiculo_modelo' => '',
            'vehiculo_tracto' => '',
            'id_dispositivo' => '',
            'numero_sim' => '',
        ];
        $installment = clone $service;
        $installment->idservicioCliente = 0;
        $installment->idcuentasPorCobrar = 11;
        $installment->servicio_detalle = 'Cuota 2 crédito';
        $installment->monto = 50;
        $installment->cxc_estado = 'PagoCredito';
        $installment->cxc_montoActual = 50;
        $installment->cxc_fechaCancelacion = '2026-09-10';
        $installment->fechaInicio = '2026-09-10';
        $installment->fecheVencimiento = '2026-09-10';
        $installment->service_credit_installment = true;

        $method = new \ReflectionMethod(CuentasPorCobrarService::class, 'groupServices');
        $method->setAccessible(true);
        $groups = $method->invoke(
            new CuentasPorCobrarService(),
            new Collection([$service, $installment]),
            new Collection(),
            [],
            new Collection(),
            [10 => [['id' => 11, 'number' => 2, 'amount' => 50, 'date' => '2026-09-10']]]
        );

        $this->assertCount(1, $groups);
        $this->assertSame(200.0, $groups[0]['monto_total']);
    }

    public function test_grouped_credit_first_installment_is_distributed_across_receivables(): void
    {
        $method = new \ReflectionMethod(CxcService::class, 'allocateCreditPlanCashAcrossReceivables');
        $method->setAccessible(true);

        $allocations = $method->invoke(
            new CxcService(),
            new Collection([
                (object) ['idcuentasPorCobrar' => 10, 'montoActual' => 100],
                (object) ['idcuentasPorCobrar' => 20, 'montoActual' => 200],
            ]),
            90,
            0,
            0,
            []
        );

        $this->assertSame([30.0, 60.0], array_values($allocations));
    }

    public function test_grouped_credit_installments_are_split_by_receivable_and_due_date(): void
    {
        $method = new \ReflectionMethod(CxcService::class, 'distributeCreditInstallmentsAcrossReceivables');
        $method->setAccessible(true);
        $items = [
            [
                'id' => 10,
                'balance' => 100,
                'data' => [
                    'monto_aplicado' => 30,
                    'monto_deduccion' => 0,
                    'monto_deduccion_pendiente' => 0,
                    'monto_saldo_favor_aplicado' => 0,
                    'monto_nota_credito_aplicado' => 0,
                    'monto_adelantado' => 0,
                    'monto_excedente' => 0,
                ],
            ],
            [
                'id' => 20,
                'balance' => 200,
                'data' => [
                    'monto_aplicado' => 60,
                    'monto_deduccion' => 0,
                    'monto_deduccion_pendiente' => 0,
                    'monto_saldo_favor_aplicado' => 0,
                    'monto_nota_credito_aplicado' => 0,
                    'monto_adelantado' => 0,
                    'monto_excedente' => 0,
                ],
            ],
        ];

        $distributed = $method->invoke(
            new CxcService(),
            [
                ['numero' => 1, 'monto' => 90, 'fecha' => '2026-08-20'],
                ['numero' => 2, 'monto' => 90, 'fecha' => '2026-09-20'],
                ['numero' => 3, 'monto' => 120, 'fecha' => '2026-10-20'],
            ],
            $items
        );

        $this->assertSame([30.0, 70.0, 0.0], array_column($distributed[0]['data']['cuotas'], 'monto'));
        $this->assertSame([60.0, 20.0, 120.0], array_column($distributed[1]['data']['cuotas'], 'monto'));
        $this->assertSame(
            [90.0, 90.0, 120.0],
            array_map(
                fn (int $index): float => round(
                    (float) $distributed[0]['data']['cuotas'][$index]['monto']
                    + (float) $distributed[1]['data']['cuotas'][$index]['monto'],
                    2
                ),
                [0, 1, 2]
            )
        );
    }

    public function test_credit_installment_payment_options_aggregate_children_for_each_due_installment(): void
    {
        $method = new \ReflectionMethod(CuentasPorCobrarService::class, 'creditInstallmentOptions');
        $method->setAccessible(true);

        $options = $method->invoke(
            new CuentasPorCobrarService(),
            new Collection([
                (object) ['idcuentasPorCobrar' => 10, 'service_credit_installment' => false],
                (object) ['idcuentasPorCobrar' => 20, 'service_credit_installment' => false],
            ]),
            [
                10 => [
                    ['id' => 101, 'number' => 2, 'amount' => 34.67, 'date' => '2026-11-10'],
                    ['id' => 102, 'number' => 3, 'amount' => 34.66, 'date' => '2026-12-10'],
                ],
                20 => [
                    ['id' => 201, 'number' => 2, 'amount' => 52.00, 'date' => '2026-11-10'],
                    ['id' => 202, 'number' => 3, 'amount' => 52.00, 'date' => '2026-12-10'],
                ],
            ]
        );

        $this->assertSame([
            [
                'number' => 2,
                'date' => '2026-11-10',
                'amount' => 86.67,
                'ids' => [101, 201],
            ],
            [
                'number' => 3,
                'date' => '2026-12-10',
                'amount' => 86.66,
                'ids' => [102, 202],
            ],
        ], $options);
    }
}
