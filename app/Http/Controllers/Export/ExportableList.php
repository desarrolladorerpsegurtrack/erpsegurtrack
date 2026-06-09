<?php

namespace App\Http\Controllers\Export;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait ExportableList
{
   

    protected function exportXlsxResponse($rows, array $columns, string $filename, array $summary = [])
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $rowIndex = 1;
        if (!empty($summary)) {
            $chunks = array_chunk($summary, 3, true);
            foreach ($chunks as $chunk) {
                $columnIndex = 1;
                foreach ($chunk as $label => $value) {
                    $coordinate = Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
                    $sheet->setCellValueExplicit(
                        $coordinate,
                        (string) $label,
                        DataType::TYPE_STRING
                    );
                    $columnIndex++;
                }

                $rowIndex++;
                $columnIndex = 1;
                foreach ($chunk as $label => $value) {
                    $coordinate = Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
                    $sheet->setCellValueExplicit(
                        $coordinate,
                        (string) $value,
                        DataType::TYPE_STRING
                    );
                    $columnIndex++;
                }

                $rowIndex++;
            }
            $rowIndex++;
        }

        $headerRow = $rowIndex;
        foreach ($columns as $columnIndex => $column) {
            $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1) . $rowIndex;
            $sheet->setCellValueExplicit(
                $coordinate,
                $column['label'] ?? '',
                DataType::TYPE_STRING
            );
        }

        $rowIndex++;
        $dataStartRow = $rowIndex;
        foreach ($rows as $row) {
            $columnIndex = 1;
            foreach ($columns as $column) {
                if (isset($column['value']) && is_callable($column['value'])) {
                    $value = $column['value']($row);
                } else {
                    $value = data_get($row, $column['key']);
                }

                $value = is_string($value) ? trim(strip_tags($value)) : $value;
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
                $sheet->setCellValueExplicit(
                    $coordinate,
                    (string) $value,
                    DataType::TYPE_STRING
                );

                $columnIndex++;
            }
            $rowIndex++;
        }

        $dataEndRow = $rowIndex - 1;

        // Aplicar diseño común para unificar la apariencia del XLSX
        $this->applyCommonXlsxDesign($spreadsheet, $sheet, $columns, $headerRow, $dataStartRow, $dataEndRow);

        foreach ($columns as $columnIndex => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $dimension = $sheet->getColumnDimension($columnLetter);

            if (isset($column['width']) && is_numeric($column['width'])) {
                $dimension->setAutoSize(false);
                $dimension->setWidth((float) $column['width']);
            } else {
                $dimension->setAutoSize(true);
            }

            if (!empty($column['wrap'])) {
                $styleStartRow = $dataEndRow >= $dataStartRow ? $dataStartRow : $headerRow;
                $styleEndRow = $dataEndRow >= $dataStartRow ? $dataEndRow : $headerRow;
                $range = $columnLetter . $styleStartRow . ':' . $columnLetter . $styleEndRow;
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
                $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_xlsx_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Aplica estilos y ajustes comunes al XLSX para unificar el diseño.
     */
    protected function applyCommonXlsxDesign(Spreadsheet $spreadsheet, Worksheet $sheet, array $columns, int $headerRow, int $dataStartRow, int $dataEndRow)
    {
        // Column widths, header style, wrap and borders
        foreach ($columns as $columnIndex => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $dimension = $sheet->getColumnDimension($columnLetter);

            if (isset($column['width']) && is_numeric($column['width'])) {
                $dimension->setAutoSize(false);
                $dimension->setWidth((float) $column['width']);
            } else {
                $dimension->setAutoSize(true);
            }

            // Header cell style
            $headerCell = $columnLetter . $headerRow;
            $sheet->getStyle($headerCell)->getFont()->setBold(true);
            $sheet->getStyle($headerCell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
            $sheet->getStyle($headerCell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle($headerCell)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            // Wrap text for data rows if requested
            if (!empty($column['wrap'])) {
                $styleStartRow = $dataEndRow >= $dataStartRow ? $dataStartRow : $headerRow;
                $styleEndRow = $dataEndRow >= $dataStartRow ? $dataEndRow : $headerRow;
                $range = $columnLetter . $styleStartRow . ':' . $columnLetter . $styleEndRow;
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
                $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            }
        }

        // Apply thin borders to the whole table (header + data)
        $tableStart = Coordinate::stringFromColumnIndex(1) . $headerRow;
        $tableEndCol = Coordinate::stringFromColumnIndex(count($columns));
        $tableEndRow = $dataEndRow >= $dataStartRow ? $dataEndRow : $headerRow;
        $fullRange = $tableStart . ':' . $tableEndCol . $tableEndRow;
        $sheet->getStyle($fullRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Freeze top row for readability
        $sheet->freezePane('A2');
    }

    protected function exportPdfResponseTable($rows, array $columns, string $title, string $filename)
    {
        return $this->exportPdfResponse($rows, $columns, $title, $filename);
    }

    protected function exportPdfResponseModal($rows, array $columns, string $title, string $filename)
    {
        $pdf = Pdf::loadView('layouts.export-modal', [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
        ]);

        return $pdf->download($filename);
    }

    protected function exportPdfResponse($rows, array $columns, string $title, string $filename)
    {
        $pdf = Pdf::loadView('layouts.export-table', [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
        ]);

        return $pdf->download($filename);
    }

    /**
     * Exportación PDF para selección agrupada por cliente (cliente -> servicios -> vehículos -> dispositivos)
     * Recibe un array de grupos: [ ['cliente' => object, 'servicios' => [], 'vehiculos' => [], 'dispositivos' => []], ... ]
     */
    protected function exportSelectedPdfResponse(array $groups, string $filename)
    {
        $pdf = Pdf::loadView('layouts.export-selected-clients', [
            'groups' => $groups,
        ]);

        return $pdf->download($filename);
    }

    /**
     * Exportación XLSX para selección agrupada por cliente.
     * La estructura de $groups es la misma que para PDF.
     */
    protected function exportSelectedXlsxResponse(array $groups, string $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(42);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(16);

        $rowIndex = 1;
        foreach ($groups as $group) {
            $cliente = $group['cliente'] ?? null;

            if ($cliente) {
                // Cliente title merged row (A..G)
                $sheet->mergeCells("A{$rowIndex}:G{$rowIndex}");
                $sheet->setCellValueExplicit('A' . $rowIndex, 'Cliente: ' . (string) ($cliente->idcliente ?? '') . ' - ' . (string) ($cliente->nombreComercial ?? ''), DataType::TYPE_STRING);
                $sheet->getStyle("A{$rowIndex}:G{$rowIndex}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowIndex}:G{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1E6');
                $rowIndex++;

                // Client detail headers (remove empty placeholder so Dirección sits next to Rubro)
                $clientHeaders = ['RUC/DNI', 'Nombre Comercial', 'Razón Social', 'Grupo Asignado', 'Rubro', 'Dirección', 'Estado'];
                $col = 'A';
                foreach ($clientHeaders as $idx => $label) {
                    $sheet->setCellValueExplicit($col . $rowIndex, $label, DataType::TYPE_STRING);
                    $sheet->getStyle($col . $rowIndex)->getFont()->setBold(true);
                    $sheet->getStyle($col . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFEFEF');
                    $col++;
                }
                $rowIndex++;

                // Client values
                $sheet->setCellValueExplicit('A' . $rowIndex, (string) ($cliente->idcliente ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B' . $rowIndex, (string) ($cliente->nombreComercial ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C' . $rowIndex, (string) ($cliente->razonSocial ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D' . $rowIndex, (string) ($cliente->grupo_asignado ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('E' . $rowIndex, (string) ($cliente->rubro ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('F' . $rowIndex, (string) ($cliente->direccion_completa ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('G' . $rowIndex, (string) ($cliente->estadoDetalle ?? ''), DataType::TYPE_STRING);

                // Wrap address cell (now column F)
                $sheet->getStyle('F' . $rowIndex)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

                // border for client detail row (A..G)
                $sheet->getStyle("A{$rowIndex}:G{$rowIndex}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                $rowIndex += 2;
            }

            // Servicios
            if (!empty($group['servicios'])) {
                // servicios header
                $sheet->setCellValueExplicit('A' . $rowIndex, 'Servicios', DataType::TYPE_STRING);
                $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
                $rowIndex++;

                $headers = ['ID Servicio', 'Placa Vehículo', 'Almacén', 'Fecha Inicio', 'Fecha Vencimiento', 'Monto', 'Estado Servicio', 'Documento Referencia'];
                $colLetter = 'A';
                foreach ($headers as $h) {
                    $sheet->setCellValueExplicit($colLetter . $rowIndex, $h, DataType::TYPE_STRING);
                    $sheet->getStyle($colLetter . $rowIndex)->getFont()->setBold(true);
                    $sheet->getStyle($colLetter . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
                    $colLetter++;
                }
                $rowIndex++;

                foreach ($group['servicios'] as $serv) {
                    $sheet->setCellValueExplicit('A' . $rowIndex, (string) ($serv->idservicioCliente ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $rowIndex, (string) ($serv->vehiculo_placa ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $rowIndex, (string) ($serv->almacen_detalle ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $rowIndex, (string) ($serv->fechaInicio ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $rowIndex, (string) ($serv->fecheVencimiento ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $rowIndex, (string) ($serv->monto ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $rowIndex, (string) ($serv->estado ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $rowIndex, (string) ($serv->docReferencia ?? ''), DataType::TYPE_STRING);
                    $sheet->getStyle("A{$rowIndex}:H{$rowIndex}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOTTED);
                    $rowIndex++;
                }

                $rowIndex++;
            }

            // Vehiculos
            if (!empty($group['vehiculos'])) {
                $sheet->setCellValueExplicit('A' . $rowIndex, 'Vehículos', DataType::TYPE_STRING);
                $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
                $rowIndex++;

                $vHeaders = ['Placa', 'Tipo Vehículo', 'Año', 'Marca', 'Modelo', 'Color', 'Tracto'];
                $col = 'A';
                foreach ($vHeaders as $h) {
                    $sheet->setCellValueExplicit($col . $rowIndex, $h, DataType::TYPE_STRING);
                    $sheet->getStyle($col . $rowIndex)->getFont()->setBold(true);
                    $sheet->getStyle($col . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
                    $col++;
                }
                $rowIndex++;

                foreach ($group['vehiculos'] as $veh) {
                    $sheet->setCellValueExplicit('A' . $rowIndex, (string) ($veh->placa ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $rowIndex, (string) ($veh->tipo_vehiculo ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $rowIndex, (string) ($veh->anio ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $rowIndex, (string) ($veh->marca ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $rowIndex, (string) ($veh->modelo ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $rowIndex, (string) ($veh->color ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $rowIndex, (string) ($veh->tracto ?? ''), DataType::TYPE_STRING);
                    $sheet->getStyle("A{$rowIndex}:G{$rowIndex}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOTTED);
                    $rowIndex++;
                }

                $rowIndex++;
            }

            // Dispositivos
            if (!empty($group['dispositivos'])) {
                $sheet->setCellValueExplicit('A' . $rowIndex, 'Dispositivos', DataType::TYPE_STRING);
                $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
                $rowIndex++;

                $dHeaders = ['ID Dispositivo', 'Placa Vehículo', 'Marca Dispositivo', 'Modelo Dispositivo', 'Fecha Instalación', 'Fecha Baja', 'Estado Dispositivo'];
                $col = 'A';
                foreach ($dHeaders as $h) {
                    $sheet->setCellValueExplicit($col . $rowIndex, $h, DataType::TYPE_STRING);
                    $sheet->getStyle($col . $rowIndex)->getFont()->setBold(true);
                    $sheet->getStyle($col . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
                    $col++;
                }
                $rowIndex++;

                foreach ($group['dispositivos'] as $d) {
                    $sheet->setCellValueExplicit('A' . $rowIndex, (string) ($d->iddispositivoCliente ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $rowIndex, (string) ($d->vehiculo_placa ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('C' . $rowIndex, (string) ($d->marcaDispositivo ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('D' . $rowIndex, (string) ($d->modeloDispositivo ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('E' . $rowIndex, (string) ($d->fechaInstalacion ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('F' . $rowIndex, (string) ($d->fechaBaja ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('G' . $rowIndex, (string) ($d->estado ?? ''), DataType::TYPE_STRING);
                    $sheet->getStyle("A{$rowIndex}:G{$rowIndex}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOTTED);
                    $rowIndex++;
                }

                $rowIndex++;
            }

            // espacio entre clientes
            $rowIndex += 1;
        }

        // Freeze top row for readability
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_xlsx_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }
}
