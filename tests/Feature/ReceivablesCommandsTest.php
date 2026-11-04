<?php

namespace Tests\Feature;

use App\Services\CxcService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

class ReceivablesCommandsTest extends TestCase
{
    public function test_generar_periodos_command_keeps_its_current_month_default(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generarPeriodosAutomaticos')
                ->once()
                ->with(null, true)
                ->andReturn(2);
        });

        $this->artisan('cxc:generar-periodos')
            ->expectsOutput('Se generaron 2 periodos de cuentas por cobrar.')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_generar_periodos_command_accepts_a_historical_reference_month(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        try {
            $this->mock(CxcService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('generarPeriodosAutomaticos')
                    ->once()
                    ->withArgs(static function (?Carbon $period, bool $verificarVencidos): bool {
                        return $period?->format('Y-m-d') === '2026-10-01'
                            && !$verificarVencidos;
                    })
                    ->andReturn(12);
            });

            $this->artisan('cxc:generar-periodos', ['--periodo' => '2026-10'])
                ->expectsOutput('Periodo de referencia: octubre 2026. Se generará el periodo siguiente para los servicios elegibles.')
                ->expectsOutput('Esta ejecución manual no verificará vencimientos ni cambiará el estado de las CXC origen.')
                ->expectsOutput('Se generaron 12 periodos de cuentas por cobrar.')
                ->assertExitCode(Command::SUCCESS);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_generar_periodos_command_rejects_an_invalid_reference_month(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generarPeriodosAutomaticos');
        });

        $this->artisan('cxc:generar-periodos', ['--periodo' => '2026-13'])
            ->expectsOutput('El parámetro --periodo debe tener el formato YYYY-MM y ser una fecha válida.')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_verificar_vencidos_command_reports_the_updated_count(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verificarYActualizarVencidos')
                ->once()
                ->andReturn(3);
        });

        $this->artisan('cxc:verificar-vencidos')
            ->expectsOutput('Iniciando verificación de Cuentas por Cobrar vencidas...')
            ->expectsOutput("Verificación completada. Se actualizaron 3 cuentas a estado 'VENCIDO'.")
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_verificar_vencidos_command_reports_service_errors(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verificarYActualizarVencidos')
                ->once()
                ->andThrow(new \RuntimeException('fallo de base de datos'));
        });

        $this->artisan('cxc:verificar-vencidos')
            ->expectsOutput('Ocurrió un error al verificar vencidos: fallo de base de datos')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_registrar_faltantes_command_rejects_non_positive_service_ids(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('registrarCxcsFaltantesServicios');
        });

        $this->artisan('cxc:registrar-faltantes-servicios', ['--service-id' => '0'])
            ->expectsOutput('El parámetro --service-id debe ser un entero positivo.')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_registrar_faltantes_command_reports_preview_without_executing(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('registrarCxcsFaltantesServicios')
                ->once()
                ->with(null, false)
                ->andReturn($this->summary());
        });

        $this->artisan('cxc:registrar-faltantes-servicios')
            ->expectsOutput('Servicios activos con fechas revisados: 2')
            ->expectsOutput('CxC ya existentes: 1')
            ->expectsOutput('CxC ambiguas no procesadas: 0')
            ->expectsOutput('Modo de revisión: no se insertó ningún registro. Use --execute para confirmar.')
            ->expectsOutput('CxC que se crearían: 1')
            ->expectsOutput('CxC existentes que se actualizarían: 0')
            ->expectsOutput('Servicios candidatos: 41')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_registrar_faltantes_command_executes_for_a_selected_service(): void
    {
        $summary = $this->summary();
        $summary['created'] = 1;

        $this->mock(CxcService::class, function (MockInterface $mock) use ($summary): void {
            $mock->shouldReceive('registrarCxcsFaltantesServicios')
                ->once()
                ->with(41, true)
                ->andReturn($summary);
        });

        $this->artisan('cxc:registrar-faltantes-servicios', [
            '--service-id' => '41',
            '--execute' => true,
        ])
            ->expectsOutput('CxC iniciales creadas: 1')
            ->expectsOutput('CxC existentes actualizadas: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_registrar_faltantes_command_reports_service_errors(): void
    {
        $this->mock(CxcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('registrarCxcsFaltantesServicios')
                ->once()
                ->andThrow(new \RuntimeException('servicio no disponible'));
        });

        $this->artisan('cxc:registrar-faltantes-servicios')
            ->expectsOutput('No se pudieron revisar los servicios: servicio no disponible')
            ->assertExitCode(Command::FAILURE);
    }

    private function summary(): array
    {
        return [
            'eligible' => 2,
            'skipped_reason' => null,
            'existing' => 1,
            'ambiguous' => 0,
            'created' => 0,
            'updated' => 0,
            'to_create' => 1,
            'to_update' => 0,
            'service_ids' => [41],
            'ambiguous_service_ids' => [],
        ];
    }
}
