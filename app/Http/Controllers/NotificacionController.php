<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificacionController extends Controller
{
    public const PREFERENCE_NOTIFICATION_TYPE = 'App\\Notifications\\UserNotificationPreference';

    public static function notificationsMutedFor(string $usuario): bool
    {
        $preference = DB::table('notificaciones')
            ->where('notifiable_id', $usuario)
            ->where('type', self::PREFERENCE_NOTIFICATION_TYPE)
            ->value('data');

        if (!$preference) {
            return false;
        }

        $data = is_string($preference) ? json_decode($preference, true) : (array) $preference;

        return (bool) ($data['muted'] ?? false);
    }

    /**
     * Obtener las notificaciones no leídas del usuario en sesión.
     */
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario');

        if (!$usuario) {
            return response()->json(['success' => false, 'count' => 0, 'items' => []], 401);
        }

        $rows = DB::table('notificaciones')
            ->where('notifiable_id', $usuario)
            ->where('type', '!=', self::PREFERENCE_NOTIFICATION_TYPE)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $activeServiceIds = DB::table('serviciocliente')
            ->whereRaw('LOWER(COALESCE(estado, "")) = ?', ['activo'])
            ->whereNotNull('fecheVencimiento')
            ->whereDate('fecheVencimiento', '>=', Carbon::today()->format('Y-m-d'))
            ->whereDate('fecheVencimiento', '<=', Carbon::today()->addDays(5)->format('Y-m-d'))
            ->pluck('idservicioCliente')
            ->map(fn ($id) => (string) $id)
            ->all();

        $activeServiceIds = array_fill_keys($activeServiceIds, true);
        $rows = $rows->filter(function ($row) use ($activeServiceIds): bool {
            if ($row->type !== 'App\\Notifications\\ServicioPorVencerNotification') {
                return true;
            }

            $data = is_string($row->data) ? json_decode($row->data, true) : (array) $row->data;
            $serviceIds = array_map('strval', (array) ($data['servicio_ids'] ?? []));
            if ($serviceIds === [] && isset($data['servicio_id'])) {
                $serviceIds = [(string) $data['servicio_id']];
            }

            return collect($serviceIds)->contains(fn ($id) => isset($activeServiceIds[$id]));
        })->values();

        $formatted = $rows->map(function ($row) {
            $datos = is_string($row->data) ? json_decode($row->data, true) : (array) $row->data;
            $createdAt = $row->created_at ? Carbon::parse($row->created_at) : null;

            return [
                'id' => $row->id,
                'titulo' => $datos['titulo'] ?? 'Notificación',
                'mensaje' => $datos['mensaje'] ?? '',
                'cliente' => $datos['cliente'] ?? '',
                'vehiculo' => $datos['vehiculo'] ?? '',
                'dias_restantes' => $datos['dias_restantes'] ?? null,
                'servicio_id' => $datos['servicio_id'] ?? null,
                'servicio_ids' => $datos['servicio_ids'] ?? [],
                'group_key' => $datos['group_key'] ?? null,
                'cxc_id' => $datos['cxc_id'] ?? null,
                'url' => $datos['url'] ?? route('modules.cuentasporcobrar', ['tab' => 'servicios']),
                'fecha_display' => $createdAt ? $createdAt->locale('es')->diffForHumans() : '',
                'fecha_completa' => $createdAt ? $createdAt->format('d/m/Y H:i') : '',
            ];
        });

        return response()->json([
            'success' => true,
            'count' => $formatted->count(),
            'items' => $formatted,
            'muted' => self::notificationsMutedFor($usuario),
        ]);
    }

    /**
     * Mantener la alerta pendiente al abrirla; se elimina cuando el servicio deja de estar pendiente.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario');

        if (!$usuario) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Marcar todas las notificaciones como leídas.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario');

        if (!$usuario) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Guardar la preferencia individual para mostrar alertas emergentes.
     */
    public function updatePreference(Request $request): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario');

        if (!$usuario) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $validated = $request->validate([
            'muted' => ['required', 'boolean'],
        ]);
        $now = Carbon::now();

        $preference = DB::table('notificaciones')
            ->where('notifiable_id', $usuario)
            ->where('type', self::PREFERENCE_NOTIFICATION_TYPE)
            ->first();
        $data = json_encode(['muted' => (bool) $validated['muted']], JSON_THROW_ON_ERROR);

        if ($preference) {
            DB::table('notificaciones')
                ->where('id', $preference->id)
                ->update([
                    'data' => $data,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('notificaciones')->insert([
                'id' => (string) Str::uuid(),
                'type' => self::PREFERENCE_NOTIFICATION_TYPE,
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $usuario,
                'data' => $data,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json([
            'success' => true,
            'muted' => (bool) $validated['muted'],
        ]);
    }

    /**
     * Ejecutar el comando de notificaciones manualmente para pruebas.
     */
    public function triggerCron(): JsonResponse
    {
        Artisan::call('app:notificar-servicios-por-vencer');
        $output = Artisan::output();

        return response()->json([
            'success' => true,
            'message' => 'Comando ejecutado correctamente',
            'output' => $output,
        ]);
    }
}
