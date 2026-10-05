<?php

namespace App\Console\Commands;

use App\Support\ErpAuthSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NotificarServiciosPorVencer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notificar-servicios-por-vencer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notifica a los usuarios con permiso a Cuentas por Cobrar sobre servicios próximos a vencer (1 a 5 días).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Iniciando proceso de notificación de servicios por vencer...');

        // 1. Obtener servicios activos que están entre 1 y 5 días de vencer
        $today = Carbon::today();
        $limitDate = Carbon::today()->addDays(5);

        $servicios = DB::table('serviciocliente as sc')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'sc.cliente_idcliente')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->leftJoin('cuentasporcobrar as c', function ($join) {
                $join->on('c.cliente_idcliente', '=', 'sc.cliente_idcliente')
                    ->where(function ($match) {
                        $match->where(function ($document) {
                            $document->whereNotNull('c.docReferencia')
                                ->whereColumn('sc.docReferencia', 'c.docReferencia');
                        })->orWhere(function ($withoutDocument) {
                            $withoutDocument->whereNull('c.docReferencia')
                                ->where(function ($amountOrRenewal) {
                                    $amountOrRenewal->whereColumn('sc.monto', 'c.montoOriginal')
                                        ->orWhereRaw("c.descripcion LIKE CONCAT('%', DATE_FORMAT(sc.fechaInicio, '%d/%m/%Y'), ' a ', DATE_FORMAT(sc.fecheVencimiento, '%d/%m/%Y'), '%')");
                                });
                        });
                    });
            })
            ->select([
                'sc.idservicioCliente',
                'sc.cliente_idcliente',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'sc.vehiculo_placa',
                'sc.fechaInicio',
                'sc.fecheVencimiento',
                'sc.monto',
                'sc.estado',
                'sc.moneda_idmoneda as servicio_moneda_id',
                'c.idcuentasPorCobrar as cxc_id',
                DB::raw('COALESCE(a.detalle, "") as servicio_detalle'),
            ])
            ->whereRaw('LOWER(COALESCE(sc.estado, "")) = ?', ['activo'])
            ->whereNotNull('sc.fecheVencimiento')
            ->whereDate('sc.fecheVencimiento', '>=', $today->format('Y-m-d'))
            ->whereDate('sc.fecheVencimiento', '<=', $limitDate->format('Y-m-d'))
            ->orderBy('sc.fecheVencimiento')
            ->get();

        $this->removeInactiveServiceNotifications($servicios->pluck('idservicioCliente')->map(fn ($id) => (string) $id)->all());

        if ($servicios->isEmpty()) {
            $this->info('No se encontraron servicios próximos a vencer (1-5 días).');
            return \Symfony\Component\Console\Command\Command::SUCCESS;
        }

        $this->info("Se encontraron {$servicios->count()} servicios próximos a vencer.");

        // 2. Obtener todos los usuarios activos que tienen acceso al módulo Cuentas por Cobrar
        $activeUsernames = DB::table('usuario')
            ->where('estado', '1')
            ->pluck('usuario');

        $targetUsers = [];
        foreach ($activeUsernames as $username) {
            $authData = ErpAuthSession::calculateAuthForUser((string) $username);
            if (!$authData) {
                continue;
            }

            $roles = collect($authData['roles'] ?? [])->map(fn ($r) => mb_strtolower(trim((string) $r)));
            $isAdmin = $roles->contains('admin');
            $modules = $authData['modules'] ?? [];
            $permissions = $authData['permissions'] ?? [];

            if ($isAdmin || in_array('cuentasporcobrar', $modules, true) || isset($permissions['cuentasporcobrar'])) {
                $targetUsers[] = (string) $username;
            }
        }

        if (empty($targetUsers)) {
            $this->warn('No se encontraron usuarios con permiso a Cuentas por Cobrar.');
            return \Symfony\Component\Console\Command\Command::SUCCESS;
        }

        $this->info('Usuarios destinatarios: ' . implode(', ', $targetUsers));

        $countCreated = 0;
        $urlBase = route('modules.cuentasporcobrar') . '?tab=servicios';

        // 3. Crear una notificación por agrupación, no por vehículo o servicio.
        $grupos = $servicios->groupBy(function ($servicio): string {
            return implode('|', [
                (string) $servicio->cliente_idcliente,
                Carbon::parse($servicio->fechaInicio)->format('Y-m-d'),
                Carbon::parse($servicio->fecheVencimiento)->format('Y-m-d'),
                (string) ($servicio->servicio_moneda_id ?? ''),
            ]);
        });

        foreach ($grupos as $groupKey => $serviciosGrupo) {
            $servicio = $serviciosGrupo->first();
            $vencimiento = Carbon::parse($servicio->fecheVencimiento)->startOfDay();
            $diasRestantes = max(0, (int) $today->startOfDay()->diffInDays($vencimiento, false));

            if ($diasRestantes < 1 || $diasRestantes > 5) {
                continue;
            }

            $clienteNombre = trim((string) $servicio->cliente_nombre);
            $diaTexto = $diasRestantes === 1 ? '1 día' : "{$diasRestantes} días";
            $tiposServicio = $serviciosGrupo
                ->pluck('servicio_detalle')
                ->map(fn ($detalle) => trim((string) $detalle))
                ->filter()
                ->unique()
                ->values();
            $vehiculos = $serviciosGrupo
                ->pluck('vehiculo_placa')
                ->filter()
                ->unique()
                ->values();
            $cantidadServicios = $tiposServicio->count();
            $cantidadVehiculos = $vehiculos->count();
            $inicioTexto = Carbon::parse($servicio->fechaInicio)->format('d/m/Y');
            $finTexto = Carbon::parse($servicio->fecheVencimiento)->format('d/m/Y');
            $serviciosTexto = $cantidadServicios === 1 ? '1 tipo de servicio' : "{$cantidadServicios} tipos de servicio";
            $vehiculosTexto = $cantidadVehiculos === 1 ? '1 vehículo' : "{$cantidadVehiculos} vehículos";
            $mensaje = "El cliente \"{$clienteNombre}\" tiene un {$serviciosTexto} y {$vehiculosTexto}. Vence en {$diaTexto} ({$inicioTexto} - {$finTexto}).";
            $serviceIds = $serviciosGrupo->pluck('idservicioCliente')->map(fn ($id) => (string) $id)->values()->all();

            $datosPayload = [
                'titulo' => 'CUENTAS POR COBRAR',
                'mensaje' => $mensaje,
                'cliente' => $clienteNombre,
                'vehiculo' => $vehiculos->implode(', ') ?: '-',
                'dias_restantes' => $diasRestantes,
                'servicio_id' => $serviceIds[0] ?? null,
                'servicio_ids' => $serviceIds,
                'group_key' => $groupKey,
                'cxc_id' => $servicio->cxc_id ?? null,
                'url' => $urlBase,
            ];

            $datosJson = json_encode($datosPayload, JSON_UNESCAPED_UNICODE);

            foreach ($targetUsers as $username) {
                // Mantener una sola notificación por agrupación y usuario mientras siga pendiente.
                $existingNotification = DB::table('notificaciones')
                    ->where('notifiable_id', $username)
                    ->where('type', 'App\\Notifications\\ServicioPorVencerNotification')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.group_key')) = ?", [$groupKey])
                    ->first();

                if ($existingNotification) {
                    DB::table('notificaciones')
                        ->where('id', $existingNotification->id)
                        ->update([
                            'data' => $datosJson,
                            'updated_at' => Carbon::now(),
                        ]);
                    continue;
                }

                $notificationId = (string) Str::uuid();

                DB::table('notificaciones')->insert([
                    'id' => $notificationId,
                    'type' => 'App\\Notifications\\ServicioPorVencerNotification',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id' => $username,
                    'data' => $datosJson,
                    'read_at' => null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $countCreated++;

                // Emitir evento vía WebSocket si está disponible
                $this->notifyWebSocket($username, [
                    'id' => $notificationId,
                    'message' => $mensaje,
                    'url' => $urlBase,
                    'cliente' => $clienteNombre,
                    'dias_restantes' => $diasRestantes,
                    'group_key' => $groupKey,
                ]);
            }
        }

        $this->info("Se crearon {$countCreated} nuevas notificaciones exitosamente.");

        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    private function removeInactiveServiceNotifications(array $activeServiceIds): void
    {
        $activeIds = array_fill_keys($activeServiceIds, true);
        $rows = DB::table('notificaciones')
            ->where('type', 'App\\Notifications\\ServicioPorVencerNotification')
            ->select(['id', 'data'])
            ->get();

        $staleIds = $rows
            ->filter(function ($row) use ($activeIds): bool {
                $data = is_string($row->data) ? json_decode($row->data, true) : (array) $row->data;
                if (empty($data['group_key'])) {
                    return true;
                }

                $serviceIds = array_map('strval', (array) ($data['servicio_ids'] ?? []));
                if ($serviceIds === [] && isset($data['servicio_id'])) {
                    $serviceIds = [(string) $data['servicio_id']];
                }

                return $serviceIds === [] || !collect($serviceIds)->contains(fn ($id) => isset($activeIds[$id]));
            })
            ->pluck('id')
            ->values()
            ->all();

        if ($staleIds !== []) {
            DB::table('notificaciones')->whereIn('id', $staleIds)->delete();
        }
    }

    private function notifyWebSocket(string $username, array $payload): void
    {
        try {
            $wsUrl = config('services.websocket.url', env('WS_SERVER_URL', 'http://192.168.1.72:6001')) . '/publish';
            Http::timeout(1)->post($wsUrl, [
                'type' => 'notification.created',
                'resource' => 'notification',
                'id' => $payload['id'],
                'usuario' => $username,
                'action' => 'created',
                'meta' => $payload,
            ]);
        } catch (\Throwable $e) {
            // Ignorar errores de websocket en consola silenciosamente
        }
    }
}
