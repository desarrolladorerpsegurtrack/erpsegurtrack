<?php

namespace App\Http\Controllers\Export;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

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
}
