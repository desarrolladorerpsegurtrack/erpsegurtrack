<?php

namespace App\Console\Commands;

use App\Services\CxcService;
use Illuminate\Console\Command;

class ProcesarBajasProgramadas extends Command
{
    protected $signature = 'servicios:procesar-bajas-programadas';

    protected $description = 'Inactiva los servicios cuya baja fue programada para el fin de su periodo.';

    public function handle(CxcService $cxcService): int
    {
        try {
            $processed = $cxcService->procesarBajasProgramadas();
            $this->info("Se procesaron {$processed} bajas programadas.");
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('No se pudieron procesar las bajas programadas: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }
}
