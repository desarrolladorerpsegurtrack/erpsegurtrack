<?php

namespace Tests\Unit;

use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CotizacionService;
use App\Services\CuentasPorCobrarService;
use App\Services\CxcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CxcServiceExportTest extends TestCase
{
    public function test_service_export_uses_the_compact_payment_template(): void
    {
        $controller = new CuentasPorCobrarController(
            new CxcService,
            new CuentasPorCobrarService,
            new CotizacionService
        );
        $export = new \ReflectionMethod($controller, 'exportServiceGroupsXlsx');
        $export->setAccessible(true);
        $response = $export->invoke($controller, [[
            'cliente_id' => '20100000000',
            'cliente_nombre' => 'Cliente de prueba',
            'fecha_fin' => '31/10/2026',
            'mes' => 'octubre',
            'moneda_simbolo' => 'S/',
            'cxc_ids' => [],
            'tipos_servicios' => [[
                'servicio_nombre' => 'Servicio mensual',
                'monto_subtotal' => 1000,
            ]],
        ]]);
        $path = $response->getFile()->getPathname();

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame([
                'RUC', 'CLIENTE', 'FECHA LÍMITE', 'MES', 'FACTURA', 'TOTAL',
                'PAGO REALIZADO', 'DETRACCION', 'FECHA ABONO', 'AUTODETRACCIÓN', 'FECHA ABONO',
            ], $sheet->rangeToArray('A1:K1')[0]);
            $this->assertSame('31/10/2026', $sheet->getCell('C2')->getValue());
            $this->assertSame('S/ 1,000.00', $sheet->getCell('F2')->getValue());
            $this->assertNull($sheet->getCell('G2')->getValue());
            $this->assertNull($sheet->getCell('H2')->getValue());
            $this->assertNull($sheet->getCell('J2')->getValue());
            $this->assertSame('A8E08C', $sheet->getStyle('J1')->getFill()->getStartColor()->getRGB());
            $this->assertSame('A8E08C', $sheet->getStyle('K2')->getFill()->getStartColor()->getRGB());
            $this->assertSame('thin', $sheet->getStyle('A2')->getBorders()->getLeft()->getBorderStyle());
            $this->assertNull($sheet->getCell('L1')->getValue());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_legacy_service_autoretention_is_displayed_as_pending_retention(): void
    {
        $service = new CuentasPorCobrarService;
        $effectiveState = new \ReflectionMethod($service, 'effectivePaymentState');
        $effectiveState->setAccessible(true);
        $paymentState = new \ReflectionMethod($service, 'paymentState');
        $paymentState->setAccessible(true);

        $state = $effectiveState->invoke($service, 'Pendiente Autoretención', null, null);

        $this->assertSame('PENDIENTE_A_RETENCION', $state);
        $this->assertSame('Pendiente a Retención', $paymentState->invoke($service, $state, false));
    }

    public function test_service_export_distinguishes_paid_and_pending_detraccion(): void
    {
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->decimal('montoOriginal', 10, 2);
            $table->string('docReferencia')->nullable();
            $table->string('estado');
        });
        Schema::create('bancos', function (Blueprint $table): void {
            $table->increments('idbancos');
            $table->string('tipoMov');
            $table->decimal('monto', 10, 2);
            $table->string('informacion');
            $table->dateTime('fechaRegistro')->nullable();
        });
        Schema::create('detallecxc', function (Blueprint $table): void {
            $table->increments('iddetalleCXC');
            $table->integer('cuentasPorCobrar_idcuentasPorCobrar');
            $table->integer('bancos_idbancos');
            $table->string('descripcion');
            $table->dateTime('fechaPago')->nullable();
            $table->string('estado');
        });
        DB::table('cuentasporcobrar')->insert([
            ['idcuentasPorCobrar' => 11, 'montoOriginal' => 1000, 'docReferencia' => 'F001-001', 'estado' => 'Pendiente Autodetracción'],
            ['idcuentasPorCobrar' => 12, 'montoOriginal' => 1000, 'docReferencia' => 'F001-002', 'estado' => '3'],
            ['idcuentasPorCobrar' => 13, 'montoOriginal' => 1000, 'docReferencia' => 'F001-003', 'estado' => 'Pendiente a Retención'],
            ['idcuentasPorCobrar' => 14, 'montoOriginal' => 1000, 'docReferencia' => 'F001-004', 'estado' => '3'],
        ]);
        DB::table('bancos')->insert([
            ['idbancos' => 1, 'tipoMov' => 'I', 'monto' => 880, 'informacion' => 'Pago Servicio', 'fechaRegistro' => '2026-10-20 10:00:00'],
            ['idbancos' => 2, 'tipoMov' => 'I', 'monto' => 880, 'informacion' => 'Pago Servicio', 'fechaRegistro' => '2026-10-21 10:00:00'],
            ['idbancos' => 3, 'tipoMov' => 'T', 'monto' => 120, 'informacion' => 'Detracción pagada de CXC #12', 'fechaRegistro' => '2026-10-22 10:00:00'],
            ['idbancos' => 4, 'tipoMov' => 'I', 'monto' => 970, 'informacion' => 'Pago Servicio', 'fechaRegistro' => '2026-10-23 10:00:00'],
            ['idbancos' => 5, 'tipoMov' => 'I', 'monto' => 1000, 'informacion' => 'Pago Servicio', 'fechaRegistro' => '2026-10-24 10:00:00'],
            ['idbancos' => 6, 'tipoMov' => 'S', 'monto' => 120, 'informacion' => 'Autodetracción pagada de CXC #14', 'fechaRegistro' => '2026-10-25 10:00:00'],
        ]);
        DB::table('detallecxc')->insert([
            ['cuentasPorCobrar_idcuentasPorCobrar' => 11, 'bancos_idbancos' => 1, 'descripcion' => 'Obs: pago', 'fechaPago' => '2026-10-20 10:00:00', 'estado' => '1'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 11, 'bancos_idbancos' => 1, 'descripcion' => 'Detracción pendiente: 120.00', 'fechaPago' => null, 'estado' => '0'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 12, 'bancos_idbancos' => 2, 'descripcion' => 'Obs: pago', 'fechaPago' => '2026-10-21 10:00:00', 'estado' => '1'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 12, 'bancos_idbancos' => 3, 'descripcion' => 'Detracción aplicada', 'fechaPago' => '2026-10-22 10:00:00', 'estado' => '1'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 13, 'bancos_idbancos' => 4, 'descripcion' => 'Obs: pago', 'fechaPago' => '2026-10-23 10:00:00', 'estado' => '1'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 13, 'bancos_idbancos' => 4, 'descripcion' => 'Retención pendiente: 30.00', 'fechaPago' => null, 'estado' => '0'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 14, 'bancos_idbancos' => 5, 'descripcion' => 'Obs: pago', 'fechaPago' => '2026-10-24 10:00:00', 'estado' => '1'],
            ['cuentasPorCobrar_idcuentasPorCobrar' => 14, 'bancos_idbancos' => 6, 'descripcion' => 'Detracción aplicada', 'fechaPago' => '2026-10-25 10:00:00', 'estado' => '1'],
        ]);

        $controller = new CuentasPorCobrarController(
            new CxcService,
            new CuentasPorCobrarService,
            new CotizacionService
        );
        $export = new \ReflectionMethod($controller, 'exportServiceGroupsXlsx');
        $export->setAccessible(true);
        $response = $export->invoke($controller, [[
            'cliente_id' => '20100000000',
            'cliente_nombre' => 'Cliente de prueba',
            'fecha_fin' => '31/10/2026',
            'mes' => 'octubre',
            'moneda_simbolo' => 'S/',
            'cxc_ids' => [],
            'cxc_id' => 11,
            'tipos_servicios' => [[
                'servicio_nombre' => 'Servicio mensual',
                'cxc_ids' => [12],
            ]],
            'cxc_reversion_ids' => [14],
        ], [
            'cliente_id' => '20100000001',
            'cliente_nombre' => 'Cliente con retención',
            'fecha_fin' => '31/10/2026',
            'mes' => 'octubre',
            'moneda_simbolo' => 'S/',
            'es_retencion' => true,
            'cxc_ids' => [13],
            'tipos_servicios' => [],
        ]]);
        $path = $response->getFile()->getPathname();

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('S/ 1,000.00', $sheet->getCell('F2')->getValue());
            $this->assertSame('S/ 880.00', $sheet->getCell('G2')->getValue());
            $this->assertSame('S/ 120.00', $sheet->getCell('J2')->getValue());
            $this->assertNull($sheet->getCell('K2')->getValue());
            $this->assertSame('S/ 120.00', $sheet->getCell('H3')->getValue());
            $this->assertSame('22/10/2026', $sheet->getCell('I3')->getValue());
            $this->assertSame('F001-002', $sheet->getCell('E3')->getValue());
            $this->assertSame('S/ 1,000.00', $sheet->getCell('G4')->getValue());
            $this->assertSame('S/ 120.00', $sheet->getCell('J4')->getValue());
            $this->assertSame('25/10/2026', $sheet->getCell('K4')->getValue());
            $this->assertSame('RETENCION', $sheet->getCell('H7')->getValue());
            $this->assertSame('S/ 970.00', $sheet->getCell('G8')->getValue());
            $this->assertSame('S/ 30.00', $sheet->getCell('H8')->getValue());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
