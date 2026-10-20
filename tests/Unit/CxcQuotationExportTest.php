<?php

namespace Tests\Unit;

use App\Http\Controllers\CuentasPorCobrarController;
use App\Services\CotizacionService;
use App\Services\CuentasPorCobrarService;
use App\Services\CxcService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CxcQuotationExportTest extends TestCase
{
    public function test_xlsx_quotation_export_keeps_autodetraccion_without_autoretencion(): void
    {
        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );
        $export = new \ReflectionMethod($controller, 'exportCustomQuotationsXlsxResponse');
        $export->setAccessible(true);
        $response = $export->invoke($controller, [
            'detracciones' => [[
                'cliente' => 'Cliente detracción',
                'ruc' => '20100000000',
                'fecha_emision' => '20/10/2026',
                'nro_factura' => 'F001-001',
                'total' => 'S/ 100.00',
                'pago_realizado' => 'S/ 88.00',
                'observacion' => '',
                'detraccion' => '',
                'fecha_abono_detraccion' => '',
                'autodetraccion' => 'S/ 12.00',
                'fecha_abono_autodetraccion' => '20/10/2026',
            ]],
            'retenciones' => [[
                'cliente' => 'Cliente retención',
                'ruc' => '20100000001',
                'fecha_emision' => '21/10/2026',
                'nro_factura' => 'F001-002',
                'total' => 'S/ 100.00',
                'pago_realizado' => 'S/ 97.00',
                'observacion' => '',
                'retencion' => 'S/ 3.00',
                'fecha_abono_retencion' => '21/10/2026',
            ]],
        ], 'cotizaciones.xlsx');
        $path = $response->getFile()->getPathname();

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('AUTODETRACCION', $sheet->getCell('J1')->getValue());
            $this->assertSame('FECHA ABONO', $sheet->getCell('K1')->getValue());
            $this->assertSame('S/ 12.00', $sheet->getCell('J2')->getValue());
            $this->assertSame('20/10/2026', $sheet->getCell('K2')->getValue());
            $this->assertSame('A8E08C', $sheet->getStyle('J1')->getFill()->getStartColor()->getRGB());
            $this->assertSame('A8E08C', $sheet->getStyle('K2')->getFill()->getStartColor()->getRGB());
            $this->assertSame('RETENCION', $sheet->getCell('H5')->getValue());
            $this->assertSame('FECHA ABONO', $sheet->getCell('I5')->getValue());
            $this->assertNull($sheet->getCell('J5')->getValue());
            $this->assertNull($sheet->getCell('J6')->getValue());
            $this->assertNull($sheet->getCell('K5')->getValue());
            $this->assertNull($sheet->getCell('K6')->getValue());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_quotation_deduction_keeps_detraccion_and_optional_retention_rates_separate(): void
    {
        $controller = new CuentasPorCobrarController(
            new CxcService(),
            new CuentasPorCobrarService(),
            new CotizacionService()
        );
        $breakdown = new \ReflectionMethod($controller, 'quotationWithholdingBreakdown');
        $breakdown->setAccessible(true);

        $detraccion = $breakdown->invoke(null, 720.0, true, false);
        $this->assertSame(86.0, $detraccion['deduction']);
        $this->assertSame(634.0, $detraccion['net']);
        $this->assertFalse($detraccion['is_retention']);

        $retention = $breakdown->invoke(null, 720.0, true, true);
        $this->assertSame(22.0, $retention['deduction']);
        $this->assertSame(698.0, $retention['net']);
        $this->assertTrue($retention['is_retention']);

        $noDeduction = $breakdown->invoke(null, 699.0, false, false);
        $this->assertSame(0.0, $noDeduction['deduction']);
        $this->assertSame(699.0, $noDeduction['net']);
    }
}
