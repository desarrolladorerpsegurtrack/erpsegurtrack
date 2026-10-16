<?php

namespace Tests\Unit;

use App\Http\Controllers\CuentasPorCobrarController;
use App\Http\Controllers\BancosController;
use App\Services\CotizacionService;
use App\Services\BancosService;
use App\Services\CxcService;
use App\Services\CuentasPorCobrarService;
use Illuminate\Support\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CxcPaymentBreakdownTest extends TestCase
{
    #[DataProvider('deductionCases')]
    public function test_deduction_uses_gross_threshold_and_client_type(
        float $gross,
        bool $isRetention,
        float $expectedDeduction,
        float $expectedCashDue
    ): void {
        $result = CxcService::calculatePaymentBreakdown($gross, $isRetention, 0, false, 0);

        $this->assertSame($expectedDeduction, $result['deduction']);
        $this->assertSame($expectedCashDue, $result['cash_due']);
    }

    public function test_payment_datetime_preserves_selected_date_and_uses_current_time(): void
    {
        Carbon::setTestNow('2026-10-14 09:57:00');
        $service = new CxcService();
        $method = new \ReflectionMethod($service, 'paymentDateTime');

        try {
            $this->assertSame(
                '2026-10-10 09:57:00',
                $method->invoke($service, '2026-10-10 00:00:00')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function deductionCases(): array
    {
        return [
            'exact threshold has no deduction' => [700.00, false, 0.00, 700.00],
            'standard detraccion rounds down to whole unit' => [702.50, false, 84.00, 618.50],
            'standard detraccion rounds up at half' => [704.17, false, 85.00, 619.17],
            'retention rounds down to whole unit' => [710.00, true, 21.00, 689.00],
            'retention rounds up at half' => [716.67, true, 22.00, 694.67],
            'standard detraccion' => [1284.00, false, 154.00, 1130.00],
            'retention client' => [1284.00, true, 39.00, 1245.00],
        ];
    }

    public function test_quotation_status_filters_match_existing_cxc_states_without_rewriting_them(): void
    {
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('estado');
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('descripcion');
            $table->string('estado');
            $table->decimal('montoOriginal', 20, 2);
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->decimal('monto', 20, 2);
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos');
            $table->string('descripcion');
            $table->string('estado');
        });

        DB::table('cotizacion')->insert([
            ['nroCotizacion' => 'Q-NEW', 'estado' => '1'],
            ['nroCotizacion' => 'Q-CREDIT', 'estado' => '1'],
            ['nroCotizacion' => 'Q-DET', 'estado' => '1'],
            ['nroCotizacion' => 'Q-AUTO', 'estado' => '1'],
            ['nroCotizacion' => 'Q-RET', 'estado' => '1'],
            ['nroCotizacion' => 'Q-APPROVED', 'estado' => '2'],
            ['nroCotizacion' => 'Q-LEGACY', 'estado' => '2'],
        ]);
        DB::table('cuentasporcobrar')->insert([
            ['descripcion' => 'COTIZACION:Q-NEW', 'estado' => 'NUEVO', 'montoOriginal' => 500],
            ['descripcion' => 'COTIZACION:Q-CREDIT', 'estado' => 'Pendiente a credito: 1/2', 'montoOriginal' => 500],
            ['descripcion' => 'COTIZACION:Q-DET', 'estado' => 'Pendiente a Detracción', 'montoOriginal' => 1000],
            ['descripcion' => 'COTIZACION:Q-AUTO', 'estado' => 'Pendiente Autodetracción', 'montoOriginal' => 1000],
            ['descripcion' => 'COTIZACION:Q-RET', 'estado' => 'Pendiente a Retención', 'montoOriginal' => 500],
            ['descripcion' => 'COTIZACION:Q-APPROVED', 'estado' => '3', 'montoOriginal' => 500],
            ['descripcion' => 'Cuenta previa Q-LEGACY', 'estado' => 'Pendiente a credito', 'montoOriginal' => 500],
        ]);
        DB::table('bancos')->insert([
            ['idbancos' => 1, 'monto' => 880],
            ['idbancos' => 2, 'monto' => 1000],
        ]);
        DB::table('detallecxc')->insert([
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => 3,
                'bancos_idbancos' => 1,
                'descripcion' => 'Detracción pendiente: 120.00',
                'estado' => '0',
            ],
            [
                'cuentasPorCobrar_idcuentasPorCobrar' => 4,
                'bancos_idbancos' => 2,
                'descripcion' => 'Detracción pendiente: 120.00',
                'estado' => '0',
            ],
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );
        $applyFilters = new \ReflectionMethod($controller, 'applyQuotationFilters');
        $applyFilters->setAccessible(true);
        $filteredQuotes = static function (string $status) use ($controller, $applyFilters): array {
            $query = DB::table('cotizacion as c');
            $applyFilters->invoke($controller, $query, Request::create('/?quote_status=' . $status), 'pending');

            $quotes = $query->pluck('c.nroCotizacion')->all();
            sort($quotes);

            return $quotes;
        };

        $this->assertSame(['Q-AUTO', 'Q-CREDIT', 'Q-DET', 'Q-LEGACY', 'Q-NEW', 'Q-RET'], $filteredQuotes('pending'));
        $this->assertSame(['Q-CREDIT', 'Q-LEGACY'], $filteredQuotes('pending_credit'));
        $this->assertSame(['Q-AUTO'], $filteredQuotes('pending_autodetraccion'));
        $this->assertSame(['Q-DET'], $filteredQuotes('pending_detraccion'));
        $this->assertSame(['Q-RET'], $filteredQuotes('pending_retencion'));
        $this->assertSame(['Q-APPROVED'], $filteredQuotes('approved'));
        $this->assertSame(['Q-APPROVED', 'Q-AUTO', 'Q-CREDIT', 'Q-DET', 'Q-LEGACY', 'Q-NEW', 'Q-RET'], $filteredQuotes('all'));
        $this->assertSame('Pendiente a Detracción', DB::table('cuentasporcobrar')
            ->where('descripcion', 'COTIZACION:Q-DET')
            ->value('estado'));
    }

    public function test_approved_sp_quote_creates_one_new_receivable_and_only_once(): void
    {
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('estado');
            $table->string('cliente_idcliente');
            $table->integer('moneda_idmoneda');
            $table->decimal('total', 20, 2);
            $table->dateTime('fechaHoraEmision')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('docReferencia')->nullable();
            $table->string('descripcion', 100);
            $table->decimal('montoOriginal', 20, 2);
            $table->decimal('montoActual', 20, 2);
            $table->integer('canCuotas')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->dateTime('fechaCancelacion')->nullable();
            $table->string('estado', 50);
        });

        DB::table('cotizacion')->insert([
            'nroCotizacion' => 'CT00152',
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 1,
            'total' => 298,
            'fechaHoraEmision' => '2026-10-06 10:00:00',
        ]);

        $service = app(CxcService::class);
        $firstId = $service->ensureQuotationReceivable('CT00152');
        $secondId = $service->ensureQuotationReceivable('CT00152');
        $receivable = DB::table('cuentasporcobrar')->first();

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, DB::table('cuentasporcobrar')->count());
        $this->assertSame('NUEVO', $receivable->estado);
        $this->assertSame('COTIZACION:CT00152', $receivable->descripcion);
        $this->assertSame(298.0, (float) $receivable->montoActual);
    }

    public function test_credit_is_optional_and_applied_after_deduction(): void
    {
        $withoutCredit = CxcService::calculatePaymentBreakdown(1000, false, 200, false, 880);
        $withCredit = CxcService::calculatePaymentBreakdown(1000, false, 200, true, 680);

        $this->assertSame(0.0, $withoutCredit['credit_applied']);
        $this->assertSame(880.0, $withoutCredit['cash_applied']);
        $this->assertSame(200.0, $withCredit['credit_applied']);
        $this->assertSame(680.0, $withCredit['cash_applied']);
        $this->assertSame(0.0, $withCredit['remaining_debt']);
    }

    public function test_only_payment_exceeding_gross_becomes_new_credit_after_deduction(): void
    {
        $withinGross = CxcService::calculatePaymentBreakdown(1000, false, 0, false, 1000);
        $overGross = CxcService::calculatePaymentBreakdown(1000, false, 0, false, 1100);

        $this->assertSame(880.0, $withinGross['cash_applied']);
        $this->assertSame(0.0, $withinGross['new_credit']);
        $this->assertSame(0.0, $withinGross['remaining_debt']);
        $this->assertSame(880.0, $overGross['cash_applied']);
        $this->assertSame(100.0, $overGross['new_credit']);
        $this->assertSame(0.0, $overGross['remaining_debt']);
    }

    public function test_group_invoice_overpayment_is_separated_from_unit_debt(): void
    {
        $result = CxcService::calculatePaymentBreakdown(160, false, 0, false, 180);

        $this->assertSame(160.0, $result['cash_applied']);
        $this->assertSame(20.0, $result['new_credit']);
        $this->assertSame(0.0, $result['remaining_debt']);
    }

    public function test_group_payment_records_unit_bank_entries_credit_and_individual_successors(): void
    {
        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('detraccion')->nullable();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('cotizacion', function (Blueprint $table): void {
            $table->string('nroCotizacion')->primary();
            $table->string('batch_id')->nullable();
            $table->string('estado');
            $table->string('cliente_idcliente');
            $table->integer('moneda_idmoneda');
            $table->integer('formaPago_idformaPago')->nullable();
            $table->decimal('total', 20, 2);
            $table->string('docReferencia')->nullable();
            $table->string('archivoPago')->nullable();
            $table->date('fechaPago')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->integer('tipoCobro_idtipoCobros');
            $table->integer('moneda_idmoneda');
            $table->string('docReferencia')->nullable();
            $table->string('docfactura')->nullable();
            $table->string('descripcion', 100);
            $table->decimal('montoOriginal', 20, 2);
            $table->decimal('montoActual', 20, 2);
            $table->integer('canCuotas')->nullable();
            $table->dateTime('fechaRegistro')->nullable();
            $table->dateTime('fechaCancelacion')->nullable();
            $table->string('estado', 50);
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
        Schema::create('almacen', function (Blueprint $table): void {
            $table->increments('idalmacen');
            $table->string('detalle')->nullable();
            $table->string('periodo')->nullable();
            $table->integer('tipoElemento_idtipoElemento')->nullable();
        });
        Schema::create('tipoelemento', function (Blueprint $table): void {
            $table->increments('idtipoElemento');
            $table->string('nombre');
        });
        Schema::create('detallecotizacion', function (Blueprint $table): void {
            $table->increments('iddetalleCotizacion');
            $table->string('cotizacion_nroCotizacion');
            $table->integer('almacen_idalmacen');
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
            $table->string('descripcion')->nullable();
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
            $table->string('informacion', 100);
            $table->decimal('monto', 20, 2);
            $table->string('tipoMov', 1);
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

        DB::table('cliente')->insert([
            'idcliente' => 'CLIENTE-1',
            'detraccion' => '0',
            'razonSocial' => 'Cliente de prueba',
        ]);
        DB::table('tipocobro')->insert(['idtipoCobros' => 5, 'recurrencia' => 'M', 'tiempo' => 1]);
        DB::table('moneda')->insert(['idmoneda' => 1, 'detalle' => 'Sol', 'simbolo' => 'S/']);
        DB::table('entidadbancaria')->insert([
            ['identidadBancaria' => 1, 'razonSocial' => 'Banco'],
            ['identidadBancaria' => 2, 'razonSocial' => 'Banco Internacional'],
            ['identidadBancaria' => 5, 'razonSocial' => 'Banco de la Nacion'],
        ]);
        DB::table('formapago')->insert([
            ['idformaPago' => 1, 'detalle' => 'Contado'],
            ['idformaPago' => 2, 'detalle' => 'Crédito'],
        ]);

        $services = [41 => 50.0, 42 => 50.0, 43 => 60.0];
        $cxcIds = [];
        foreach ($services as $serviceId => $amount) {
            DB::table('serviciocliente')->insert([
                'idservicioCliente' => $serviceId,
                'cliente_idcliente' => 'CLIENTE-1',
                'estado' => 'activo',
                'fechaInicio' => '2026-10-27',
                'fecheVencimiento' => '2026-11-27',
                'moneda_idmoneda' => 1,
                'tipoCobro_idtipoCobros' => 5,
                'monto' => $amount,
                'vehiculo_placa' => 'PLACA-' . $serviceId,
            ]);
            $cxcIds[] = DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 5,
                'moneda_idmoneda' => 1,
                'descripcion' => 'CXC 27/10/2026 a 27/11/2026 SC-' . $serviceId,
                'montoOriginal' => $amount,
                'montoActual' => $amount,
                'fechaCancelacion' => '2026-11-27',
                'estado' => '1',
            ]);
        }

        $result = (new CxcService())->procesarPagoGrupo($cxcIds, [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 180,
            'monto_factura' => 180,
            'fechaPago' => '2026-11-02',
            'fechaFin' => '2026-12-15',
            'docReferencia' => 'F001-180',
            'descripcion' => 'Factura grupal',
            'servicio_ids' => array_keys($services),
            'renewal_cxc_id' => $cxcIds[2],
        ]);

        $bankRows = DB::table('bancos')->orderBy('idbancos')->get();
        $this->assertSame(160.0, (float) $bankRows->where('tipoMov', 'I')->sum('monto'));
        $this->assertSame([50.0, 50.0, 60.0], $bankRows->where('tipoMov', 'I')->pluck('monto')->map(fn ($amount): float => (float) $amount)->sort()->values()->all());
        $this->assertSame(20.0, (float) $bankRows->where('tipoMov', 'A')->sum('monto'));
        $this->assertSame(['3', '3', '3'], DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $cxcIds)->orderBy('idcuentasPorCobrar')->pluck('estado')->all());
        $successors = DB::table('cuentasporcobrar')->whereNotIn('idcuentasPorCobrar', $cxcIds)->orderBy('montoOriginal')->get();
        $this->assertCount(3, $successors);
        $this->assertSame([50.0, 50.0, 60.0], $successors->pluck('montoOriginal')->map(fn ($amount): float => (float) $amount)->all());
        $this->assertSame(['2026-12-15', '2026-12-15', '2026-12-15'], $successors->pluck('fechaCancelacion')->all());
        foreach ($successors as $successor) {
            $this->assertStringContainsString('27/11/2026 a 15/12/2026', $successor->descripcion);
        }
        $this->assertSame(
            ['2026-12-15', '2026-12-15', '2026-12-15'],
            DB::table('serviciocliente')->whereIn('idservicioCliente', array_keys($services))->orderBy('idservicioCliente')->pluck('fecheVencimiento')->all()
        );
        $this->assertSame(['2026-11-02', '2026-11-02', '2026-11-02'], $successors->map(fn ($cxc): string => Carbon::parse($cxc->fechaRegistro)->toDateString())->all());
        $this->assertSame(180.0, $result['cash_received']);

        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '1']);
        $partialCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'descripcion' => 'Cobro por servicio SC-41',
            'montoOriginal' => 1000,
            'montoActual' => 1000,
            'fechaCancelacion' => '2026-11-27',
            'estado' => 'FACTURADO',
        ]);
        (new CxcService())->procesarPagoGrupo([$partialCxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 5,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 50,
            'monto_factura' => 1000,
            'fechaPago' => '2026-11-02',
            'docReferencia' => 'F001-1000',
            'descripcion' => 'Abono parcial de servicio',
            'servicio_ids' => [41],
            'renewal_cxc_id' => $partialCxcId,
        ]);
        $this->assertSame(950.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $partialCxcId)->value('montoActual'));
        $this->assertSame('Pendiente Pago parcial', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $partialCxcId)->value('estado'));
        $this->assertSame(50.0, (float) DB::table('bancos')->where('informacion', 'Pago Servicio - PLACA-41 (Abono parcial de servicio)')->value('monto'));
        $this->assertSame(0, DB::table('bancos')->where('tipoMov', 'T')->count());

        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '0']);
        DB::table('moneda')->insert(['idmoneda' => 2, 'detalle' => 'Dolar', 'simbolo' => '$']);
        $this->assertSame(345.0, CxcService::convertPaymentAmountToDebtCurrency(100, 3.45, 1, 2));
        $this->assertSame(100.0, CxcService::convertPaymentAmountToDebtCurrency(345, 3.45, 2, 1));
        $this->assertSame(103.5, CxcService::convertPaymentAmountToDebtCurrency(30, 3.45, 1, 2));
        try {
            CxcService::convertPaymentAmountToDebtCurrency(100, 3.45, 1, 1);
            $this->fail('No se debe convertir entre la misma moneda.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('La moneda de pago debe ser distinta a la moneda de la deuda.', $exception->getMessage());
        }
        $quoteCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:CT00152',
            'montoOriginal' => 345,
            'montoActual' => 345,
            'estado' => 'NUEVO',
        ]);
        $quoteConvertedTotal = CxcService::convertPaymentAmountToDebtCurrency(345, 3.45, 2, 1);
        $this->assertSame(100.0, $quoteConvertedTotal);
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $quoteCxcId)->update([
            'moneda_idmoneda' => 2,
            'montoOriginal' => $quoteConvertedTotal,
            'montoActual' => $quoteConvertedTotal,
        ]);

        $quotePaymentData = [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 2,
            'currency_selector' => 2,
            'payment_currency_mode' => 'same',
            'tipo_cambio' => 1,
            'monto_cancelado' => 100,
            'monto_banco' => 100,
            'modo_pago' => 'total',
            'montoPago' => 100,
            'monto_recibido' => 100,
            'monto_convertido' => 100,
            'fechaPago' => '2026-11-02',
            'docReferencia' => 'F001-345',
            'descripcion' => 'Pago de cotización CT00152',
            'renewal_cxc_id' => $quoteCxcId,
            'aplicar_deduccion' => false,
            'origen_pago' => 'cotizacion',
        ];
        try {
            (new CxcService())->procesarPagoGrupo(
                [$quoteCxcId],
                array_diff_key($quotePaymentData, ['origen_pago' => true])
            );
            $this->fail('Una CXC de cotización no debe entrar por el procesador de pagos de servicios.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Las cuentas de cotización y de servicios deben procesarse por separado.', $exception->getMessage());
        }
        (new CxcService())->procesarPagoGrupo([$quoteCxcId], $quotePaymentData);

        $quoteBankEntry = DB::table('bancos')
            ->where('informacion', 'Pago de cotización CT00152')
            ->first();
        $quotePaymentDetail = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $quoteCxcId)
            ->first();
        $this->assertSame(100.0, (float) $quoteBankEntry->monto);
        $this->assertSame('I', $quoteBankEntry->tipoMov);
        $this->assertSame(2, (int) $quotePaymentDetail->moneda_idmoneda);
        $this->assertSame(0, DB::table('evidenciapago')->count());
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $quoteCxcId)->value('montoActual'));
        $this->assertSame(2, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $quoteCxcId)->value('moneda_idmoneda'));
        $this->assertSame('F001-345', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $quoteCxcId)->value('docReferencia'));

        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '1']);
        $grossQuoteCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:CT-RETENCION',
            'montoOriginal' => 1284,
            'montoActual' => 1284,
            'estado' => 'NUEVO',
        ]);
        (new CxcService())->procesarPagoGrupo([$grossQuoteCxcId], [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 1,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'modo_pago' => 'total',
            'montoPago' => 1284,
            'fechaPago' => '2026-11-02',
            'docReferencia' => 'F001-1284',
            'descripcion' => 'Pago de cotización CT-RETENCION',
            'renewal_cxc_id' => $grossQuoteCxcId,
            'aplicar_deduccion' => false,
            'origen_pago' => 'cotizacion',
        ]);
        $grossQuoteBankEntry = DB::table('bancos')
            ->where('informacion', 'Pago de cotización CT-RETENCION')
            ->first();
        $this->assertSame(1284.0, (float) $grossQuoteBankEntry->monto);
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $grossQuoteCxcId)->value('montoActual'));
        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '0']);

        $creditQuoteCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:CT-CREDITO',
            'montoOriginal' => 103.5,
            'montoActual' => 103.5,
            'estado' => 'NUEVO',
        ]);
        $creditService = new CxcService();
        $firstInstallment = [
            'entidadBancaria_identidadBancaria' => 1,
            'formaPago_idformaPago' => 2,
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'currency_selector' => 1,
            'payment_currency_mode' => 'same',
            'tipo_cambio' => 1,
            'monto_cancelado' => 60,
            'monto_banco' => 60,
            'monto_recibido' => 60,
            'monto_convertido' => 60,
            'modo_pago' => 'parcial',
            'montoPago' => 60,
            'canCuotas' => 2,
            'cuotas_montos' => [60, 43.5],
            'cuotas_fechas' => ['2026-11-02', '2026-12-02'],
            'cuota_cotizacion_numero' => 1,
            'cuota_cotizacion_fecha' => '2026-11-02',
            'fechaPago' => '2026-11-02',
            'docReferencia' => 'F001-103',
            'descripcion' => 'Primer pago de cotización a crédito',
            'renewal_cxc_id' => $creditQuoteCxcId,
            'aplicar_deduccion' => false,
            'origen_pago' => 'cotizacion',
        ];
        $creditService->procesarPagoGrupo([$creditQuoteCxcId], $firstInstallment);

        $this->assertSame(43.5, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditQuoteCxcId)->value('montoActual'));
        $this->assertSame(1, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditQuoteCxcId)->value('moneda_idmoneda'));
        $pendingQuoteInstallment = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditQuoteCxcId)
            ->where('nCuota', 2)
            ->where('estado', '0')
            ->first();
        $this->assertNotNull($pendingQuoteInstallment);
        $this->assertStringContainsString('Monto: 43.50', $pendingQuoteInstallment->descripcion);
        $this->assertSame('2026-12-02', Carbon::parse($pendingQuoteInstallment->fechaPagoProgramada)->toDateString());
        $paidFirstQuoteInstallment = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditQuoteCxcId)
            ->where('nCuota', 1)
            ->where('estado', '1')
            ->first();
        $this->assertNotNull($paidFirstQuoteInstallment);
        $this->assertNotNull($pendingQuoteInstallment->bancos_idbancos);
        $this->assertSame((int) $paidFirstQuoteInstallment->bancos_idbancos, (int) $pendingQuoteInstallment->bancos_idbancos);
        $creditService->procesarPagoGrupo([$creditQuoteCxcId], array_merge($firstInstallment, [
            'monto_cancelado' => 43.5,
            'monto_banco' => 43.5,
            'monto_recibido' => 43.5,
            'monto_convertido' => 43.5,
            'modo_pago' => 'parcial',
            'montoPago' => 43.5,
            'canCuotas' => null,
            'cuotas_montos' => [],
            'cuotas_fechas' => [],
            'cuota_cotizacion_numero' => 2,
            'cuota_cotizacion_fecha' => '2026-12-02',
            'fechaPago' => '2026-12-02',
            'descripcion' => 'Segundo pago de cotización a crédito',
        ]));

        $creditBankEntries = DB::table('bancos')
            ->whereIn('informacion', [
                'Primer pago de cotización a crédito',
                'Segundo pago de cotización a crédito',
            ])
            ->orderBy('idbancos')
            ->get();
        $creditPaymentDetails = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditQuoteCxcId)
            ->whereNotNull('fechaPago')
            ->orderBy('iddetalleCXC')
            ->get();
        $this->assertSame(['I', 'I'], $creditBankEntries->pluck('tipoMov')->all());
        $this->assertSame([43.5, 60.0], $creditBankEntries->pluck('monto')->map(fn ($amount): float => (float) $amount)->sort()->values()->all());
        $this->assertSame([1, 1], $creditPaymentDetails->pluck('moneda_idmoneda')->map(fn ($currencyId): int => (int) $currencyId)->all());
        $this->assertSame([1, 2], $creditPaymentDetails->pluck('nCuota')->map(fn ($number): int => (int) $number)->all());
        $this->assertSame(0, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditQuoteCxcId)
            ->where('estado', '0')
            ->count());
        $this->assertSame(2, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditQuoteCxcId)
            ->count());
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditQuoteCxcId)->value('montoActual'));
        $this->assertSame(0, DB::table('evidenciapago')->count());

        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );
        $makeQuotationRequest = function (string $uri, array $input, array $files = []): Request {
            $request = Request::create($uri, 'POST', $input, [], $files);
            $request->setLaravelSession(app('session')->driver());
            app()->instance('request', $request);

            return $request;
        };
        $cashQuotation = 'CT-CURRENCY-CASH';
        DB::table('cotizacion')->insert([
            'nroCotizacion' => $cashQuotation,
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 2,
            'formaPago_idformaPago' => 1,
            'total' => 30,
        ]);
        $cashCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 2,
            'descripcion' => 'COTIZACION:' . $cashQuotation,
            'montoOriginal' => 30,
            'montoActual' => 30,
            'estado' => 'NUEVO',
        ]);
        $controller->approveQuotation($makeQuotationRequest('/quotation/cash', [
            'fecha_pago' => '2026-10-12',
            'docReferencia' => 'F001-103',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'moneda_idmoneda' => 2,
            'payment_currency_mode' => 'other',
            'currency_selector' => 1,
            'tipo_cambio' => 3.45,
            'monto_cancelado' => 103.50,
        ]), $cashQuotation);
        $this->assertSame(30.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cashCxcId)->value('montoOriginal'));
        $this->assertSame(2, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $cashCxcId)->value('moneda_idmoneda'));
        $this->assertSame(103.5, (float) DB::table('bancos')->where('informacion', 'Pago de cotización ' . $cashQuotation)->value('monto'));
        $this->assertSame(1, (int) DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cashCxcId)
            ->value('moneda_idmoneda'));

        $reverseCashQuotation = 'CT-CURRENCY-CASH-REVERSE';
        DB::table('cotizacion')->insert([
            'nroCotizacion' => $reverseCashQuotation,
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 1,
            'formaPago_idformaPago' => 1,
            'total' => 345,
        ]);
        $reverseCashCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 1,
            'descripcion' => 'COTIZACION:' . $reverseCashQuotation,
            'montoOriginal' => 345,
            'montoActual' => 345,
            'estado' => 'NUEVO',
        ]);
        $controller->approveQuotation($makeQuotationRequest('/quotation/cash/reverse', [
            'fecha_pago' => '2026-10-12',
            'docReferencia' => 'F001-100',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'other',
            'currency_selector' => 2,
            'tipo_cambio' => 3.45,
            'monto_cancelado' => 100,
        ]), $reverseCashQuotation);
        $this->assertSame(345.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $reverseCashCxcId)->value('montoOriginal'));
        $this->assertSame(1, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $reverseCashCxcId)->value('moneda_idmoneda'));
        $this->assertSame(100.0, (float) DB::table('bancos')->where('informacion', 'Pago de cotización ' . $reverseCashQuotation)->value('monto'));

        $creditQuotation = 'CT-CURRENCY-CREDIT';
        DB::table('cotizacion')->insert([
            'nroCotizacion' => $creditQuotation,
            'estado' => '1',
            'cliente_idcliente' => 'CLIENTE-1',
            'moneda_idmoneda' => 2,
            'formaPago_idformaPago' => 2,
            'total' => 30,
        ]);
        $creditCxcId = DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => 'CLIENTE-1',
            'tipoCobro_idtipoCobros' => 1,
            'moneda_idmoneda' => 2,
            'descripcion' => 'COTIZACION:' . $creditQuotation,
            'montoOriginal' => 30,
            'montoActual' => 30,
            'estado' => 'NUEVO',
        ]);
        $controller->approveQuotation($makeQuotationRequest('/quotation/credit', [
            'fecha_pago' => '2026-10-12',
            'docReferencia' => 'F001-103',
            'formaPago_idformaPago' => 2,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 65,
            'canCuotas' => 2,
            'cuotas_montos' => [18.84, 11.16],
            'cuotas_fechas' => ['2026-10-10', '2026-11-11'],
            'moneda_idmoneda' => 2,
            'payment_currency_mode' => 'other',
            'currency_selector' => 1,
            'tipo_cambio' => 3.45,
            'monto_cancelado' => 65,
            'descripcion' => str_repeat('P', 70),
        ]), $creditQuotation);
        $this->assertSame(30.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditCxcId)->value('montoOriginal'));
        $this->assertSame(11.16, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditCxcId)->value('montoActual'));
        $this->assertSame(2, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditCxcId)->value('moneda_idmoneda'));
        $this->assertSame(2, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditCxcId)->value('canCuotas'));
        $this->assertSame(str_repeat('P', 70), DB::table('bancos')->where('informacion', str_repeat('P', 70))->value('informacion'));
        $this->assertSame(65.0, (float) DB::table('bancos')->where('informacion', str_repeat('P', 70))->value('monto'));
        $paidFirstInstallment = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditCxcId)
            ->where('nCuota', 1)
            ->where('estado', '1')
            ->first();
        $this->assertNotNull($paidFirstInstallment);
        $this->assertSame(str_repeat('P', 70), $paidFirstInstallment->descripcion);
        $this->assertSame('2026-10-10', Carbon::parse($paidFirstInstallment->fechaPagoProgramada)->toDateString());
        $this->assertSame('2026-10-12', Carbon::parse($paidFirstInstallment->fechaPago)->toDateString());
        $pendingConvertedInstallment = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditCxcId)
            ->where('nCuota', 2)
            ->where('estado', '0')
            ->first();
        $this->assertNotNull($pendingConvertedInstallment);
        $this->assertStringContainsString('Monto: 11.16', $pendingConvertedInstallment->descripcion);
        $this->assertSame(2, (int) $pendingConvertedInstallment->moneda_idmoneda);
        $this->assertNull($pendingConvertedInstallment->fechaPago);
        $this->assertNotNull($pendingConvertedInstallment->bancos_idbancos);
        $this->assertSame((int) $paidFirstInstallment->bancos_idbancos, (int) $pendingConvertedInstallment->bancos_idbancos);
        $controller->approveQuotation($makeQuotationRequest('/quotation/credit/next', [
            'fecha_pago' => '2026-11-11',
            'docReferencia' => 'F001-103',
            'formaPago_idformaPago' => 2,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 11.16,
            'moneda_idmoneda' => 2,
            'payment_currency_mode' => 'same',
        ]), $creditQuotation);
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditCxcId)->value('montoActual'));
        $this->assertSame(30.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditCxcId)->value('montoOriginal'));
        $this->assertSame(11.16, (float) DB::table('bancos')->where('informacion', 'Pago de cotización ' . $creditQuotation)->orderByDesc('idbancos')->value('monto'));
        $this->assertSame(2, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditCxcId)
            ->count());

        DB::table('tipoelemento')->insert([
            ['idtipoElemento' => 1, 'nombre' => 'SERVICIO TÉCNICO'],
            ['idtipoElemento' => 2, 'nombre' => 'PLAN'],
            ['idtipoElemento' => 3, 'nombre' => 'EQUIPAMIENTO'],
        ]);
        $createTaxQuote = function (string $quoteId, float $amount, int $paymentId, int $typeId): int {
            DB::table('cotizacion')->insert([
                'nroCotizacion' => $quoteId,
                'estado' => '1',
                'cliente_idcliente' => 'CLIENTE-1',
                'moneda_idmoneda' => 1,
                'formaPago_idformaPago' => $paymentId,
                'total' => $amount,
            ]);
            $warehouseId = DB::table('almacen')->insertGetId([
                'detalle' => 'Cotización ' . $quoteId,
                'tipoElemento_idtipoElemento' => $typeId,
            ]);
            DB::table('detallecotizacion')->insert([
                'cotizacion_nroCotizacion' => $quoteId,
                'almacen_idalmacen' => $warehouseId,
            ]);

            return DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => 'CLIENTE-1',
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => 1,
                'descripcion' => 'COTIZACION:' . $quoteId,
                'montoOriginal' => $amount,
                'montoActual' => $amount,
                'estado' => 'NUEVO',
            ]);
        };
        $netQuoteCxcId = $createTaxQuote('CT-TAX-NET', 1000, 1, 1);
        Storage::fake('public');
        $paymentReceipt = UploadedFile::fake()->image('pago-cotizacion.png');
        $withholdingReceipt = UploadedFile::fake()->image('comprobante-detraccion.png');
        $netResponse = $controller->approveQuotation($makeQuotationRequest('/quotation/tax/net', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-200',
            'descripcion' => 'Comentario de pago y deducción',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 880,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
            'withholding_decision' => 'paid',
            'fecha_detraccion' => '2026-11-11',
            'descripcion_detraccion' => 'Constancia SUNAT',
        ], [
            'archivoPago' => $paymentReceipt,
            'comprobante_detraccion' => $withholdingReceipt,
        ]), 'CT-TAX-NET');
        $this->assertNull($netResponse->getSession()->get('error'), (string) $netResponse->getSession()->get('error'));
        $this->assertSame('CANCELADO', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $netQuoteCxcId)->value('estado'));
        $this->assertSame(1000.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $netQuoteCxcId)->value('montoActual'));
        $this->assertSame(880.0, (float) DB::table('bancos')->where('informacion', 'Comentario de pago y deducción')->value('monto'));
        $this->assertSame(120.0, (float) DB::table('bancos')->where('tipoMov', 'T')->sum('monto'));
        $this->assertSame('Comentario de pago y deducción', DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $netQuoteCxcId)
            ->where('descripcion', 'Comentario de pago y deducción')
            ->value('descripcion'));
        $this->assertSame('Detracción aplicada', DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $netQuoteCxcId)
            ->where('descripcion', 'Detracción aplicada')
            ->value('descripcion'));
        $withholdingMovement = DB::table('bancos')
            ->where('informacion', 'like', 'Detracción pagada CT-TAX-NET%')
            ->first();
        $this->assertSame(5, (int) $withholdingMovement->entidadBancaria_identidadBancaria);
        $this->assertSame('T', $withholdingMovement->tipoMov);
        $this->assertSame(120.0, (float) $withholdingMovement->monto);
        $this->assertSame('2026-11-11', Carbon::parse($withholdingMovement->fechaRegistro)->toDateString());
        $this->assertStringContainsString('Cliente: Cliente de prueba', $withholdingMovement->informacion);
        $this->assertStringContainsString('Constancia SUNAT', $withholdingMovement->informacion);
        $paymentReceiptPath = DB::table('cotizacion')
            ->where('nroCotizacion', 'CT-TAX-NET')
            ->value('archivoPago');
        $withholdingReceiptPath = DB::table('evidenciapago')
            ->where('bancos_idbancos', $withholdingMovement->idbancos)
            ->value('archivo');
        $this->assertNotSame(
            $paymentReceiptPath,
            $withholdingReceiptPath,
            sprintf('Payment proof path: %s; withholding proof path: %s', $paymentReceiptPath, $withholdingReceiptPath)
        );
        Storage::disk('public')->assertExists($paymentReceiptPath);
        Storage::disk('public')->assertExists($withholdingReceiptPath);

        $pendingNetQuoteCxcId = $createTaxQuote('CT-TAX-NET-PENDING', 1000, 1, 1);
        $unconfirmedNetResponse = $controller->approveQuotation($makeQuotationRequest('/quotation/tax/net-pending/unconfirmed', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-202',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 880,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
        ]), 'CT-TAX-NET-PENDING');
        $this->assertStringContainsString('Confirma si el cliente ya pagó', (string) $unconfirmedNetResponse->getSession()->get('error'));
        $this->assertSame('NUEVO', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $pendingNetQuoteCxcId)
            ->value('estado'));
        $unconfirmedNetResponse->getSession()->forget('error');

        Storage::fake('public');
        $initialPaymentReceipt = UploadedFile::fake()->image('pago-inicial.png');
        $pendingNetResponse = $controller->approveQuotation($makeQuotationRequest('/quotation/tax/net-pending', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-202',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 880,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
            'withholding_decision' => 'pending',
        ], [
            'archivoPago' => $initialPaymentReceipt,
        ]), 'CT-TAX-NET-PENDING');
        $this->assertNull($pendingNetResponse->getSession()->get('error'), (string) $pendingNetResponse->getSession()->get('error'));
        $this->assertSame('Pendiente a Detracción', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $pendingNetQuoteCxcId)
            ->value('estado'));
        $initialReceiptPath = DB::table('cotizacion')
            ->where('nroCotizacion', 'CT-TAX-NET-PENDING')
            ->value('archivoPago');
        $this->assertNotEmpty($initialReceiptPath);
        Storage::disk('public')->assertExists($initialReceiptPath);
        $this->assertSame(120.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $pendingNetQuoteCxcId)
            ->value('montoActual'));
        $this->assertSame(880.0, (float) DB::table('bancos')
            ->where('informacion', 'Pago de cotización CT-TAX-NET-PENDING')
            ->value('monto'));

        $completePendingNetResponse = $controller->approveQuotation($makeQuotationRequest('/quotation/tax/net-pending/complete', [
            'completar_deduccion_pendiente' => '1',
            'descripcion' => 'Depósito confirmado posteriormente',
            'fecha_pago' => '2026-11-18',
        ], [
            'archivoPago' => UploadedFile::fake()->create('deposito-detraccion.pdf', 10, 'application/pdf'),
        ]), 'CT-TAX-NET-PENDING');
        $this->assertNull($completePendingNetResponse->getSession()->get('error'), (string) $completePendingNetResponse->getSession()->get('error'));
        $this->assertSame('2', DB::table('cotizacion')->where('nroCotizacion', 'CT-TAX-NET-PENDING')->value('estado'));
        $this->assertSame($initialReceiptPath, DB::table('cotizacion')
            ->where('nroCotizacion', 'CT-TAX-NET-PENDING')
            ->value('archivoPago'));
        $this->assertSame('3', DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $pendingNetQuoteCxcId)
            ->value('estado'));
        $this->assertSame(120.0, (float) DB::table('bancos')
            ->where('informacion', 'like', 'Detracción pagada de CT-TAX-NET-PENDING%')
            ->value('monto'));
        $this->assertStringContainsString('Depósito confirmado posteriormente', (string) DB::table('bancos')
            ->where('informacion', 'like', 'Detracción pagada de CT-TAX-NET-PENDING%')
            ->value('informacion'));
        $this->assertSame('2026-11-18', Carbon::parse(DB::table('bancos')
            ->where('informacion', 'like', 'Detracción pagada de CT-TAX-NET-PENDING%')
            ->value('fechaRegistro'))->toDateString());
        $this->assertSame('2026-11-18', Carbon::parse(DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $pendingNetQuoteCxcId)
            ->where('descripcion', 'Detracción aplicada')
            ->value('fechaPago'))->toDateString());
        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '1']);
        $retentionCxcId = $createTaxQuote('CT-TAX-RETENTION', 1000, 1, 3);
        $controller->approveQuotation($makeQuotationRequest('/quotation/tax/retention', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-206',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 970,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
            'withholding_decision' => 'paid',
        ], [
            'comprobante_detraccion' => UploadedFile::fake()->image('comprobante-retencion.png'),
        ]), 'CT-TAX-RETENTION');
        $this->assertSame(30.0, (float) DB::table('bancos')
            ->where('informacion', 'like', 'Retención pagada CT-TAX-RETENTION%')
            ->value('monto'));
        $this->assertSame('Retención aplicada', DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $retentionCxcId)
            ->where('descripcion', 'Retención aplicada')
            ->value('descripcion'));
        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '0']);
        $this->assertSame('2', DB::table('cotizacion')->where('nroCotizacion', 'CT-TAX-NET')->value('estado'));

        $grossQuoteCxcId = $createTaxQuote('CT-TAX-GROSS', 1575, 1, 1);
        $controller->approveQuotation($makeQuotationRequest('/quotation/tax/gross', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-201',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 1575,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
        ]), 'CT-TAX-GROSS');
        $pendingTaxCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $grossQuoteCxcId)->first();
        $pendingTaxDetail = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $grossQuoteCxcId)
            ->where('estado', '0')
            ->first();
        $this->assertSame('Pendiente Autodetracción', $pendingTaxCxc->estado);
        $this->assertSame(189.0, (float) $pendingTaxCxc->montoActual);
        $this->assertSame(1575.0, (float) DB::table('bancos')->where('informacion', 'Pago de cotización CT-TAX-GROSS')->value('monto'));
        $this->assertNotNull($pendingTaxDetail);
        $this->assertNotNull($pendingTaxDetail->bancos_idbancos);
        $originalPaymentBankId = (int) $pendingTaxDetail->bancos_idbancos;
        $this->assertSame('1', DB::table('cotizacion')->where('nroCotizacion', 'CT-TAX-GROSS')->value('estado'));
        $exportMethod = new \ReflectionMethod($controller, 'prepareQuotationsExportData');
        $exportMethod->setAccessible(true);
        $exportRows = $exportMethod->invoke($controller, collect([(object) [
            'nroCotizacion' => 'CT-TAX-GROSS',
            'cliente_idcliente' => 'CLIENTE-1',
            'razonSocial' => 'Cliente de prueba',
            'nombreComercial' => '',
            'cliente_nombre' => 'Cliente de prueba',
            'cliente_detraccion' => '0',
            'total' => 1575,
            'fechaHoraEmision' => '2026-11-12',
            'cotizacion_estado' => '1',
            'cotizacion_doc_referencia' => 'F001-201',
            'moneda_simbolo' => 'S/',
            'moneda_detalle' => 'Sol',
            'servicio' => 'SERVICIOS TÉCNICOS',
        ]]));
        $this->assertSame('S/ 1,575.00', $exportRows[0]['pago_realizado']);
        $this->assertSame('S/ 189.00', $exportRows[0]['autodetraccion']);
        $this->assertSame('', $exportRows[0]['detraccion']);
        $retentionExportRows = $exportMethod->invoke($controller, collect([(object) [
            'nroCotizacion' => 'CT-TAX-RETENTION',
            'cliente_idcliente' => 'CLIENTE-1',
            'razonSocial' => 'Cliente de prueba',
            'nombreComercial' => '',
            'cliente_nombre' => 'Cliente de prueba',
            'cliente_detraccion' => '1',
            'total' => 1000,
            'fechaHoraEmision' => '2026-11-12',
            'cotizacion_estado' => '2',
            'cotizacion_doc_referencia' => 'F001-206',
            'moneda_simbolo' => 'S/',
            'moneda_detalle' => 'Sol',
            'servicio' => 'EQUIPAMIENTO',
        ]]));
        $this->assertSame('S/ 30.00', $retentionExportRows[0]['retencion']);
        $this->assertSame('', $retentionExportRows[0]['autodetraccion']);
        $netExportRows = $exportMethod->invoke($controller, collect([(object) [
            'nroCotizacion' => 'CT-TAX-NET-PENDING',
            'cliente_idcliente' => 'CLIENTE-1',
            'razonSocial' => 'Cliente de prueba',
            'nombreComercial' => '',
            'cliente_nombre' => 'Cliente de prueba',
            'cliente_detraccion' => '0',
            'total' => 1000,
            'fechaHoraEmision' => '2026-11-12',
            'cotizacion_estado' => '1',
            'cotizacion_doc_referencia' => 'F001-202',
            'moneda_simbolo' => 'S/',
            'moneda_detalle' => 'Sol',
            'servicio' => 'SERVICIOS TÉCNICOS',
        ]]));
        $this->assertSame('S/ 880.00', $netExportRows[0]['pago_realizado']);
        $this->assertSame('S/ 120.00', $netExportRows[0]['detraccion']);
        $this->assertSame('', $netExportRows[0]['autodetraccion']);
        $annulPendingPayment = (new CotizacionService())->anularCotizaciones(['CT-TAX-GROSS']);
        $this->assertFalse($annulPendingPayment['success']);
        $this->assertSame('1', DB::table('cotizacion')->where('nroCotizacion', 'CT-TAX-GROSS')->value('estado'));
        Storage::fake('public');
        $controller->approveQuotation($makeQuotationRequest('/quotation/tax/gross/complete', [
            'completar_deduccion_pendiente' => '1',
            'fecha_pago' => '2026-11-19',
            'descripcion' => 'Se pagó la autodetracción de 189 soles',
        ], [
            'archivoPago' => UploadedFile::fake()->create('deposito.pdf', 10, 'application/pdf'),
        ]), 'CT-TAX-GROSS');
        $this->assertSame('2', DB::table('cotizacion')->where('nroCotizacion', 'CT-TAX-GROSS')->value('estado'));
        $this->assertSame('3', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $grossQuoteCxcId)->value('estado'));
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $grossQuoteCxcId)->value('montoActual'));
        $this->assertSame(189.0, (float) DB::table('bancos')
            ->where('entidadBancaria_identidadBancaria', 5)
            ->where('tipoMov', 'T')
            ->where('informacion', 'like', 'Autodetracción pagada de CT-TAX-GROSS%')
            ->value('monto'));
        $autodetraccionMovement = DB::table('bancos')
            ->where('tipoMov', 'T')
            ->where('informacion', 'like', 'Autodetracción pagada de CT-TAX-GROSS%')
            ->first();
        $this->assertNotNull($autodetraccionMovement);
        $this->assertSame(5, (int) $autodetraccionMovement->entidadBancaria_identidadBancaria);
        $this->assertSame(189.0, (float) $autodetraccionMovement->monto);
        $this->assertStringContainsString('Se pagó autodetracción: 189 soles', $autodetraccionMovement->informacion);
        $this->assertSame(1575.0, (float) DB::table('bancos')
            ->where('idbancos', $originalPaymentBankId)
            ->value('monto'));
        $this->assertSame(2, DB::table('bancos')->whereIn('idbancos', [
            $originalPaymentBankId,
            $autodetraccionMovement->idbancos,
        ])->count());
        $this->assertSame($grossQuoteCxcId, (int) DB::table('detallecxc')
            ->where('bancos_idbancos', $autodetraccionMovement->idbancos)
            ->value('cuentasPorCobrar_idcuentasPorCobrar'));
        $this->assertSame('Autodetracción', (new \ReflectionMethod(
            new BancosController(new BancosService()),
            'movementLabel'
        ))->invoke(new BancosController(new BancosService()), $autodetraccionMovement->tipoMov, $autodetraccionMovement->informacion));
        $grossQuoteBankStats = (new BancosService())->getStats(Request::create('/bancos?q=CT-TAX-GROSS'));
        $this->assertSame(1575.0, $grossQuoteBankStats['balances']['S/']['balance_positivo']);
        $this->assertSame(189.0, $grossQuoteBankStats['balances']['S/']['balance_negativo']);
        $autodetraccionBankStats = (new BancosService())->getStats(Request::create('/bancos?tipoMov=AUTODETRACCION'));
        $this->assertSame(0.0, $autodetraccionBankStats['balances']['S/']['balance_positivo']);
        $this->assertSame(189.0, $autodetraccionBankStats['balances']['S/']['balance_negativo']);
        $bankService = new BancosService();
        $applyBankFilters = new \ReflectionMethod($bankService, 'applyFilters');
        $applyBankFilters->setAccessible(true);
        $autodetraccionQuery = DB::table('bancos as b');
        $applyBankFilters->invoke($bankService, $autodetraccionQuery, Request::create('/?tipoMov=AUTODETRACCION'));
        $this->assertSame(
            [(int) $autodetraccionMovement->idbancos],
            array_map('intval', $autodetraccionQuery->pluck('b.idbancos')->all())
        );
        $this->assertSame(0, DB::table('bancos')
            ->where('tipoMov', 'S')
            ->where('informacion', 'like', 'Autodetracción%')
            ->count());
        $this->assertSame('1', DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $grossQuoteCxcId)
            ->where('descripcion', 'Detracción aplicada')
            ->value('estado'));
        $this->assertSame(6, DB::table('evidenciapago')->count());
        (new CxcService())->revertirPagoCancelado($grossQuoteCxcId, 'tester', 'Reversión de prueba');
        $this->assertStringStartsWith('[ANULADO]', (string) DB::table('bancos')
            ->where('idbancos', $autodetraccionMovement->idbancos)
            ->value('informacion'));
        $revertedAutodetraccionBankStats = (new BancosService())->getStats(Request::create('/bancos', 'GET', [
            'q' => 'ANULADO',
            'entidadBancaria_identidadBancaria' => 5,
        ]));
        $this->assertSame(189.0, $revertedAutodetraccionBankStats['balances']['S/']['balance_positivo']);
        $this->assertSame(0.0, $revertedAutodetraccionBankStats['balances']['S/']['balance_negativo']);
        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '1']);
        $creditTaxCxcId = $createTaxQuote('CT-TAX-CREDIT', 1000, 2, 2);
        $creditTaxResponse = $controller->approveQuotation($makeQuotationRequest('/quotation/tax/credit', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-202',
            'formaPago_idformaPago' => 2,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'parcial',
            'montoPago' => 300,
            'canCuotas' => 3,
            'cuotas_montos' => [300, 290, 290],
            'cuotas_fechas' => ['2026-11-12', '2026-12-12', '2027-01-11'],
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
        ]), 'CT-TAX-CREDIT');
        $this->assertNull($creditTaxResponse->getSession()->get('error'), (string) $creditTaxResponse->getSession()->get('error'));
        $this->assertSame(120.0, (float) DB::table('bancos')
            ->where('tipoMov', 'T')
            ->where('informacion', 'like', 'Detracción pagada CT-TAX-CREDIT%')
            ->value('monto'));
        $this->assertSame(300.0, (float) DB::table('bancos')->where('informacion', 'Pago de cotización CT-TAX-CREDIT')->value('monto'));
        $this->assertSame(580.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $creditTaxCxcId)->value('montoActual'));
        $this->assertSame([290.0, 290.0], DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $creditTaxCxcId)
            ->where('estado', '0')
            ->orderBy('nCuota')
            ->get()
            ->pluck('descripcion')
            ->map(fn (string $description): float => (float) substr($description, strpos($description, 'Monto: ') + 7))
            ->all());

        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '0']);
        $crossCurrencyCxcId = $createTaxQuote('CT-CROSS-CURRENCY', 45, 1, 1);
        DB::table('cotizacion')->where('nroCotizacion', 'CT-CROSS-CURRENCY')->update([
            'moneda_idmoneda' => 2,
            'total' => 45,
        ]);
        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $crossCurrencyCxcId)->update([
            'moneda_idmoneda' => 2,
            'montoOriginal' => 45,
            'montoActual' => 45,
        ]);
        $crossCurrencyResponse = $controller->approveQuotation($makeQuotationRequest('/quotation/cross-currency', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-203',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'moneda_idmoneda' => 2,
            'payment_currency_mode' => 'other',
            'currency_selector' => 1,
            'tipo_cambio' => 3.45,
            'monto_cancelado' => 155.25,
        ]), 'CT-CROSS-CURRENCY');
        $this->assertNull($crossCurrencyResponse->getSession()->get('error'), (string) $crossCurrencyResponse->getSession()->get('error'));
        $this->assertSame(45.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $crossCurrencyCxcId)->value('montoOriginal'));
        $this->assertSame(0.0, (float) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $crossCurrencyCxcId)->value('montoActual'));
        $this->assertSame(2, (int) DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $crossCurrencyCxcId)->value('moneda_idmoneda'));
        $this->assertSame(155.25, (float) DB::table('bancos')->where('informacion', 'Pago de cotización CT-CROSS-CURRENCY')->value('monto'));
        $this->assertSame(1, (int) DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $crossCurrencyCxcId)
            ->where('descripcion', 'Pago de cotización CT-CROSS-CURRENCY')
            ->value('moneda_idmoneda'));
        $this->assertSame(0, DB::table('bancos')
            ->where('informacion', 'like', 'Detracción pagada CT-CROSS-CURRENCY%')
            ->count());
        $paymentSymbol = DB::table('detallecxc as dc')
            ->join('moneda as m', 'm.idmoneda', '=', 'dc.moneda_idmoneda')
            ->where('dc.cuentasPorCobrar_idcuentasPorCobrar', $crossCurrencyCxcId)
            ->where('dc.descripcion', 'Pago de cotización CT-CROSS-CURRENCY')
            ->value('m.simbolo');
        $this->assertSame('S/', $paymentSymbol);

        DB::table('cliente')->where('idcliente', 'CLIENTE-1')->update(['detraccion' => '0']);
        $equipmentTaxCxcId = $createTaxQuote('CT-TAX-EQUIPMENT', 1000, 1, 3);
        $controller->approveQuotation($makeQuotationRequest('/quotation/tax/equipment', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-204',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 1000,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
        ]), 'CT-TAX-EQUIPMENT');
        $this->assertSame('Pendiente a Retención', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $equipmentTaxCxcId)->value('estado'));
        $this->assertSame(30.0, (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $equipmentTaxCxcId)
            ->value('montoActual'));
        $this->assertSame(0, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $equipmentTaxCxcId)
            ->whereIn('descripcion', ['Detracción aplicada', 'Retención aplicada'])
            ->count());

        $thresholdCxcId = $createTaxQuote('CT-TAX-THRESHOLD', 700, 1, 3);
        $controller->approveQuotation($makeQuotationRequest('/quotation/tax/threshold', [
            'fecha_pago' => '2026-11-12',
            'docReferencia' => 'F001-205',
            'formaPago_idformaPago' => 1,
            'entidadBancaria_identidadBancaria' => 1,
            'modo_pago' => 'total',
            'montoPago' => 700,
            'moneda_idmoneda' => 1,
            'payment_currency_mode' => 'same',
        ]), 'CT-TAX-THRESHOLD');
        $this->assertSame('CANCELADO', DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $thresholdCxcId)->value('estado'));
        $this->assertSame(0, DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $thresholdCxcId)
            ->whereIn('descripcion', ['Detracción aplicada', 'Retención aplicada'])
            ->count());
    }

    public function test_5760_detraccion_rounds_to_whole_sol_and_uses_the_net_amount_of_5069(): void
    {
        $result = CxcService::calculatePaymentBreakdown(5760, false, 0, false, 0);
        $paidGross = CxcService::calculatePaymentBreakdown(5760, false, 0, false, 5760);

        $this->assertSame(691.0, $result['deduction']);
        $this->assertSame(5069.0, $result['cash_due']);
        $this->assertSame(5069.0, $paidGross['cash_applied']);
        $this->assertSame(0.0, $paidGross['new_credit']);
    }

    public function test_withholding_can_remain_unapplied_until_proof_is_confirmed(): void
    {
        $pendingDetraction = CxcService::calculatePaymentBreakdown(750, false, 0, false, 660, false);
        $pendingRetention = CxcService::calculatePaymentBreakdown(750, true, 0, false, 727.50, false);

        $this->assertSame(0.0, $pendingDetraction['deduction']);
        $this->assertSame(90.0, $pendingDetraction['remaining_debt']);
        $this->assertSame(0.0, $pendingRetention['deduction']);
        $this->assertSame(22.5, $pendingRetention['remaining_debt']);
    }

    public function test_partial_payment_before_period_end_uses_partial_state(): void
    {
        $state = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-30',
            Carbon::parse('2026-09-29')
        );

        $this->assertSame('Pendiente Pago parcial', $state);
    }

    public function test_partial_payment_remains_pending_after_period_end_until_fully_paid(): void
    {
        $partialState = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-27',
            Carbon::parse('2026-09-28'),
            null,
            null,
            true
        );
        $paidState = CxcService::resolvePaymentState(
            0.00,
            0.00,
            'Contado',
            false,
            '2026-09-27',
            Carbon::parse('2026-09-28'),
            null,
            null,
            true
        );

        $this->assertSame('Pendiente Pago parcial', $partialState);
        $this->assertSame('3', $paidState);
    }

    public function test_credit_payment_before_period_end_uses_credit_state(): void
    {
        $state = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Crédito 30 días',
            false,
            '2026-09-30',
            Carbon::parse('2026-09-29')
        );

        $this->assertSame('Pendiente a credito', $state);
    }

    public function test_open_balance_becomes_overdue_only_after_period_end(): void
    {
        $stateOnEndDate = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-27',
            Carbon::parse('2026-09-27')
        );
        $stateAfterEndDate = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-27',
            Carbon::parse('2026-09-28')
        );

        $this->assertSame('Pendiente Pago parcial', $stateOnEndDate);
        $this->assertSame('4', $stateAfterEndDate);
    }

    public function test_fully_paid_deduction_and_retention_both_cancel_the_cxc(): void
    {
        $detraccionState = CxcService::resolvePaymentState(0.00, 12.00, 'Contado', false, '2026-09-30');
        $retentionState = CxcService::resolvePaymentState(0.00, 3.00, 'Contado', true, '2026-09-30');

        $this->assertSame('3', $detraccionState);
        $this->assertSame('3', $retentionState);
    }

    public function test_credit_payment_states_include_installment_number_and_advance(): void
    {
        $firstState = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Crédito 30 días',
            false,
            '2026-09-30',
            Carbon::parse('2026-09-29'),
            1,
            2
        );
        $secondState = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Crédito 30 días',
            false,
            '2026-10-30',
            Carbon::parse('2026-09-29'),
            2,
            2
        );

        $this->assertSame('Pendiente a credito: 1/2', $firstState);
        $this->assertSame('Pendiente a credito: 2/2', $secondState);
    }

    public function test_next_period_generation_date_is_first_day_of_period_end_month(): void
    {
        $generationDate = CxcService::automaticGenerationDate('2026-10-20');

        $this->assertNotNull($generationDate);
        $this->assertSame('2026-10-01', $generationDate->toDateString());
    }

    public function test_advance_period_end_counts_current_period_in_total(): void
    {
        $this->assertSame('2027-01-15', CxcService::advancePeriodEnd('2026-11-15', 3)->toDateString());
        $this->assertSame('2026-11-15', CxcService::advancePeriodEnd('2026-11-15', 1)->toDateString());
        $this->assertSame('2026-11-25', CxcService::advancePeriodEnd('2026-10-25', 2)->toDateString());
    }

    public function test_advance_months_are_saved_as_a_bounded_description_suffix(): void
    {
        $description = CxcService::descriptionWithAdvanceMonths(str_repeat('A', 100), 3);

        $this->assertSame(3, CxcService::advanceMonthsFromDescription($description));
        $this->assertStringEndsWith(' - 3', $description);
        $this->assertLessThanOrEqual(70, mb_strlen($description, 'UTF-8'));
        $this->assertSame(70, mb_strlen(CxcService::descriptionWithAdvanceMonths(str_repeat('A', 100), 0), 'UTF-8'));
        $this->assertSame(0, CxcService::advanceMonthsFromDescription('Comentario libre del usuario'));
        $this->assertSame(3, CxcService::advanceMonthsFromDescription(
            CxcService::descriptionWithAdvanceMonths('', 3)
        ));
    }

    public function test_previous_debt_picker_excludes_current_month_and_future_periods(): void
    {
        $octoberFirst = Carbon::parse('2026-10-01');
        $this->assertFalse(CxcService::isPreviousBillingPeriod(Carbon::parse('2026-10-15'), $octoberFirst));
        $this->assertFalse(CxcService::isPreviousBillingPeriod(Carbon::parse('2026-11-15'), $octoberFirst));
        $this->assertTrue(CxcService::isPreviousBillingPeriod(Carbon::parse('2026-10-15'), Carbon::parse('2026-11-01')));
    }

    public function test_full_payment_renews_immediately_when_period_end_exists(): void
    {
        $this->assertTrue(CxcService::shouldRenewAfterFullPayment('2026-10-20'));
        $this->assertFalse(CxcService::shouldRenewAfterFullPayment(null));
    }

    #[DataProvider('automaticRenewalCases')]
    public function test_scheduled_renewal_only_includes_open_cxc_states(
        string $state,
        string $periodEnd,
        string $today,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            CxcService::shouldGenerateNextPeriod($state, $periodEnd, Carbon::parse($today))
        );
    }

    public static function automaticRenewalCases(): array
    {
        return [
            'pending on generation day' => ['1', '2026-10-20', '2026-10-01', true],
            'invoiced on generation day' => ['2', '2026-10-20', '2026-10-01', true],
            'overdue on generation day' => ['4', '2026-10-20', '2026-10-01', true],
            'partial payment remains pending' => ['Pendiente Pago parcial', '2026-10-20', '2026-10-01', true],
            'credit remains pending' => ['Pendiente a credito', '2026-10-20', '2026-10-01', true],
            'does not renew before first day' => ['1', '2026-10-20', '2026-09-30', false],
            'paid cxc is excluded from schedule' => ['3', '2026-10-20', '2026-10-01', false],
            'cancelled alias is excluded from schedule' => ['CANCELADO', '2026-10-20', '2026-10-01', false],
            'deduction cancellation is excluded from schedule' => ['Cancelado Detracción', '2026-10-20', '2026-10-01', false],
        ];
    }
}