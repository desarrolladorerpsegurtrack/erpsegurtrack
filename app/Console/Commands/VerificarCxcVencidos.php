<?php

namespace App\Console\Commands;

use App\Services\CxcService;
use Illuminate\Console\Command;

class VerificarCxcVencidos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cxc:verificar-vencidos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evalúa las fechas límite de cuentas por cobrar en PENDIENTE y actualiza su estado a VENCIDO si expiraron.';

    /**
     * Execute the console command.
     */
    public function handle(CxcService $cxcService): int
    {
        $this->info('Iniciando verificación de Cuentas por Cobrar vencidas...');

        try {
            $count = $cxcService->verificarYActualizarVencidos();
            $this->info("Verificación completada. Se actualizaron {$count} cuentas a estado 'VENCIDO'.");
        } catch (\Throwable $e) {
            $this->error('Ocurrió un error al verificar vencidos: ' . $e->getMessage());
            return \Symfony\Component\Console\Command\Command::FAILURE;
        }

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
