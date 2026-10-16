<?php

namespace App\Console\Commands;

use App\Services\CxcService;
use Illuminate\Console\Command;

class RegistrarCxcFaltantesServicios extends Command
{
    protected $signature = 'cxc:registrar-faltantes-servicios
        {--execute : Crear las cuentas por cobrar faltantes}
        {--service-id= : Procesar solo el servicio indicado}';

    protected $description = 'Detecta o registra CxC iniciales faltantes de servicios activos con fechas.';

    public function handle(CxcService $cxcService): int
    {
        $serviceId = $this->option('service-id');
        if ($serviceId !== null && (!ctype_digit((string) $serviceId) || (int) $serviceId <= 0)) {
            $this->error('El parámetro --service-id debe ser un entero positivo.');
            return self::FAILURE;
        }

        try {
            $execute = (bool) $this->option('execute');
            $summary = $cxcService->registrarCxcsFaltantesServicios(
                $serviceId !== null ? (int) $serviceId : null,
                $execute
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('No se pudieron revisar los servicios: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Servicios activos con fechas revisados: ' . $summary['eligible']);
        if ($summary['skipped_reason'] !== null) {
            $this->warn($summary['skipped_reason']);
        }
        $this->info('CxC ya existentes: ' . $summary['existing']);
        $this->info('CxC ambiguas no procesadas: ' . $summary['ambiguous']);

        if ($execute) {
            $this->info('CxC iniciales creadas: ' . $summary['created']);
            $this->info('CxC existentes actualizadas: ' . $summary['updated']);
        } else {
            $this->warn('Modo de revisión: no se insertó ningún registro. Use --execute para confirmar.');
            $this->info('CxC que se crearían: ' . $summary['to_create']);
            $this->info('CxC existentes que se actualizarían: ' . $summary['to_update']);
            $this->info('Servicios candidatos: ' . ($summary['service_ids'] === [] ? 'ninguno' : implode(', ', $summary['service_ids'])));
        }

        if ($summary['ambiguous'] > 0) {
            $this->warn('Revisar manualmente los servicios ambiguos: ' . implode(', ', $summary['ambiguous_service_ids']));
        }

        return self::SUCCESS;
    }
}
