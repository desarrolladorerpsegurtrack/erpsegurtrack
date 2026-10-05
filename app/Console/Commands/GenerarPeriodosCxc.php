<?php

namespace App\Console\Commands;

use App\Services\CxcService;
use Illuminate\Console\Command;

class GenerarPeriodosCxc extends Command
{
    protected $signature = 'cxc:generar-periodos';

    protected $description = 'Genera los siguientes periodos de cuentas por cobrar según las fechas de cada servicio.';

    public function handle(CxcService $cxcService): int
    {
        try {
            $count = $cxcService->generarPeriodosAutomaticos();
            $this->info("Se generaron {$count} periodos de cuentas por cobrar.");
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}