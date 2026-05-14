<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\ErpPermission;
use App\Support\ErpAuthSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    private const DEFAULT_MODULES = ['personal', 'roles', 'usuarios', 'clientes', 'configuracion'];
    private const DEFAULT_ACTIONS = ['ver', 'crear', 'editar', 'eliminar'];
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 60;

    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('erp_auth')) {
            return redirect()->route('home');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'usuario' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $throttleKey = Str::lower($credentials['usuario']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $this->registrarEventoAuth(
                usuario: (string) $credentials['usuario'],
                accion: 'login',
                resultado: 'error',
                codigoHttp: 429,
                mensaje: 'Demasiados intentos de inicio de sesión.',
                request: $request
            );

            return back()
                ->withErrors(['usuario' => 'Demasiados intentos. Espera un minuto e intenta nuevamente.'])
                ->onlyInput('usuario');
        }

        $usuario = DB::table('usuario')
            ->where('usuario', $credentials['usuario'])
            ->first();

        if (!$usuario || !$this->passwordMatches($credentials['password'], (string) $usuario->clave)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            $this->registrarEventoAuth(
                usuario: (string) $credentials['usuario'],
                accion: 'login',
                resultado: 'error',
                codigoHttp: 401,
                mensaje: 'Credenciales inválidas.',
                request: $request
            );

            return back()
                ->withErrors(['usuario' => 'Las credenciales son incorrectas.'])
                ->onlyInput('usuario');
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();
        $request->session()->put('erp_auth', ErpAuthSession::calculateAuthForUser($usuario->usuario));

        $this->registrarEventoAuth(
            usuario: (string) $usuario->usuario,
            accion: 'login',
            resultado: 'success',
            codigoHttp: 302,
            mensaje: 'Inicio de sesión exitoso.',
            request: $request,
            personalDni: $usuario->personal_dniPersonal
        );

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);

        $this->registrarEventoAuth(
            usuario: (string) ($authData['usuario'] ?? 'anonimo'),
            accion: 'logout',
            resultado: 'success',
            codigoHttp: 302,
            mensaje: 'Cierre de sesión.',
            request: $request,
            personalDni: $authData['personal_dni'] ?? null
        );

        $request->session()->forget('erp_auth');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function getUserRoleRows(string $usuario): Collection
    {
        return DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->leftJoin('inforol as ir', 'r.idrol', '=', 'ir.rol_idrol')
            ->where('dr.usuario_usuario', $usuario)
            ->select('r.nombre', 'ir.modulo', 'ir.accion')
            ->get();
    }

    private function resolveRoles(Collection $roleRows): array
    {
        return $roleRows
            ->pluck('nombre')
            ->map(fn ($role): string => trim((string) $role))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isAdmin(array $roles): bool
    {
        return collect($roles)
            ->map(fn (string $role): string => mb_strtolower(trim($role)))
            ->contains('admin');
    }

    private function resolveModules(array $permissions, bool $isAdmin): Collection
    {
        if ($isAdmin) {
            return collect(self::DEFAULT_MODULES);
        }

        return collect(array_keys($permissions))
            ->map(fn ($permissionKey): ?string => ErpPermission::permissionKeyToModule((string) $permissionKey))
            ->filter()
            ->unique()
            ->values();
    }

    private function resolvePermissions(Collection $roleRows, bool $isAdmin): array
    {
        if ($isAdmin) {
            $permissions = collect(self::DEFAULT_MODULES)
                ->mapWithKeys(fn ($module): array => [$module => self::DEFAULT_ACTIONS])
                ->all();

            foreach (ErpPermission::allPermissionKeys() as $permissionKey) {
                $permissions[$permissionKey] = self::DEFAULT_ACTIONS;
                $parentModule = ErpPermission::permissionKeyToModule($permissionKey);
                if ($parentModule !== null) {
                    $permissions[$parentModule] = self::DEFAULT_ACTIONS;
                }
            }

            return $permissions;
        }

        $permissions = [];
        foreach ($roleRows as $row) {
            $action = ErpPermission::normalizeAction($row->accion ?? null);

            if ($action === null) {
                continue;
            }

            $permissionKeys = ErpPermission::expandPermissionKeys($row->modulo ?? null);
            foreach ($permissionKeys as $permissionKey) {
                $permissions[$permissionKey] ??= [];
                $permissions[$permissionKey][] = $action;

                $parentModule = ErpPermission::permissionKeyToModule($permissionKey);
                if ($parentModule !== null) {
                    $permissions[$parentModule] ??= [];
                    $permissions[$parentModule][] = $action;
                }
            }
        }

        return collect($permissions)
            ->map(fn ($actions): array => collect($actions)->unique()->values()->all())
            ->all();
    }

    private function passwordMatches(string $plainPassword, string $storedPassword): bool
    {
        if ($storedPassword === '') {
            return false;
        }

        return Hash::check($plainPassword, $storedPassword);
    }

    private function registrarEventoAuth(
        string $usuario,
        string $accion,
        string $resultado,
        int $codigoHttp,
        string $mensaje,
        Request $request,
        ?string $personalDni = null
    ): void {
        try {
            DB::table('auditoria')->insert([
                'usuario' => $usuario,
                'personal_dni' => $personalDni,
                'modulo' => 'auth',
                'accion' => $accion,
                'metodo_http' => strtoupper($request->method()),
                'ruta' => (string) $request->path(),
                'nombre_ruta' => (string) ($request->route()?->getName() ?? ''),
                'parametros' => json_encode($this->sanitizarDatos($request->all()), JSON_UNESCAPED_UNICODE),
                'ip_address' => (string) ($request->ip() ?? ''),
                'user_agent' => (string) ($request->userAgent() ?? ''),
                'resultado' => $resultado,
                'codigo_http' => $codigoHttp,
                'mensaje' => $mensaje,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            logger()->error('Audit auth insert failed', [
                'exception' => $exception,
                'route' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'user' => $usuario,
                'params' => $request->all(),
            ]);
            // No romper autenticación si falla auditoría.
        }
    }

    private function sanitizarDatos(array $data): array
    {
        $sensibles = ['password', 'clave', 'token', '_token', 'authorization', 'cookie'];
        $clean = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, $sensibles, true)) {
                $clean[$key] = '[OCULTO]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizarDatos($value);
                continue;
            }

            if (is_object($value)) {
                $clean[$key] = '[OBJETO]';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

}
