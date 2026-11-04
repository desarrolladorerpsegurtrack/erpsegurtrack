<?php

namespace App\Console\Commands;

use App\Services\CxcService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerarPeriodosCxc extends Command
{
    protected $signature = 'cxc:generar-periodos {--periodo= : Mes cuyos servicios vencen (formato YYYY-MM)}';

    protected $description = 'Genera los siguientes periodos de cuentas por cobrar según las fechas de cada servicio.';

    public function handle(CxcService $cxcService): int
    {
        try {
            $periodoSeleccionado = $this->option('periodo');
            $fechaPeriodo = $periodoSeleccionado !== null && $periodoSeleccionado !== ''
                ? $this->parsePeriod($periodoSeleccionado)
                : null;

            if ($periodoSeleccionado !== null && $periodoSeleccionado !== '' && $fechaPeriodo === null) {
                $this->error('El parámetro --periodo debe tener el formato YYYY-MM y ser una fecha válida.');

                return self::FAILURE;
            }

            if ($fechaPeriodo !== null) {
                $this->info(
                    'Periodo de referencia: '
                    .$fechaPeriodo->locale('es')->isoFormat('MMMM YYYY')
                    .'. Se generará el periodo siguiente para los servicios elegibles.'
                );
                $this->info('Esta ejecución manual no verificará vencimientos ni cambiará el estado de las CXC origen.');
            }

            $count = $cxcService->generarPeriodosAutomaticos(
                $fechaPeriodo,
                $fechaPeriodo === null
            );
            $this->info("Se generaron {$count} periodos de cuentas por cobrar.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function parsePeriod(string $period): ?Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1 || (int) substr($period, 0, 4) < 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m', $period);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date && $date->format('Y-m') === $period ? $date : null;
    }
}
