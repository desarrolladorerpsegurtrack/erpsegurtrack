<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RelationContextController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home');
Route::get('/api/consultar-documento', [\App\Http\Controllers\ConsultaDocumentoController::class, 'consultar'])->name('api.consultar.documento');
Route::get('/api/consultar-placa', [\App\Http\Controllers\VehiculosController::class, 'consultarPlaca'])->name('api.consultar.placa');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

Route::middleware(['erp.auth', 'audit.log', 'erp.action'])->group(function () {
    Route::get('/', function (\Illuminate\Http\Request $request) {
        $authData = $request->session()->get('erp_auth', []);
        $permissions = collect($authData['permissions'] ?? []);
        $roles = collect($authData['roles'] ?? [])->map(fn ($role) => mb_strtolower(trim((string) $role)))->filter();

        if ($roles->contains('admin') || collect($permissions->get('inicio', []))->contains('ver')) {
            return view('home');
        }

        $routeName = \App\Support\ErpPermission::getDefaultRedirectRoute($authData);
        if ($routeName !== 'home') {
            return redirect()->route($routeName);
        }

        abort(403, 'No tienes acceso a ningún módulo.');
    })->name('home');

    require __DIR__.'/locks.php';
    require __DIR__.'/personal.php';
    require __DIR__.'/roles.php';
    require __DIR__.'/usuarios.php';
    require __DIR__.'/clientes.php';
    require __DIR__.'/cuentas-por-cobrar.php';
    require __DIR__.'/planificacion.php';
    require __DIR__.'/vehiculos.php';
    require __DIR__.'/almacen.php';
    require __DIR__.'/ventas.php';
    require __DIR__.'/dispositivo-cliente.php';
    require __DIR__.'/servicio-cliente.php';
    require __DIR__.'/configuracion.php';
    require __DIR__.'/sistema.php';
    require __DIR__.'/lineas-chips.php';
    require __DIR__.'/tickets.php';

    Route::get('/relaciones/{resource}/{id}', [RelationContextController::class, 'show'])->name('modules.relations.summary');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
