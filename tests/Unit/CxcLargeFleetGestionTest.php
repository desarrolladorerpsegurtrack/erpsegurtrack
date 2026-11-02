<?php

namespace Tests\Unit;

use App\Services\CxcService;
use App\Services\CuentasPorCobrarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CxcLargeFleetGestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            $pdo->sqliteCreateFunction('concat', fn(...$args) => implode('', $args));
            $pdo->sqliteCreateFunction('regexp', fn($pattern, $value) => (int) @preg_match('/' . str_replace('/', '\/', $pattern) . '/', (string) $value));
            $pdo->sqliteCreateFunction('str_to_date', function ($str, $format) {
                try {
                    return Carbon::createFromFormat('d/m/Y', trim((string) $str))->format('Y-m-d');
                } catch (\Throwable) {
                    return substr((string) $str, 0, 10);
                }
            });
            $pdo->sqliteCreateFunction('date', fn($val) => substr((string) $val, 0, 10));
            $pdo->sqliteCreateFunction('substring_index', function ($string, $delimiter, $count) {
                $parts = explode($delimiter, (string) $string);
                if ($count > 0) {
                    return implode($delimiter, array_slice($parts, 0, $count));
                }
                return implode($delimiter, array_slice($parts, $count));
            });
        }

        $this->createTestSchema();
    }

    private function createTestSchema(): void
    {
        Schema::dropIfExists('evidenciapago');
        Schema::dropIfExists('detallecxc');
        Schema::dropIfExists('bancos');
        Schema::dropIfExists('historial_servicio');
        Schema::dropIfExists('cuentasporcobrar_serviciocliente');
        Schema::dropIfExists('serviciocliente');
        Schema::dropIfExists('cuentasporcobrar');
        Schema::dropIfExists('formapago');
        Schema::dropIfExists('entidadbancaria');
        Schema::dropIfExists('moneda');
        Schema::dropIfExists('tipocobro');
        Schema::dropIfExists('almacen');
        Schema::dropIfExists('tipoelemento');
        Schema::dropIfExists('plataforma');
        Schema::dropIfExists('tipovehiculo');
        Schema::dropIfExists('vehiculo');
        Schema::dropIfExists('cliente');

        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('detraccion')->nullable(); // '0' = detracción estándar 12%, '1' = agente retención 3%
            $table->tinyInteger('flag_integrador')->default(0);
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
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

        Schema::create('plataforma', function (Blueprint $table): void {
            $table->increments('idplataforma');
            $table->string('nombrePlataforma')->nullable();
        });

        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->increments('idtipoElemento');
            $table->integer('plataforma_idplataforma')->nullable();
        });

        Schema::create('almacen', function (Blueprint $table): void {
            $table->increments('idalmacen');
            $table->string('detalle')->nullable();
            $table->string('periodo')->nullable();
            $table->integer('tipoElemento_idtipoElemento')->nullable();
        });

        Schema::create('tipocobro', function (Blueprint $table): void {
            $table->increments('idtipoCobros');
            $table->string('recurrencia');
            $table->integer('tiempo');
        });

        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle')->nullable();
            $table->string('simbolo')->nullable();
        });

        Schema::create('entidadbancaria', function (Blueprint $table): void {
            $table->increments('identidadBancaria');
            $table->string('razonSocial')->nullable();
        });

        Schema::create('formapago', function (Blueprint $table): void {
            $table->increments('idformaPago');
            $table->string('detalle');
            $table->integer('tiempo')->nullable();
        });

        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->integer('entidadBancaria_identidadBancaria');
            $table->dateTime('fechaRegistro');
            $table->string('informacion', 250);
            $table->decimal('monto', 20, 2);
            $table->string('tipoMov', 1);
        });

        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('docReferencia')->nullable();
            $table->string('docfactura')->nullable();
            $table->string('descripcion', 255);
            $table->decimal('montoOriginal', 20, 2);
            $table->decimal('montoActual', 20, 2);
            $table->integer('canCuotas')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->dateTime('fechaCancelacion')->nullable();
            $table->string('estado', 50);
            $table->string('mod_monto', 30)->nullable();
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

        Schema::create('cuentasporcobrar_serviciocliente', function (Blueprint $table): void {
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('servicioCliente_idservicioCliente');
            $table->decimal('montoServicio', 12, 2)->nullable();
            $table->primary(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente']);
        });

        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('formaPago_idformaPago');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('moneda_idmoneda');
            $table->string('descripcion', 100)->nullable();
            $table->dateTime('fechaPagoProgramada')->nullable();
            $table->dateTime('fechaPago')->nullable();
            $table->integer('nCuota')->nullable();
            $table->integer('bancos_idbancos');
            $table->string('estado', 1)->nullable();
        });

        Schema::create('evidenciapago', function (Blueprint $table): void {
            $table->increments('idevidenciaPago');
            $table->dateTime('fechaCarga');
            $table->string('archivo');
            $table->integer('bancos_idbancos');
        });

        Schema::create('detalle_serviciodispositivo', function (Blueprint $table): void {
            $table->increments('iddetalle_serviciodispositivo');
            $table->integer('servicioCliente_idservicioCliente');
            $table->integer('dispositivoCliente_iddispositivoCliente')->nullable();
        });

        Schema::create('detnumerosdispositivo', function (Blueprint $table): void {
            $table->increments('iddetNumerosDispositivo');
            $table->integer('dispositivoCliente_iddispositivoCliente');
            $table->string('numeroTelefonico_numeroTelefonico')->nullable();
            $table->dateTime('fechaAsignacion')->nullable();
        });

        DB::table('tipocobro')->insert(['idtipoCobros' => 1, 'recurrencia' => 'M', 'tiempo' => 1]);
        DB::table('moneda')->insert(['idmoneda' => 1, 'detalle' => 'Soles', 'simbolo' => 'S/']);
        DB::table('entidadbancaria')->insert([
            ['identidadBancaria' => 1, 'razonSocial' => 'Banco de Crédito'],
            ['identidadBancaria' => 2, 'razonSocial' => 'Banco de la Nación'],
        ]);
        DB::table('formapago')->insert(['idformaPago' => 1, 'detalle' => 'Transferencia']);
        DB::table('tipovehiculo')->insert(['idtipoVehiculo' => 1, 'nombre' => 'Camión']);
        DB::table('plataforma')->insert(['idplataforma' => 1, 'nombrePlataforma' => 'GPS Track']);
        DB::table('tipoelemento')->insert(['idtipoElemento' => 1, 'plataforma_idplataforma' => 1]);
        DB::table('almacen')->insert(['idalmacen' => 1, 'detalle' => 'Servicio GPS Mensual', 'periodo' => '1 mes', 'tipoElemento_idtipoElemento' => 1]);
    }

    private function seedFleet(
        string $clientId,
        int $vehicleCount = 35,
        int $extraServiceCount = 5,
        float $unitPrice = 50.0,
        string $startDate = '2026-10-01',
        string $endDate = '2026-10-31'
    ): array {
        $serviceIds = [];
        $totalItems = $vehicleCount + $extraServiceCount;

        for ($i = 1; $i <= $vehicleCount; $i++) {
            $placa = sprintf('V%02d-%03d', (int) substr(preg_replace('/\D/', '', $clientId) ?: '1', -2), $i);
            DB::table('vehiculo')->updateOrInsert(['placa' => $placa], [
                'marca' => 'Volvo',
                'modelo' => 'FH',
                'tracto' => 'Si',
                'tipoUnidad_idtable1' => 1,
            ]);

            $serviceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => $startDate,
                'fecheVencimiento' => $endDate,
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => $unitPrice,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => $placa,
                'docReferencia' => null,
            ]);
            $serviceIds[] = $serviceId;
        }

        for ($j = 1; $j <= $extraServiceCount; $j++) {
            $serviceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => $startDate,
                'fecheVencimiento' => $endDate,
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => $unitPrice,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => null,
                'docReferencia' => null,
            ]);
            $serviceIds[] = $serviceId;
        }

        $totalGross = $totalItems * $unitPrice;
        $description = 'CXC ' . Carbon::parse($startDate)->format('d/m/Y') . ' a '
            . Carbon::parse($endDate)->format('d/m/Y') . ' SC-' . implode(',', $serviceIds);

        $this->assertLessThanOrEqual(255, mb_strlen($description));

        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clientId,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => $description,
            'montoOriginal' => $totalGross,
            'montoActual' => $totalGross,
            'fechaCancelacion' => $endDate,
            'estado' => '1',
        ]);

        foreach ($serviceIds as $sId) {
            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $sId,
                'montoServicio' => $unitPrice,
            ]);
        }

        return ['serviceIds' => $serviceIds, 'cxcId' => $cxcId, 'totalGross' => $totalGross];
    }

    private function seedAdditionalVehicleCharges(
        string $clientId,
        int $count,
        float $unitPrice = 50.0,
        string $startDate = '2026-10-01',
        string $endDate = '2026-10-31'
    ): array {
        $serviceIds = [];
        $cxcIds = [];
        $platePrefix = substr(preg_replace('/[^A-Z0-9]/i', '', $clientId) ?: 'CLIENT', 0, 8);

        for ($index = 1; $index <= $count; $index++) {
            $plate = sprintf('AD-%s-%02d', $platePrefix, $index);
            DB::table('vehiculo')->insert([
                'placa' => $plate,
                'marca' => 'Mercedes',
                'modelo' => 'Actros',
                'tracto' => 'Si',
                'tipoUnidad_idtable1' => 1,
            ]);

            $serviceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => $startDate,
                'fecheVencimiento' => $endDate,
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => $unitPrice,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => $plate,
                'docReferencia' => null,
            ]);
            $serviceIds[] = $serviceId;

            $cxcId = DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => $clientId,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'docReferencia' => null,
                'descripcion' => 'CXC ' . Carbon::parse($startDate)->format('d/m/Y')
                    . ' a ' . Carbon::parse($endDate)->format('d/m/Y') . ' SC-' . $serviceId,
                'montoOriginal' => $unitPrice,
                'montoActual' => $unitPrice,
                'fechaCancelacion' => $endDate,
                'estado' => '1',
            ]);
            $cxcIds[] = $cxcId;
            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => $unitPrice,
            ]);
        }

        return ['serviceIds' => $serviceIds, 'cxcIds' => $cxcIds];
    }

    private function seedAdvanceReceivables(
        string $clientId,
        int $vehicleCount,
        int $additionalServiceCount
    ): array {
        $serviceIds = [];
        $cxcIds = [];
        $currentPeriodTotal = 0.0;
        $monthlyRecurringTotal = 0.0;

        for ($index = 0; $index < $vehicleCount + $additionalServiceCount; $index++) {
            $isVehicle = $index < $vehicleCount;
            $serviceAmount = $isVehicle ? 50.0 : 40.0;
            $currentAmount = $index === 0 ? 32.0 : $serviceAmount;
            $plate = $isVehicle ? 'ADV-' . $clientId . '-' . $index : null;
            if ($plate !== null) {
                DB::table('vehiculo')->insert([
                    'placa' => $plate,
                    'marca' => 'Mercedes',
                    'modelo' => 'Actros',
                    'tracto' => 'Si',
                    'tipoUnidad_idtable1' => 1,
                ]);
            }

            $serviceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => '2026-10-01',
                'fecheVencimiento' => '2026-10-31',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => $serviceAmount,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => $plate,
                'docReferencia' => null,
            ]);
            $serviceIds[] = $serviceId;

            $cxcId = DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => $clientId,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-' . $serviceId,
                'montoOriginal' => $currentAmount,
                'montoActual' => $currentAmount,
                'fechaCancelacion' => '2026-10-31',
                'estado' => '1',
            ]);
            $cxcIds[] = $cxcId;
            $currentPeriodTotal += $currentAmount;
            $monthlyRecurringTotal += $serviceAmount;

            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => $currentAmount,
            ]);
        }

        return [
            'serviceIds' => $serviceIds,
            'cxcIds' => $cxcIds,
            'currentPeriodTotal' => $currentPeriodTotal,
            'monthlyRecurringTotal' => $monthlyRecurringTotal,
        ];
    }

    public static function advancePaymentScaleCases(): array
    {
        return [
            'one vehicle and one service' => [1, 1, 5],
            'few vehicles and three periods' => [3, 2, 3],
            'few vehicles and services' => [3, 2, 5],
            'large fleet and three periods' => [50, 20, 3],
            'large fleet and eight periods' => [50, 20, 8],
            'large fleet and twelve periods' => [50, 20, 12],
        ];
    }

    #[DataProvider('advancePaymentScaleCases')]
    public function test_group_advance_uses_all_service_prices_and_prorates_only_the_current_period(
        int $vehicleCount,
        int $additionalServiceCount,
        int $coveredPeriods
    ): void {
        $clientId = 'CLI-ADV-' . $vehicleCount . '-' . $additionalServiceCount . '-' . $coveredPeriods;
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente adelanto de prueba',
        ]);
        $receivables = $this->seedAdvanceReceivables(
            $clientId,
            $vehicleCount,
            $additionalServiceCount
        );
        $expectedGross = round(
            $receivables['currentPeriodTotal']
                + $receivables['monthlyRecurringTotal'] * ($coveredPeriods - 1),
            2
        );
        $expectedDeduction = $expectedGross >= 700
            ? round($expectedGross * 0.12)
            : 0.0;
        $expectedCash = round($expectedGross - $expectedDeduction, 2);

        $payment = (new CxcService())->procesarPagoGrupo($receivables['cxcIds'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => $expectedCash,
            'monto_factura' => $expectedGross,
            'adelanto_meses' => $coveredPeriods,
            'adelanto_desde' => '2026-10-31',
            'adelanto_hasta' => CxcService::advancePeriodEnd('2026-10-31', $coveredPeriods)->toDateString(),
            'fechaPago' => '2026-10-30',
            'docReferencia' => 'F001-ADV-' . $coveredPeriods,
            'descripcion' => 'Adelanto de servicios seleccionados',
            'withholding_decision' => $expectedDeduction > 0 ? 'paid' : '',
            'servicio_ids' => $receivables['serviceIds'],
            'renewal_cxc_id' => $receivables['cxcIds'][0],
            'renewal_cxc_ids' => $receivables['cxcIds'],
        ]);

        $this->assertSame($expectedGross, (float) $payment['gross']);
        $this->assertSame($expectedDeduction, (float) $payment['deduction']);
        $this->assertSame($expectedCash, (float) $payment['cash_due']);
        $this->assertSame(0.0, (float) $payment['remaining_debt']);
        $this->assertSame(
            array_fill(0, count($receivables['cxcIds']), 0.0),
            DB::table('cuentasporcobrar')
                ->whereIn('idcuentasPorCobrar', $receivables['cxcIds'])
                ->orderBy('idcuentasPorCobrar')
                ->pluck('montoActual')
                ->map(fn ($amount): float => (float) $amount)
                ->all()
        );
        $this->assertSame(
            $expectedDeduction,
            (float) DB::table('bancos')->where('tipoMov', 'T')->sum('monto')
        );
        $this->assertSame(
            $expectedCash,
            (float) DB::table('bancos')->where('tipoMov', 'I')->sum('monto')
        );
        $expectedServiceEnd = CxcService::advancePeriodEnd('2026-10-31', $coveredPeriods)
            ->addMonthNoOverflow()
            ->toDateString();
        $this->assertSame(
            array_fill(0, count($receivables['serviceIds']), $expectedServiceEnd),
            DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $receivables['serviceIds'])
                ->orderBy('idservicioCliente')
                ->pluck('fecheVencimiento')
                ->all()
        );
    }

    public static function documentedProcessStates(): array
    {
        return [
            'invoiced' => ['FACTURADO', 'Facturado', 2500.0],
            'partial payment' => ['Pendiente Pago parcial', 'Pendiente Pago parcial', 1250.0],
            'credit' => ['Pendiente a credito', 'Pendiente a credito', 2500.0],
            'credit installment' => ['Pendiente cuota crédito', 'Pendiente cuota crédito', 2500.0],
            'autodetracción' => ['Pendiente Autodetracción', 'Pendiente Autodetracción', 300.0],
            'autoretención' => ['Pendiente Autoretención', 'Pendiente Autoretención', 75.0],
            'detracción' => ['Pendiente a Detracción', 'Pendiente a Detracción', 300.0],
            'retención' => ['Pendiente a Retención', 'Pendiente a Retención', 75.0],
        ];
    }

    /**
     * Prueba 1: Gestión en Grupo con Detracción (12%) para 35 vehículos y 5 servicios (40 items).
     * Total bruto = S/ 2,000.00 (>= 700 aplica 12% = S/ 240.00).
     * Neto a pagar = S/ 1,760.00.
     * Cliente responde NO pagó la detracción aún -> queda Pendiente a Detracción.
     */
    public function test_01_group_gestion_with_detraccion_for_35_vehicles_and_5_services(): void
    {
        $clientId = 'CLI-DET-1';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0', // '0' = detracción estándar 12%
            'flag_integrador' => 0,
            'razonSocial' => 'Transportes Grandes SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 35, 5, 50.0);
        $cxcId = $fleet['cxcId'];
        $serviceIds = $fleet['serviceIds'];
        $this->assertCount(40, $serviceIds);

        $cxcService = new CxcService();

        // Registrar pago neto en grupo dejando detracción pendiente
        $paymentResult = $cxcService->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 1760.00,
            'fechaPago' => '2026-10-25',
            'docReferencia' => 'F001-1001',
            'descripcion' => 'Cobro grupal 40 servicios con detracción',
            'withholding_decision' => 'pending',
            'servicio_ids' => $serviceIds,
            'renewal_cxc_id' => $cxcId,
        ]);

        // Verificaciones del resultado del cobro
        $this->assertSame(240.0, $paymentResult['remaining_debt']);
        $this->assertSame(1760.0, $paymentResult['cash_received']);
        $this->assertSame(0.0, $paymentResult['new_credit']);

        // Verificación en BD
        $cxcDb = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame('Pendiente a Detracción', $cxcDb->estado);
        $this->assertSame(240.0, (float) $cxcDb->montoActual);
        $this->assertSame('F001-1001', $cxcDb->docReferencia);

        // Verificar que los 40 servicios siguen vinculados en la tabla pivote
        $linkedCount = DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
            ->count();
        $this->assertSame(40, $linkedCount);
        $this->assertSame(1760.0, (float) DB::table('bancos')->where('tipoMov', 'I')->sum('monto'));
        $this->assertSame(
            44.0,
            (float) DB::table('bancos')->where('tipoMov', 'I')->orderBy('idbancos')->value('monto')
        );

        // Completar la detracción pendiente a través de registrarDetraccionPendiente
        $completeResult = $cxcService->registrarDetraccionPendiente(
            $cxcId,
            null,
            $serviceIds,
            'Pago de detracción pendiente al Banco de la Nación',
            '2026-10-28',
            true
        );

        $this->assertSame('Detracción', $completeResult['tipoDeduccion']);
        $cxcDbFinal = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame('3', $cxcDbFinal->estado); // CANCELADO
        $this->assertSame(0.0, (float) $cxcDbFinal->montoActual);

        // Se deben haber generado las renovaciones para el siguiente periodo
        $renewalCxc = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $clientId)
            ->where('idcuentasPorCobrar', '!=', $cxcId)
            ->whereIn('estado', ['1', 'PENDIENTE'])
            ->get();
        $this->assertNotEmpty($renewalCxc);
    }

    public function test_grouped_paid_withholding_is_recorded_as_one_bank_movement(): void
    {
        Storage::fake('public');
        $service = new CxcService();
        foreach ([
            ['client_id' => 'DET-GROUP-PAID', 'detraccion' => '0', 'gross' => 960.0, 'cash' => 845.0, 'deduction' => 115.0, 'label' => 'Detracción'],
            ['client_id' => 'RET-GROUP-PAID', 'detraccion' => '1', 'gross' => 960.0, 'cash' => 931.0, 'deduction' => 29.0, 'label' => 'Retención'],
        ] as $case) {
            DB::table('cliente')->insert([
                'idcliente' => $case['client_id'],
                'detraccion' => $case['detraccion'],
                'flag_integrador' => 0,
                'razonSocial' => 'Corporacion Peter The Great Sociedad Anonima Cerrada',
                'nombreComercial' => 'Corporacion Peter The Great S.A.C.',
            ]);
            $fleet = $this->seedAdditionalVehicleCharges($case['client_id'], 16, 60.0);
            $service->procesarPagoGrupo($fleet['cxcIds'], [
                'entidadBancaria_identidadBancaria' => 1,
                'formaPago_idformaPago' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'modo_pago' => 'total',
                'montoPago' => $case['cash'],
                'fechaPago' => '2026-10-26',
                'withholding_fecha' => '2026-10-26',
                'withholding_decision' => 'paid',
                'withholding_description' => 'Constancia SUNAT',
                'withholding_proof' => UploadedFile::fake()->create('constancia.pdf', 10, 'application/pdf'),
                'docReferencia' => 'F001-' . ($case['detraccion'] === '1' ? 'RET' : 'DET'),
                'descripcion' => 'Pago agrupado de octubre',
                'servicio_ids' => $fleet['serviceIds'],
                'renewal_cxc_id' => $fleet['cxcIds'][0],
                'renewal_cxc_ids' => [],
            ]);

            $withholdingMovements = DB::table('bancos')
                ->where('tipoMov', 'T')
                ->where('informacion', 'like', $case['label'] . ' pagada de octubre 2026%')
                ->get();
            $this->assertCount(1, $withholdingMovements);
            $this->assertSame($case['deduction'], (float) $withholdingMovements->first()->monto);
            $this->assertSame(
                $case['label'] . ' pagada de octubre 2026 - Corporacion Peter The Great Sociedad Anonima Cerrada'
                    . ' - Corporacion Peter The Great S.A.C. | Constancia SUNAT',
                $withholdingMovements->first()->informacion
            );
            $this->assertSame(1, DB::table('evidenciapago')
                ->where('bancos_idbancos', $withholdingMovements->first()->idbancos)
                ->count());
            $this->assertSame(
                16,
                DB::table('detallecxc')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $fleet['cxcIds'])
                    ->where('bancos_idbancos', $withholdingMovements->first()->idbancos)
                    ->where('descripcion', $case['label'] . ' aplicada')
                    ->count()
            );
            $this->assertSame($case['gross'] - $case['deduction'], (float) DB::table('detallecxc as payment_detail')
                ->join('bancos as payment_bank', 'payment_bank.idbancos', '=', 'payment_detail.bancos_idbancos')
                ->whereIn('payment_detail.cuentasPorCobrar_idcuentasPorCobrar', $fleet['cxcIds'])
                ->where('payment_bank.tipoMov', 'I')
                ->sum('payment_bank.monto'));
        }
    }

    public function test_service_income_bank_information_keeps_long_observation(): void
    {
        $clientId = 'LONG-INCOME-INFO';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente de prueba',
        ]);
        $fleet = $this->seedAdditionalVehicleCharges($clientId, 1, 60.0);
        $observation = 'Observacion completa del usuario: ' . str_repeat('detalle del servicio ', 4);
        $observation = mb_substr($observation, 0, 80, 'UTF-8');

        (new CxcService())->procesarPagoGrupo($fleet['cxcIds'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 60,
            'fechaPago' => '2026-10-26',
            'docReferencia' => 'F001-LONG',
            'descripcion' => $observation,
            'servicio_ids' => $fleet['serviceIds'],
            'renewal_cxc_id' => $fleet['cxcIds'][0],
            'renewal_cxc_ids' => [],
        ]);

        $information = DB::table('bancos')->where('tipoMov', 'I')->value('informacion');
        $this->assertGreaterThan(100, mb_strlen($information, 'UTF-8'));
        $this->assertStringEndsWith('(' . $observation . ')', $information);
    }

    public function test_grouped_pending_withholding_completes_every_cxc_at_its_exact_amount(): void
    {
        $clientId = 'CLI-DET-GROUP';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Transportes Grupo SAC',
        ]);

        $cxcIds = [];
        foreach ([10.75, 93.25] as $index => $pendingAmount) {
            $serviceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => '2026-10-01',
                'fecheVencimiento' => '2026-10-31',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => 75,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => 'DET-' . $index,
                'docReferencia' => 'F001-DET',
            ]);
            $cxcId = DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => $clientId,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'docReferencia' => 'F001-DET',
                'descripcion' => 'CXC SC-' . $serviceId,
                'montoOriginal' => 75,
                'montoActual' => $pendingAmount,
                'fechaRegistro' => '2026-10-01 00:00:00',
                'fechaCancelacion' => null,
                'estado' => 'Pendiente a Detracción',
            ]);
            $cxcIds[] = $cxcId;
            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => 75,
            ]);
            $paymentBankId = DB::table('bancos')->insertGetId([
                'entidadBancaria_identidadBancaria' => 1,
                'fechaRegistro' => '2026-10-21 12:00:00',
                'informacion' => 'Pago recibido',
                'monto' => 75 - $pendingAmount,
                'tipoMov' => 'I',
            ]);
            DB::table('detallecxc')->insert([
                'formaPago_idformaPago' => 1,
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'moneda_idmoneda' => 1,
                'descripcion' => 'Detracción pendiente: ' . number_format($pendingAmount, 2, '.', ''),
                'fechaPagoProgramada' => '2026-10-21 12:00:00',
                'fechaPago' => null,
                'nCuota' => null,
                'bancos_idbancos' => $paymentBankId,
                'estado' => '0',
            ]);
        }

        $result = (new CxcService())->registrarDetraccionesPendientes(
            $cxcIds,
            null,
            [],
            'Depósito agrupado',
            '2026-10-21'
        );

        $this->assertSame(104.0, $result['montoDeduccion']);
        $this->assertSame(104.0, (float) DB::table('bancos')->where('tipoMov', 'T')->sum('monto'));
        $this->assertSame(1, DB::table('bancos')->where('tipoMov', 'T')->count());
        $withholdingMovement = DB::table('bancos')->where('tipoMov', 'T')->first();
        $this->assertSame(104.0, (float) $withholdingMovement->monto);
        $this->assertStringStartsWith(
            'Detracción pagada de octubre 2026 - Transportes Grupo SAC',
            $withholdingMovement->informacion
        );
        $this->assertStringContainsString('Depósito agrupado', $withholdingMovement->informacion);
        $this->assertSame(
            [$withholdingMovement->idbancos, $withholdingMovement->idbancos],
            DB::table('detallecxc')
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
                ->where('descripcion', 'like', 'Detracción aplicada:%')
                ->orderBy('cuentasPorCobrar_idcuentasPorCobrar')
                ->pluck('bancos_idbancos')
                ->all()
        );
        $this->assertSame(['3', '3'], DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', $cxcIds)
            ->orderBy('idcuentasPorCobrar')
            ->pluck('estado')
            ->all());
    }

    public function test_bank_payment_is_allocated_using_the_amount_billed_for_each_service(): void
    {
        $clientId = 'CLI-BILLED-AMOUNT';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Transportes Importe SAC',
        ]);

        $serviceIds = [];
        foreach ([100, 100] as $index => $currentPrice) {
            $serviceIds[] = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => '2026-10-01',
                'fecheVencimiento' => '2026-10-31',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => $currentPrice,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => 'BILLED-' . $index,
                'docReferencia' => 'F001-BILLED',
            ]);
        }

        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clientId,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'docReferencia' => 'F001-BILLED',
            'descripcion' => 'CXC SC-' . implode(',', $serviceIds),
            'montoOriginal' => 160,
            'montoActual' => 160,
            'fechaRegistro' => '2026-10-01 00:00:00',
            'fechaCancelacion' => null,
            'estado' => 'FACTURADO',
        ]);
        foreach (array_combine($serviceIds, [75, 85]) as $serviceId => $billedAmount) {
            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => $billedAmount,
            ]);
        }

        (new CxcService())->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 160,
            'fechaPago' => '2026-10-21',
            'docReferencia' => 'F001-BILLED',
            'descripcion' => 'Pago con precios facturados',
            'servicio_ids' => $serviceIds,
            'renewal_cxc_id' => $cxcId,
        ]);

        $this->assertSame(
            [75.0, 85.0],
            DB::table('bancos')->where('tipoMov', 'I')->orderBy('monto')->pluck('monto')->map(fn($amount): float => (float) $amount)->all()
        );
    }

    /**
     * Prueba 2: Gestión en Grupo con Retención (3%) para 35 vehículos y 5 servicios (40 items).
     * Total bruto = S/ 2,000.00.
     * Retención 3% = S/ 60.00.
     * Neto a pagar = S/ 1,940.00.
     */
    public function test_02_group_gestion_with_retencion_for_35_vehicles_and_5_services(): void
    {
        $clientId = 'CLI-RET-2';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '1', // '1' = Agente de retención 3%
            'flag_integrador' => 0,
            'razonSocial' => 'Logística Nacional SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 35, 5, 50.0);
        $cxcId = $fleet['cxcId'];
        $serviceIds = $fleet['serviceIds'];

        $cxcService = new CxcService();

        $paymentResult = $cxcService->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 1940.00,
            'fechaPago' => '2026-10-25',
            'docReferencia' => 'F001-2002',
            'descripcion' => 'Cobro grupal con retención 3%',
            'withholding_decision' => 'pending',
            'servicio_ids' => $serviceIds,
            'renewal_cxc_id' => $cxcId,
        ]);

        $this->assertSame(60.0, $paymentResult['remaining_debt']);
        $this->assertSame(1940.0, $paymentResult['cash_received']);

        $cxcDb = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame('Pendiente a Retención', $cxcDb->estado);
        $this->assertSame(60.0, (float) $cxcDb->montoActual);
    }

    /**
     * Prueba 3: Gestión Individual aislando 1 vehículo de una flota grupal de 40 items.
     * De 40 servicios (S/ 2,000), se extrae el vehículo 10 (S/ 50).
     * El vehículo 10 se cobra individualmente y el grupo original conserva los 39 restantes (S/ 1,950).
     */
    public function test_03_individual_gestion_isolated_from_fleet_group(): void
    {
        $clientId = 'CLI-ISO-3';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Flota Mixta SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 35, 5, 50.0);
        $sourceCxcId = $fleet['cxcId'];
        $allServiceIds = $fleet['serviceIds'];
        $singleVehicleServiceId = $allServiceIds[9]; // Vehículo 10

        $cxcService = new CxcService();

        // Aislar el servicio seleccionado
        $isolation = $cxcService->isolateSelectedServiceReceivables([$sourceCxcId], [$singleVehicleServiceId]);
        $isolatedCxcId = $isolation['cxc_ids'][0];

        $this->assertNotSame($sourceCxcId, $isolatedCxcId);
        $this->assertSame(50.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $isolatedCxcId)->value('montoOriginal'));
        $this->assertSame(50.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $isolatedCxcId)->value('montoActual'));

        // El grupo original debe haber reducido su monto a S/ 1,950 (39 servicios)
        $this->assertSame(1950.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->value('montoOriginal'));
        $this->assertSame(1950.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->value('montoActual'));

        // Cobrar el vehículo aislado individualmente
        $paymentIsolated = $cxcService->procesarPagoGrupo([$isolatedCxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 50.00,
            'fechaPago' => '2026-10-25',
            'docReferencia' => 'B001-50',
            'descripcion' => 'Pago individual vehículo 10',
            'servicio_ids' => [$singleVehicleServiceId],
            'renewal_cxc_id' => $isolatedCxcId,
        ]);

        $this->assertSame(0.0, $paymentIsolated['remaining_debt']);
        $this->assertSame('3', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $isolatedCxcId)->value('estado'));

        // El grupo original sigue intacto y pendiente con 39 servicios
        $this->assertSame('1', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $sourceCxcId)->value('estado'));
        $this->assertSame(39, DB::table('cuentasporcobrar_serviciocliente')->where('cuentasPorCobrar_idcuentasPorCobrar', $sourceCxcId)->count());
    }

    /**
     * Prueba 4: Gestión Individual con Detracción y generación de Saldo a Favor por pago excedente.
     * Servicio individual de S/ 1,000.
     * Detracción 12% = S/ 120. Neto = S/ 880.
     * Cliente paga S/ 900.
     * Debe generar:
     * - Saldo aplicado: S/ 880
     * - Nuevo saldo a favor: S/ 20.00
     * - Deuda pendiente (detracción): S/ 120.00
     */
    public function test_04_individual_gestion_with_detraccion_and_saldo_a_favor(): void
    {
        $clientId = 'CLI-DET-EXCESS';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0', // detracción estándar 12%
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente Pago Excedente',
        ]);

        $serviceId = DB::table('serviciocliente')->insertGetId([
            'cliente_idcliente' => $clientId,
            'estado' => 'activo',
            'fechaInicio' => '2026-10-01',
            'fecheVencimiento' => '2026-10-31',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'monto' => 1000.0,
            'almacen_idalmacen' => 1,
            'vehiculo_placa' => 'EXC-001',
            'docReferencia' => null,
        ]);

        $cxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clientId,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-' . $serviceId,
            'montoOriginal' => 1000.0,
            'montoActual' => 1000.0,
            'fechaCancelacion' => '2026-10-31',
            'estado' => '1',
        ]);

        DB::table('cuentasporcobrar_serviciocliente')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
            'servicioCliente_idservicioCliente' => $serviceId,
            'montoServicio' => 1000.0,
        ]);

        $cxcService = new CxcService();

        // Cliente paga S/ 900.00 (el neto es 880, se pasa con 20)
        $paymentResult = $cxcService->procesarPagoGrupo([$cxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 900.00,
            'fechaPago' => '2026-10-25',
            'docReferencia' => 'F001-900',
            'descripcion' => 'Pago con detracción y saldo a favor',
            'withholding_decision' => 'pending',
            'servicio_ids' => [$serviceId],
            'renewal_cxc_id' => $cxcId,
        ]);

        $this->assertSame(120.0, $paymentResult['remaining_debt']); // Falta la detracción
        $this->assertSame(20.0, $paymentResult['new_credit']);      // Saldo a favor de 20.00
        $this->assertSame(900.0, $paymentResult['cash_received']);

        $cxcDb = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cxcId)->first();
        $this->assertSame('Pendiente a Detracción', $cxcDb->estado);
        $this->assertSame(120.0, (float) $cxcDb->montoActual);
    }

    /**
     * Prueba 5: El caso consultado por el usuario:
     * - Periodo Octubre: 35 vehículos y 5 servicios (40 items) se pagan y cancelan.
     * - Se agregan 2 vehículos nuevos para ese mismo periodo de Octubre.
     * - En "Servicios por vencer" para Octubre:
     *   * El estado del cliente para Octubre pasa a PENDIENTE (porque hay 2 pendientes).
     *   * Solo se gestionan los 2 vehículos nuevos (los 35+5 ya cancelados no se tocan).
     * - Se cancelan los 2 vehículos nuevos para Octubre.
     * - Para el siguiente periodo (Noviembre):
     *   * En la vista agrupada de Servicios por vencer de Noviembre aparecen ahora los 37 vehículos (35+2) + 5 servicios juntos.
     */
    public function test_05_fleet_add_2_vehicles_to_cancelled_period_and_merge_in_next_period(): void
    {
        $clientId = 'CLI-OCT-NOV-5';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Transportes Octubre Noviembre SAC',
        ]);

        // Paso A: Crear flota de 35 vehículos + 5 servicios para Octubre (40 items)
        $fleet = $this->seedFleet($clientId, 35, 5, 50.0, '2026-10-01', '2026-10-31');
        $octCxcId = $fleet['cxcId'];
        $octServiceIds = $fleet['serviceIds'];

        $cxcService = new CxcService();
        $cxcViewService = new CuentasPorCobrarService();

        // Paso B: Cobrar y cancelar la flota original de Octubre (con withholding_decision = paid)
        $cxcService->procesarPagoGrupo([$octCxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 2000.00,
            'fechaPago' => '2026-10-25',
            'docReferencia' => 'F001-OCT',
            'descripcion' => 'Pago total octubre 40 servicios',
            'withholding_decision' => 'paid',
            'servicio_ids' => $octServiceIds,
            'renewal_cxc_id' => $octCxcId,
        ]);

        // Verificar que el CXC original de octubre está cancelado
        $this->assertSame('3', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $octCxcId)->value('estado'));

        // Paso C: Se agregan 2 vehículos nuevos para el periodo de Octubre
        $newServiceIds = [];
        for ($k = 1; $k <= 2; $k++) {
            $placa = 'NEW-OCT-' . $k;
            DB::table('vehiculo')->insert([
                'placa' => $placa,
                'marca' => 'Mercedes',
                'modelo' => 'Actros',
                'tracto' => 'Si',
                'tipoUnidad_idtable1' => 1,
            ]);

            $newServiceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => '2026-10-01',
                'fecheVencimiento' => '2026-10-31',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => 50.0,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => $placa,
                'docReferencia' => null,
            ]);
            $newServiceIds[] = $newServiceId;

            $newCxcId = $cxcService->crearCobroDesdeServicio([
                'cliente_idcliente' => $clientId,
                'montoServicioBase' => 50.0,
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'descripcion' => 'Cobro servicio ' . $placa . ' SC-' . $newServiceId,
                'vehiculo_placa' => $placa,
                'idservicioCliente' => $newServiceId,
                'fechaInicio' => '2026-10-01',
                'fecheVencimiento' => '2026-10-31',
            ]);

            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $newCxcId,
                'servicioCliente_idservicioCliente' => $newServiceId,
                'montoServicio' => 50.0,
            ]);
        }

        // Paso D: Verificar "Servicios por vencer" para Octubre
        $viewAfterNew = $cxcViewService->prepareServicesIndex();
        $octGroupAfterNew = collect($viewAfterNew['serviciosPorVencerGrouped'] ?? [])
            ->first(fn ($g) => $g['cliente_id'] === $clientId && str_contains($g['fecha_fin'], '10/2026'));

        $this->assertNotNull($octGroupAfterNew);
        // El estado ahora es PENDIENTE (porque hay 2 vehículos con deuda viva)
        $this->assertSame('1', (string) $octGroupAfterNew['cxc_estado']);
        // Los items pendientes de cobro del grupo corresponden exactamente a los 2 vehículos nuevos
        $this->assertCount(2, $octGroupAfterNew['payment_service_ids']);
        $this->assertCount(42, $octGroupAfterNew['all_service_ids']);

        // Paso E: Cobrar y cancelar los 2 vehículos nuevos para Octubre
        $pendingCxcIds = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $clientId)
            ->where('descripcion', 'like', '%NEW-OCT-%')
            ->whereIn('estado', ['1', 'PENDIENTE'])
            ->pluck('idcuentasPorCobrar')
            ->all();

        $this->assertCount(2, $pendingCxcIds);

        foreach ($pendingCxcIds as $idx => $pCxcId) {
            $cxcService->procesarPagoGrupo([$pCxcId], [
                'entidadBancaria_identidadBancaria' => 1,
                'formaPago_idformaPago' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'modo_pago' => 'total',
                'montoPago' => 50.00,
                'fechaPago' => '2026-10-29',
                'docReferencia' => 'F001-NEW-' . ($idx + 1),
                'descripcion' => 'Pago nuevo vehículo ' . ($idx + 1),
                'servicio_ids' => [$newServiceIds[$idx]],
                'renewal_cxc_id' => $pCxcId,
            ]);
        }

        // Verificar que los 2 nuevos quedaron en CANCELADO
        $newCxcsFinal = DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $pendingCxcIds)->pluck('estado')->all();
        $this->assertEquals(['3', '3'], $newCxcsFinal);

        // Paso F: En Noviembre, verificar que ahora se encuentran los 42 servicios (37 vehículos + 5 servicios)
        $viewNov = $cxcViewService->prepareServicesIndex();
        $novGroup = collect($viewNov['serviciosPorVencerGrouped'] ?? [])
            ->first(fn ($g) => $g['cliente_id'] === $clientId && str_contains($g['fecha_fin'], '11/2026'));

        $this->assertNotNull($novGroup);
        // Los 35 anteriores + los 2 nuevos + los 5 servicios adicionales = 42 servicios en total
        $this->assertCount(42, $novGroup['all_service_ids']);
        $this->assertSame(2100.0, (float) $novGroup['monto_servicios']); // 42 * 50 = S/ 2,100
    }

    public function test_cancelled_group_shows_full_period_total_when_some_services_expired(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');

        try {
            $clientId = 'CLI-CANCELLED-TOTAL';
            DB::table('cliente')->insert([
                'idcliente' => $clientId,
                'detraccion' => '0',
                'flag_integrador' => 0,
                'razonSocial' => 'Flota cancelada SAC',
            ]);

            $fleet = $this->seedFleet($clientId, 12, 0, 50.0);
            DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $fleet['serviceIds'])
                ->update(['fecheVencimiento' => '2026-10-20']);
            DB::table('serviciocliente')
                ->where('idservicioCliente', $fleet['serviceIds'][0])
                ->update(['fecheVencimiento' => '2026-10-31']);
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $fleet['cxcId'])
                ->update(['estado' => '3', 'montoActual' => 0]);

            $groups = (new CuentasPorCobrarService())->prepareServicesIndex()['serviciosPorVencerGrouped'];
            $group = collect($groups)->firstWhere('cliente_id', $clientId);

            $this->assertNotNull($group);
            $this->assertSame('Cancelado', $group['estado_pago']);
            $this->assertSame(600.0, (float) $group['monto_total']);
            $this->assertSame(600.0, (float) $group['tipos_servicios'][0]['monto_subtotal']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_new_vehicles_do_not_replace_an_existing_documented_payment_process(): void
    {
        $clientId = 'CLI-PROCESS-PRESERVED';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Transportes Proceso Preservado SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 10, 0, 100.0);
        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $fleet['cxcId'])
            ->update([
                'estado' => 'FACTURADO',
                'docReferencia' => 'F001-DET-10',
                'montoActual' => 1000.0,
            ]);

        $newServiceIds = [];
        $newCxcIds = [];
        foreach (['NEW-PROCESS-1', 'NEW-PROCESS-2'] as $plate) {
            DB::table('vehiculo')->insert([
                'placa' => $plate,
                'marca' => 'Mercedes',
                'modelo' => 'Actros',
                'tracto' => 'Si',
                'tipoUnidad_idtable1' => 1,
            ]);

            $serviceId = DB::table('serviciocliente')->insertGetId([
                'cliente_idcliente' => $clientId,
                'estado' => 'activo',
                'fechaInicio' => '2026-10-01',
                'fecheVencimiento' => '2026-10-31',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'monto' => 50.0,
                'almacen_idalmacen' => 1,
                'vehiculo_placa' => $plate,
                'docReferencia' => null,
            ]);
            $newServiceIds[] = $serviceId;

            $cxcId = DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => $clientId,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'docReferencia' => null,
                'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-' . $serviceId,
                'montoOriginal' => 50.0,
                'montoActual' => 50.0,
                'fechaCancelacion' => '2026-10-31',
                'estado' => '1',
            ]);
            $newCxcIds[] = $cxcId;
            DB::table('cuentasporcobrar_serviciocliente')->insert([
                'cuentasPorCobrar_idcuentasPorCobrar' => $cxcId,
                'servicioCliente_idservicioCliente' => $serviceId,
                'montoServicio' => 50.0,
            ]);
        }

        $cxcViewService = new CuentasPorCobrarService();
        $group = collect($cxcViewService->prepareServicesIndex()['serviciosPorVencerGrouped'])
            ->firstWhere('cliente_id', $clientId);

        $this->assertNotNull($group);
        $this->assertSame('Facturado', $group['estado_pago']);
        $this->assertSame(1000.0, (float) $group['monto_total']);
        $this->assertSame([$fleet['cxcId']], $group['cxc_ids']);
        $this->assertEqualsCanonicalizing($fleet['serviceIds'], $group['payment_service_ids']);
        $this->assertSame([$fleet['cxcId']], $group['tipos_servicios'][0]['cxc_ids']);
        $this->assertEqualsCanonicalizing($fleet['serviceIds'], $group['tipos_servicios'][0]['service_ids']);

        foreach ([
            'Pendiente Pago parcial',
            'Pendiente a credito',
            'Pendiente Autodetracción',
            'Pendiente Autoretención',
            'Pendiente a Detracción',
            'Pendiente a Retención',
        ] as $inProgressState) {
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $fleet['cxcId'])
                ->update([
                    'estado' => $inProgressState,
                    'montoActual' => 120.0,
                ]);
            $group = collect($cxcViewService->prepareServicesIndex()['serviciosPorVencerGrouped'])
                ->firstWhere('cliente_id', $clientId);

            $this->assertNotNull($group);
            $this->assertSame($inProgressState, $group['cxc_estado']);
            $this->assertSame(120.0, (float) $group['monto_total']);
            $this->assertSame([$fleet['cxcId']], $group['cxc_ids']);
            $this->assertEqualsCanonicalizing($fleet['serviceIds'], $group['payment_service_ids']);
        }

        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $fleet['cxcId'])
            ->update([
                'estado' => 'Pendiente a Detracción',
                'montoActual' => 120.0,
            ]);
        $group = collect($cxcViewService->prepareServicesIndex()['serviciosPorVencerGrouped'])
            ->firstWhere('cliente_id', $clientId);

        $this->assertNotNull($group);
        $this->assertSame('Pendiente a Detracción', $group['estado_pago']);
        $this->assertSame(120.0, (float) $group['monto_total']);
        $this->assertSame([$fleet['cxcId']], $group['cxc_ids']);
        $this->assertEqualsCanonicalizing($fleet['serviceIds'], $group['payment_service_ids']);
        $this->assertSame([$fleet['cxcId']], $group['tipos_servicios'][0]['cxc_ids']);
        $this->assertEqualsCanonicalizing($fleet['serviceIds'], $group['tipos_servicios'][0]['service_ids']);

        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $fleet['cxcId'])->update([
            'estado' => '3',
            'montoActual' => 0,
        ]);
        $group = collect($cxcViewService->prepareServicesIndex()['serviciosPorVencerGrouped'])
            ->firstWhere('cliente_id', $clientId);

        $this->assertNotNull($group);
        $this->assertSame('Pendiente', $group['estado_pago']);
        $this->assertSame(100.0, (float) $group['monto_total']);
        $this->assertEqualsCanonicalizing($newCxcIds, $group['cxc_ids']);
        $this->assertEqualsCanonicalizing($newServiceIds, $group['payment_service_ids']);

        DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $newCxcIds)->update([
            'estado' => '3',
            'montoActual' => 0,
        ]);
        $group = collect($cxcViewService->prepareServicesIndex()['serviciosPorVencerGrouped'])
            ->firstWhere('cliente_id', $clientId);

        $this->assertNotNull($group);
        $this->assertSame('Cancelado', $group['estado_pago']);
        $this->assertSame(1100.0, (float) $group['monto_total']);
        $this->assertSame([], $group['cxc_ids']);
        $this->assertSame([], $group['payment_service_ids']);
    }

    #[DataProvider('documentedProcessStates')]
    public function test_50_vehicles_and_10_services_keep_their_process_when_20_new_vehicles_are_added(
        string $storedState,
        string $expectedLabel,
        float $expectedAmount
    ): void {
        Carbon::setTestNow('2026-10-24 12:00:00');

        try {
            $clientId = 'CLI-FLEET-' . substr(sha1($storedState), 0, 8);
            DB::table('cliente')->insert([
                'idcliente' => $clientId,
                'detraccion' => '0',
                'flag_integrador' => 0,
                'razonSocial' => 'Flota de prueba estado ' . $storedState,
            ]);

            $originalFleet = $this->seedFleet($clientId, 50, 10, 50.0);
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $originalFleet['cxcId'])
                ->update([
                    'estado' => $storedState,
                    'docReferencia' => 'F001-' . substr(sha1($storedState), 0, 8),
                    'montoActual' => $expectedAmount,
                ]);

            $additionalFleet = $this->seedAdditionalVehicleCharges($clientId, 20);
            $cxcViewService = new CuentasPorCobrarService();
            $getOctoberGroup = function () use ($cxcViewService, $clientId): ?array {
                return collect($cxcViewService->prepareServicesIndex()['serviciosPorVencerGrouped'])
                    ->first(fn(array $candidate): bool => $candidate['cliente_id'] === $clientId
                        && strtolower((string) $candidate['mes']) === 'octubre');
            };
            $group = $getOctoberGroup();

            $this->assertNotNull($group);
            $this->assertSame($storedState, $group['cxc_estado']);
            $this->assertSame($expectedLabel, $group['estado_pago']);
            $this->assertSame($expectedAmount, (float) $group['monto_total']);
            $this->assertSame([$originalFleet['cxcId']], $group['cxc_ids']);
            $this->assertEqualsCanonicalizing($originalFleet['serviceIds'], $group['payment_service_ids']);
            $this->assertCount(80, $group['all_service_ids']);

            $vehicles = collect($group['tipos_servicios'])
                ->flatMap(fn(array $type): array => $type['vehiculos'])
                ->keyBy('idservicioCliente');
            $this->assertEqualsCanonicalizing($additionalFleet['serviceIds'], $vehicles
                ->only($additionalFleet['serviceIds'])
                ->keys()
                ->all());
            $this->assertSame(
                1000.0,
                (float) $vehicles->only($additionalFleet['serviceIds'])->sum('monto')
            );

            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $originalFleet['cxcId'])
                ->update(['estado' => '3', 'montoActual' => 0]);

            $group = $getOctoberGroup();
            $this->assertNotNull($group);
            $this->assertSame('Pendiente', $group['estado_pago']);
            $this->assertSame(1000.0, (float) $group['monto_total']);
            $this->assertEqualsCanonicalizing($additionalFleet['cxcIds'], $group['cxc_ids']);
            $this->assertEqualsCanonicalizing($additionalFleet['serviceIds'], $group['payment_service_ids']);

            $paymentResult = (new CxcService())->procesarPagoGrupo($additionalFleet['cxcIds'], [
                'entidadBancaria_identidadBancaria' => 1,
                'formaPago_idformaPago' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'modo_pago' => 'total',
                'montoPago' => 880.0,
                'fechaPago' => '2026-10-24',
                'docReferencia' => 'F002-NEW-FLEET',
                'descripcion' => 'Pago de los 20 vehículos nuevos',
                'withholding_decision' => 'pending',
                'servicio_ids' => $additionalFleet['serviceIds'],
                'renewal_cxc_id' => $additionalFleet['cxcIds'][0],
                'renewal_cxc_ids' => $additionalFleet['cxcIds'],
            ]);

            $this->assertSame(120.0, (float) $paymentResult['remaining_debt']);
            $group = $getOctoberGroup();
            $this->assertNotNull($group);
            $this->assertSame('Pendiente a Detracción', $group['estado_pago']);
            $this->assertSame(120.0, (float) $group['monto_total']);
            $this->assertEqualsCanonicalizing($additionalFleet['cxcIds'], $group['cxc_ids']);
            $this->assertEqualsCanonicalizing($additionalFleet['serviceIds'], $group['payment_service_ids']);

            (new CxcService())->registrarDetraccionesPendientes(
                $additionalFleet['cxcIds'],
                null,
                $additionalFleet['serviceIds'],
                'Depósito de detracción del grupo nuevo',
                '2026-10-24'
            );

            $group = $getOctoberGroup();
            $this->assertNotNull($group);
            $this->assertSame('Cancelado', $group['estado_pago']);
            $this->assertSame(4000.0, (float) $group['monto_total']);
            $this->assertSame([], $group['cxc_ids']);
            $this->assertSame([], $group['payment_service_ids']);

            $individualServiceId = $additionalFleet['serviceIds'][0];
            (new CxcService())->revertirPagoCancelado(
                $additionalFleet['cxcIds'][0],
                'tester',
                'Anulación individual de prueba',
                $individualServiceId
            );

            $group = $getOctoberGroup();
            $this->assertNotNull($group);
            $this->assertSame('Pendiente', $group['estado_pago']);
            $this->assertSame(50.0, (float) $group['monto_total']);
            $this->assertSame([$additionalFleet['cxcIds'][0]], $group['cxc_ids']);
            $this->assertSame([$individualServiceId], $group['payment_service_ids']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_payment_isolation_refreshes_unpaid_receivable_from_current_service_prices(): void
    {
        $clientId = 'CLI-CURRENT-SERVICE-PRICES';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Flota precio actualizado SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 4, 0, 47.0);
        DB::table('serviciocliente')
            ->where('idservicioCliente', $fleet['serviceIds'][0])
            ->update(['monto' => 9.40]);

        $result = (new CxcService())->isolateSelectedServiceReceivables(
            [$fleet['cxcId']],
            $fleet['serviceIds']
        );

        $charge = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $result['cxc_ids'][0])
            ->first();
        $updatedLinkAmount = DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $fleet['cxcId'])
            ->where('servicioCliente_idservicioCliente', $fleet['serviceIds'][0])
            ->value('montoServicio');

        $this->assertSame(150.4, (float) $charge->montoOriginal);
        $this->assertSame(150.4, (float) $charge->montoActual);
        $this->assertSame(9.4, (float) $updatedLinkAmount);

        $paymentData = [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 150.39,
            'monto_factura' => 150.4,
            'fechaPago' => '2026-10-21',
            'docReferencia' => 'F001-UPDATED-PRICE',
            'servicio_ids' => $fleet['serviceIds'],
            'renewal_cxc_id' => $fleet['cxcId'],
        ];
        try {
            (new CxcService())->procesarPagoGrupo($result['cxc_ids'], $paymentData);
            $this->fail('The payment must not accept less cash than the current receivable balance.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'El efectivo ingresado es menor al saldo que falta pagar. Verifica el saldo de las cuentas seleccionadas y los montos de efectivo, créditos o retenciones aplicados.',
                $exception->getMessage()
            );
        }

        $paymentData['montoPago'] = 150.4;
        $payment = (new CxcService())->procesarPagoGrupo($result['cxc_ids'], $paymentData);
        $this->assertSame(150.4, $payment['cash_applied']);
        $this->assertSame(0.0, $payment['remaining_debt']);
    }

    public function test_payment_isolation_preserves_a_period_specific_cxc_price(): void
    {
        $clientId = 'CLI-PERIOD-SPECIFIC-PRICE';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Flota precio por periodo SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 1, 0, 40.0);
        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $fleet['cxcId'])
            ->update([
                'montoOriginal' => 20,
                'montoActual' => 20,
                'mod_monto' => 'Si mantener precio pasado',
            ]);

        $result = (new CxcService())->isolateSelectedServiceReceivables(
            [$fleet['cxcId']],
            $fleet['serviceIds']
        );
        $charge = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $result['cxc_ids'][0])
            ->first();

        $this->assertSame(20.0, (float) $charge->montoOriginal);
        $this->assertSame(20.0, (float) $charge->montoActual);
        $this->assertSame(40.0, (float) DB::table('serviciocliente')
            ->where('idservicioCliente', $fleet['serviceIds'][0])
            ->value('monto'));

        $payment = (new CxcService())->procesarPagoGrupo($result['cxc_ids'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 20.0,
            'fechaPago' => '2026-10-21',
            'docReferencia' => 'F001-PERIOD-PRICE',
            'servicio_ids' => $fleet['serviceIds'],
            'renewal_cxc_id' => $fleet['cxcId'],
        ]);

        $this->assertSame(20.0, $payment['cash_applied']);
        $this->assertSame(0.0, $payment['remaining_debt']);
    }

    public function test_group_total_reflects_single_period_price_override_without_changing_service_base_total(): void
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        $clientId = 'CLI-PERIOD-PRICE-OVERRIDE';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente con precio por período',
        ]);
        $fleet = $this->seedAdditionalVehicleCharges(
            $clientId,
            4,
            25.0,
            '2026-09-07',
            '2026-10-07'
        );
        DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $fleet['cxcIds'])->update([
            'estado' => '4',
        ]);
        $editedCxcId = $fleet['cxcIds'][0];
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $editedCxcId)->update([
            'montoOriginal' => 50.0,
            'montoActual' => 50.0,
            'mod_monto' => 'Si mantener precio pasado',
        ]);
        DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $editedCxcId)
            ->delete();

        $group = collect((new CuentasPorCobrarService())->prepareServicesIndex()['serviciosPorVencerGrouped'])
            ->firstWhere('cliente_id', $clientId);

        $this->assertNotNull($group);
        $this->assertSame(100.0, (float) $group['monto_servicios']);
        $this->assertSame(125.0, (float) $group['monto_total']);
        $this->assertSame(125.0, (float) $group['tipos_servicios'][0]['monto_subtotal']);
    }

    public function test_payment_isolation_accepts_the_monthly_proration_amount(): void
    {
        $clientId = 'CLI-MONTHLY-PRORATION-PRICE';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Flota prorrateada SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 1, 0, 40.0);
        $serviceId = $fleet['serviceIds'][0];
        $parentCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clientId,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CXC 07/09/2026 a 07/10/2026 SC-' . $serviceId,
            'montoOriginal' => 40,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-10-07',
            'estado' => '3',
        ]);
        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $fleet['cxcId'])
            ->update([
                'descripcion' => 'REN-CXC-' . $parentCxcId . ' P 07/10/2026 a 30/11/2026 SC-' . $serviceId,
                'montoOriginal' => 32,
                'montoActual' => 32,
                'fechaCancelacion' => '2026-11-30',
            ]);

        $result = (new CxcService())->isolateSelectedServiceReceivables(
            [$fleet['cxcId']],
            $fleet['serviceIds']
        );
        $charge = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $result['cxc_ids'][0])
            ->first();

        $this->assertSame(32.0, (float) $charge->montoOriginal);
        $this->assertSame(32.0, (float) $charge->montoActual);
        $this->assertSame(CxcService::MONTHLY_PRORATION_PRICE_MARKER, $charge->mod_monto);

        $payment = (new CxcService())->procesarPagoGrupo($result['cxc_ids'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 32.0,
            'monto_factura' => 32.0,
            'fechaPago' => '2026-10-21',
            'docReferencia' => 'F001-MONTHLY-PRORATION',
            'servicio_ids' => $fleet['serviceIds'],
            'renewal_cxc_id' => $fleet['cxcId'],
        ]);

        $this->assertSame(32.0, $payment['cash_applied']);
        $this->assertSame(0.0, $payment['remaining_debt']);
    }

    public function test_payment_isolation_accepts_zero_value_service_receivables(): void
    {
        $clientId = 'CLI-ZERO-VALUE-SERVICES';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Servicios sin costo SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 2, 0, 0.0);

        $result = (new CxcService())->isolateSelectedServiceReceivables(
            [$fleet['cxcId']],
            [$fleet['serviceIds'][0]]
        );

        $this->assertCount(1, $result['cxc_ids']);
        $this->assertNotSame($fleet['cxcId'], $result['cxc_ids'][0]);
        $isolatedCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $result['cxc_ids'][0])
            ->first();
        $this->assertSame(0.0, (float) $isolatedCxc->montoOriginal);
        $this->assertSame(0.0, (float) $isolatedCxc->montoActual);
        $remainingCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $fleet['cxcId'])
            ->first();
        $this->assertSame(0.0, (float) $remainingCxc->montoOriginal);
        $this->assertSame(0.0, (float) $remainingCxc->montoActual);
    }

    public function test_registering_a_new_cxc_document_updates_selected_services_and_records_history(): void
    {
        $clientId = 'CLI-DOCUMENT-UPDATE';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente con documento actualizado SAC',
        ]);
        $fleet = $this->seedFleet($clientId, 2, 0, 100.0);
        [$firstServiceId, $secondServiceId] = $fleet['serviceIds'];
        $unrelatedServiceId = DB::table('serviciocliente')->insertGetId([
            'cliente_idcliente' => $clientId,
            'estado' => 'activo',
            'fechaInicio' => '2026-10-01',
            'fecheVencimiento' => '2026-10-31',
            'moneda_idmoneda' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'monto' => 100,
            'almacen_idalmacen' => 1,
            'vehiculo_placa' => 'OTHER-001',
            'docReferencia' => 'F00-72015',
        ]);
        DB::table('serviciocliente')
            ->whereIn('idservicioCliente', [$firstServiceId, $secondServiceId])
            ->update(['docReferencia' => 'F00-72015']);

        (new CxcService())->registrarFactura(
            $fleet['cxcId'],
            '',
            'F00270162',
            [$firstServiceId, $secondServiceId],
            'tester'
        );
        (new CxcService())->registrarFactura(
            $fleet['cxcId'],
            '',
            'F00270162',
            [$firstServiceId, $secondServiceId],
            'tester'
        );

        $this->assertSame(
            ['F00270162', 'F00270162'],
            DB::table('serviciocliente')
                ->whereIn('idservicioCliente', [$firstServiceId, $secondServiceId])
                ->orderBy('idservicioCliente')
                ->pluck('docReferencia')
                ->all()
        );
        $this->assertSame(
            'F00-72015',
            DB::table('serviciocliente')->where('idservicioCliente', $unrelatedServiceId)->value('docReferencia')
        );
        foreach ([$firstServiceId, $secondServiceId] as $index => $serviceId) {
            $deviceId = 'DOC-DEVICE-' . $index;
            $phoneNumber = 'DOC-PHONE-' . $index;
            DB::table('detalle_serviciodispositivo')->insert([
                'servicioCliente_idservicioCliente' => $serviceId,
                'dispositivoCliente_iddispositivoCliente' => $deviceId,
            ]);
            DB::table('detnumerosdispositivo')->insert([
                'dispositivoCliente_iddispositivoCliente' => $deviceId,
                'numeroTelefonico_numeroTelefonico' => $phoneNumber,
            ]);
        }

        (new CxcService())->registrarFactura(
            $fleet['cxcId'],
            '',
            'F00270163',
            [$firstServiceId, $secondServiceId],
            'tester'
        );

        $historyRows = DB::table('historial_servicio')
            ->whereIn('servicioCliente_idservicioCliente', [$firstServiceId, $secondServiceId])
            ->where('doc_referencia', 'F00270163')
            ->orderBy('servicioCliente_idservicioCliente')
            ->get();
        $this->assertCount(2, $historyRows);
        $this->assertSame('tester', $historyRows->first()->serv_usuario);
        $this->assertSame('F00270163', $historyRows->first()->doc_referencia);
        $this->assertSame('DOC-DEVICE-0', $historyRows->first()->dispositivo);
        $this->assertSame('DOC-PHONE-0', $historyRows->first()->numerotelefono);
        $this->assertStringContainsString('F00270162 a F00270163', $historyRows->first()->descripcion);
    }

    public function test_group_payment_registers_zero_value_cxc_and_cancels_positive_balance(): void
    {
        $clientId = 'CLI-ZERO-AND-POSITIVE-CXC';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente con servicio sin costo SAC',
        ]);

        $fleet = $this->seedFleet($clientId, 2, 0, 460.0);
        [$zeroServiceId, $paidServiceId] = $fleet['serviceIds'];
        $zeroCxcId = $fleet['cxcId'];
        DB::table('serviciocliente')->where('idservicioCliente', $zeroServiceId)->update(['monto' => 0]);
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $zeroCxcId)->update([
            'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-' . $zeroServiceId,
            'montoOriginal' => 0,
            'montoActual' => 0,
        ]);
        DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $zeroCxcId)
            ->where('servicioCliente_idservicioCliente', $zeroServiceId)
            ->update(['montoServicio' => 0]);
        DB::table('cuentasporcobrar_serviciocliente')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $zeroCxcId)
            ->where('servicioCliente_idservicioCliente', $paidServiceId)
            ->delete();
        $paidCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clientId,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'CXC 01/10/2026 a 31/10/2026 SC-' . $paidServiceId,
            'montoOriginal' => 460,
            'montoActual' => 460,
            'fechaCancelacion' => '2026-10-31',
            'estado' => '1',
        ]);
        DB::table('cuentasporcobrar_serviciocliente')->insert([
            'cuentasPorCobrar_idcuentasPorCobrar' => $paidCxcId,
            'servicioCliente_idservicioCliente' => $paidServiceId,
            'montoServicio' => 460,
        ]);

        $cxcService = new CxcService();
        $isolated = $cxcService->isolateSelectedServiceReceivables(
            [$zeroCxcId, $paidCxcId],
            [$zeroServiceId, $paidServiceId]
        );
        $cxcService->registrarFactura($zeroCxcId, '', 'F001-ZERO-460', [$zeroServiceId]);
        $payment = $cxcService->procesarPagoGrupo($isolated['cxc_ids'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 460,
            'fechaPago' => '2026-10-22',
            'docReferencia' => 'F001-ZERO-460',
            'descripcion' => 'Pago grupo con servicio sin costo',
            'servicio_ids' => [$zeroServiceId, $paidServiceId],
            'renewal_cxc_id' => $paidCxcId,
            'renewal_cxc_ids' => $isolated['cxc_ids'],
        ]);

        $zeroCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $zeroCxcId)->first();
        $this->assertSame('3', (string) $zeroCxc->estado);
        $zeroPaymentDetail = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $zeroCxcId)
            ->where('estado', '1')
            ->first();
        $this->assertNotNull($zeroPaymentDetail);
        $this->assertSame(0.0, (float) DB::table('bancos')
            ->where('idbancos', $zeroPaymentDetail->bancos_idbancos)
            ->value('monto'));
        $paidCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $paidCxcId)->first();
        $this->assertSame('3', (string) $paidCxc->estado);
        $this->assertSame(460.0, (float) DB::table('bancos')->where('tipoMov', 'I')->sum('monto'));
        $this->assertSame(0.0, (float) $payment['remaining_debt']);
    }

    public function test_full_balance_credit_application_is_recorded_and_cancels_cxc_with_zero_balance(): void
    {
        $clientId = 'CLI-CREDIT-COVERS-FULL-CXC';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente con saldo a favor SAC',
        ]);
        $fleet = $this->seedFleet($clientId, 1, 0, 50.0);

        $originCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clientId,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'Origen de saldo a favor',
            'montoOriginal' => 50,
            'montoActual' => 0,
            'fechaCancelacion' => '2026-09-30',
            'estado' => '3',
        ]);
        $creditMovementId = DB::table('bancos')->insertGetId([
            'entidadBancaria_identidadBancaria' => 1,
            'fechaRegistro' => '2026-09-30 12:00:00',
            'informacion' => 'Saldo a favor por sobrepago',
            'monto' => 50,
            'tipoMov' => 'A',
        ]);
        DB::table('detallecxc')->insert([
            'formaPago_idformaPago' => 1,
            'cuentasPorCobrar_idcuentasPorCobrar' => $originCxcId,
            'moneda_idmoneda' => 1,
            'descripcion' => 'Saldo a favor generado',
            'fechaPagoProgramada' => '2026-09-30',
            'fechaPago' => '2026-09-30',
            'bancos_idbancos' => $creditMovementId,
            'estado' => 'A',
        ]);

        $cxcService = new CxcService();
        $result = $cxcService->isolateSelectedServiceReceivables(
            [$fleet['cxcId']],
            $fleet['serviceIds']
        );
        $payment = $cxcService->procesarPagoGrupo($result['cxc_ids'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 0,
            'usar_saldo_favor' => true,
            'monto_saldo_favor' => 50,
            'fechaPago' => '2026-10-22',
            'docReferencia' => 'F001-CREDIT-50',
            'descripcion' => 'Pago cubierto con saldo a favor',
            'servicio_ids' => $fleet['serviceIds'],
            'renewal_cxc_id' => $fleet['cxcId'],
        ]);

        $targetCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $fleet['cxcId'])
            ->first();
        $this->assertSame('3', (string) $targetCxc->estado);
        $this->assertSame(0.0, (float) $targetCxc->montoActual);
        $this->assertSame(50.0, (float) $payment['credit_applied']);
        $this->assertSame(0.0, (float) $payment['remaining_debt']);
        $this->assertSame(0.0, $cxcService->saldoFavorDisponible($clientId, 1));
        $creditApplication = DB::table('detallecxc as dc')
            ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
            ->where('dc.cuentasPorCobrar_idcuentasPorCobrar', $fleet['cxcId'])
            ->where('b.tipoMov', 'C')
            ->first(['dc.descripcion', 'b.monto']);
        $this->assertNotNull($creditApplication);
        $this->assertSame('Aplicación saldo a favor', $creditApplication->descripcion);
        $this->assertSame(50.0, (float) $creditApplication->monto);
    }

    public function test_grouped_credit_plan_creates_future_installments_for_each_vehicle_receivable(): void
    {
        $clientId = 'CLI-CREDIT-GROUP';
        DB::table('cliente')->insert([
            'idcliente' => $clientId,
            'detraccion' => '0',
            'flag_integrador' => 0,
            'razonSocial' => 'Cliente crédito agrupado',
        ]);
        DB::table('formapago')->insert([
            'idformaPago' => 2,
            'detalle' => 'Crédito',
            'tiempo' => 30,
        ]);
        $fleet = $this->seedAdditionalVehicleCharges($clientId, 2, 100);

        (new CxcService())->procesarPagoGrupo($fleet['cxcIds'], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 2,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 60,
            'monto_cancelado' => 60,
            'monto_factura' => 200,
            'canCuotas' => 3,
            'cuotas_montos' => [60, 70, 70],
            'cuotas_fechas' => ['2026-08-20', '2026-09-20', '2026-10-20'],
            'fechaPago' => '2026-08-20',
            'docReferencia' => 'F001-200',
            'descripcion' => 'Pago agrupado en cuotas',
            'servicio_ids' => $fleet['serviceIds'],
        ]);

        $parents = DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', $fleet['cxcIds'])
            ->orderBy('idcuentasPorCobrar')
            ->get();
        $this->assertSame(
            [70.0, 70.0],
            $parents->pluck('montoActual')->map(fn ($amount): float => (float) $amount)->all()
        );
        $firstPayments = DB::table('detallecxc as dc')
            ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
            ->whereIn('dc.cuentasPorCobrar_idcuentasPorCobrar', $fleet['cxcIds'])
            ->where('dc.nCuota', 1)
            ->orderBy('dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->pluck('b.monto')
            ->map(fn ($amount): float => (float) $amount)
            ->all();
        $this->assertSame([30.0, 30.0], $firstPayments);
        $scheduledQuotas = DB::table('detallecxc')
            ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $fleet['cxcIds'])
            ->where('estado', '0')
            ->orderBy('cuentasPorCobrar_idcuentasPorCobrar')
            ->orderBy('nCuota')
            ->get(['cuentasPorCobrar_idcuentasPorCobrar', 'nCuota'])
            ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
            ->map(fn ($details): array => $details->pluck('nCuota')->map(fn ($number): int => (int) $number)->all())
            ->all();
        $this->assertSame([
            $fleet['cxcIds'][0] => [2],
            $fleet['cxcIds'][1] => [3],
        ], $scheduledQuotas);

        $firstParentInstallments = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'CCXC:' . $fleet['cxcIds'][0] . ':%')
            ->orderBy('fechaCancelacion')
            ->get(['montoActual', 'fechaCancelacion']);
        $secondParentInstallments = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'CCXC:' . $fleet['cxcIds'][1] . ':%')
            ->orderBy('fechaCancelacion')
            ->get(['montoActual', 'fechaCancelacion']);

        $this->assertSame(
            [70.0],
            $firstParentInstallments->pluck('montoActual')->map(fn ($amount): float => (float) $amount)->all()
        );
        $this->assertSame(['2026-09-20'], $firstParentInstallments->pluck('fechaCancelacion')->all());
        $this->assertSame(
            [70.0],
            $secondParentInstallments->pluck('montoActual')->map(fn ($amount): float => (float) $amount)->all()
        );
        $this->assertSame(['2026-10-20'], $secondParentInstallments->pluck('fechaCancelacion')->all());

        $creditInstallments = DB::table('cuentasporcobrar')
            ->where(function ($query) use ($fleet): void {
                foreach ($fleet['cxcIds'] as $parentId) {
                    $query->orWhere('descripcion', 'like', 'CCXC:' . $parentId . ':%');
                }
            })
            ->orderBy('idcuentasPorCobrar')
            ->get(['idcuentasPorCobrar', 'montoActual']);
        $this->assertCount(2, $creditInstallments);

        (new CxcService())->procesarPagoGrupo(
            $creditInstallments->pluck('idcuentasPorCobrar')->map(fn ($id): int => (int) $id)->all(),
            [
                'entidadBancaria_identidadBancaria' => 1,
                'formaPago_idformaPago' => 1,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'modo_pago' => 'total',
                'montoPago' => (float) $creditInstallments->sum('montoActual'),
                'monto_factura' => (float) $creditInstallments->sum('montoActual'),
                'fechaPago' => '2026-08-20',
                'docReferencia' => 'REC-CUOTAS',
                'descripcion' => 'Pago anticipado de cuotas',
            ]
        );

        $this->assertSame(
            ['3', '3'],
            DB::table('cuentasporcobrar')
                ->whereIn('idcuentasPorCobrar', $creditInstallments->pluck('idcuentasPorCobrar'))
                ->orderBy('idcuentasPorCobrar')
                ->pluck('estado')
                ->all()
        );
        $paidParents = DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', $fleet['cxcIds'])
            ->orderBy('idcuentasPorCobrar')
            ->get(['estado', 'montoActual']);
        $this->assertSame(['CANCELADO', 'CANCELADO'], $paidParents->pluck('estado')->all());
        $this->assertSame([0.0, 0.0], $paidParents->pluck('montoActual')->map(fn ($amount): float => (float) $amount)->all());
    }
}
