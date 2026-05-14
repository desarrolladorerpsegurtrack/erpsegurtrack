<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuariosRoleTypeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_internal_role_when_no_precreated_role_is_selected(): void
    {
        $this->loginAsAdmin();
        $this->seedPersonal('70010001');

        $response = $this->post(route('modules.usuarios.store'), [
            'usuario' => 'custom.user',
            'personal_dniPersonal' => '70010001',
            'clave' => 'ClaveSegura1!',
            'estado' => '1',
            'permissions' => [
                'usuarios' => [
                    'ver' => '1',
                ],
                'personal' => [
                    'ver' => '1',
                ],
            ],
        ]);

        $response->assertRedirect(route('modules.usuarios'));

        $assignedRole = DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->where('dr.usuario_usuario', 'custom.user')
            ->select('r.idrol', 'r.tipo')
            ->first();

        $this->assertNotNull($assignedRole);
        $this->assertSame(0, (int) $assignedRole->tipo);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => (int) $assignedRole->idrol,
            'modulo' => 'usuarios',
            'accion' => 'ver',
        ]);
    }

    public function test_store_with_precreated_role_does_not_assign_internal_role(): void
    {
        $this->loginAsAdmin();
        $this->seedPersonal('70010002');

        $precreatedRoleId = $this->createPrecreatedRole('Operador', [
            ['modulo' => 'usuarios', 'accion' => 'ver', 'nombre' => 'Ver Usuarios'],
        ]);

        $response = $this->post(route('modules.usuarios.store'), [
            'usuario' => 'pre.role.user',
            'personal_dniPersonal' => '70010002',
            'clave' => 'ClaveSegura1!',
            'estado' => '1',
            'role_ids' => [(string) $precreatedRoleId],
            'permissions' => [
                'usuarios' => [
                    'editar' => '1',
                ],
            ],
        ]);

        $response->assertRedirect(route('modules.usuarios'));

        $this->assertDatabaseHas('detallerol', [
            'usuario_usuario' => 'pre.role.user',
            'rol_idrol' => $precreatedRoleId,
        ]);

        $internalAssignedCount = DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->where('dr.usuario_usuario', 'pre.role.user')
            ->where('r.tipo', 0)
            ->count();

        $this->assertSame(0, $internalAssignedCount);
    }

    public function test_update_switches_internal_role_to_precreated_role_and_cleans_orphan(): void
    {
        $this->loginAsAdmin();
        $this->seedPersonal('70010003');
        $this->seedUsuario('switch.user', '70010003');

        $internalRoleId = $this->createInternalRole('switch.user', [
            ['modulo' => 'usuarios', 'accion' => 'ver', 'nombre' => 'Ver Usuarios'],
            ['modulo' => 'usuarios', 'accion' => 'editar', 'nombre' => 'Editar Usuarios'],
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'switch.user',
            'rol_idrol' => $internalRoleId,
        ]);

        $precreatedRoleId = $this->createPrecreatedRole('Supervisor', [
            ['modulo' => 'usuarios', 'accion' => 'ver', 'nombre' => 'Ver Usuarios'],
        ]);

        $response = $this->put(route('modules.usuarios.update', 'switch.user'), [
            'usuario' => 'switch.user',
            'personal_dniPersonal' => '70010003',
            'estado' => '1',
            'role_ids' => [(string) $precreatedRoleId],
        ]);

        $response->assertRedirect(route('modules.usuarios'));

        $this->assertDatabaseHas('detallerol', [
            'usuario_usuario' => 'switch.user',
            'rol_idrol' => $precreatedRoleId,
        ]);

        $this->assertDatabaseMissing('rol', [
            'idrol' => $internalRoleId,
        ]);

        $this->assertDatabaseMissing('inforol', [
            'rol_idrol' => $internalRoleId,
        ]);
    }

    public function test_destroy_cleans_internal_role_and_permissions(): void
    {
        $this->loginAsAdmin();
        $this->seedPersonal('70010004');
        $this->seedUsuario('delete.user', '70010004');

        $internalRoleId = $this->createInternalRole('delete.user', [
            ['modulo' => 'usuarios', 'accion' => 'ver', 'nombre' => 'Ver Usuarios'],
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'delete.user',
            'rol_idrol' => $internalRoleId,
        ]);

        $response = $this->delete(route('modules.usuarios.destroy', 'delete.user'));

        $response->assertRedirect(route('modules.usuarios'));

        $this->assertDatabaseMissing('usuario', [
            'usuario' => 'delete.user',
        ]);

        $this->assertDatabaseMissing('detallerol', [
            'usuario_usuario' => 'delete.user',
        ]);

        $this->assertDatabaseMissing('rol', [
            'idrol' => $internalRoleId,
        ]);

        $this->assertDatabaseMissing('inforol', [
            'rol_idrol' => $internalRoleId,
        ]);
    }

    public function test_internal_role_permissions_are_applied_after_login(): void
    {
        $this->seedPersonal('70010005');

        DB::table('usuario')->insert([
            'usuario' => 'internal.login',
            'personal_dniPersonal' => '70010005',
            'clave' => Hash::make('ClaveSegura1!'),
            'estado' => '1',
        ]);

        $internalRoleId = $this->createInternalRole('internal.login', [
            ['modulo' => 'roles', 'accion' => 'ver', 'nombre' => 'Ver Roles'],
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'internal.login',
            'rol_idrol' => $internalRoleId,
        ]);

        $loginResponse = $this->post(route('login.attempt'), [
            'usuario' => 'internal.login',
            'password' => 'ClaveSegura1!',
        ]);

        $loginResponse->assertRedirect(route('home'));

        $authData = session('erp_auth');
        $this->assertIsArray($authData);
        $this->assertContains('roles', $authData['modules'] ?? []);
        $this->assertContains('ver', $authData['permissions']['roles'] ?? []);

        $this->get(route('modules.roles'))->assertOk();
        $this->get(route('modules.usuarios'))->assertForbidden();
    }

    public function test_create_usuario_page_renders_without_blade_parse_errors(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('La ruta usa GROUP_CONCAT con sintaxis de MySQL no compatible con SQLite en tests.');
        }

        $this->loginAsAdmin();

        $response = $this->get(route('modules.usuarios.create'));

        $response->assertOk();
        $response->assertSee('Nuevo Usuario');
    }

    private function loginAsAdmin(): void
    {
        $this->seedPersonal('00000000');

        DB::table('usuario')->insert([
            'usuario' => 'admin',
            'personal_dniPersonal' => '00000000',
            'clave' => Hash::make('ClaveAdmin1!'),
            'estado' => '1',
        ]);

        $adminRoleId = DB::table('rol')->insertGetId([
            'nombre' => 'admin',
            'estado' => 1,
            'tipo' => 1,
            'fechaCreacion' => now(),
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'admin',
            'rol_idrol' => $adminRoleId,
        ]);

        $this->post(route('login.attempt'), [
            'usuario' => 'admin',
            'password' => 'ClaveAdmin1!',
        ])->assertRedirect(route('home'));
    }

    private function seedUsuario(string $usuario, string $dni): void
    {
        DB::table('usuario')->insert([
            'usuario' => $usuario,
            'personal_dniPersonal' => $dni,
            'clave' => Hash::make('ClaveSegura1!'),
            'estado' => '1',
        ]);
    }

    private function seedPersonal(string $dni): void
    {
        DB::table('cargopersonal')->insertOrIgnore([
            'idcargoPersonal' => 1,
            'descripcion' => 'Cargo Base',
        ]);

        DB::table('personal')->insertOrIgnore([
            'dniPersonal' => $dni,
            'apellido' => 'QA',
            'nombre' => 'Usuarios',
            'cargoPersonal_idcargoPersonal' => 1,
            'foto' => 'foto.png',
            'firma' => 'firma.png',
            'correo' => $dni . '@example.com',
            'estado' => '1',
        ]);
    }

    private function createPrecreatedRole(string $nombre, array $permissions): int
    {
        $roleId = DB::table('rol')->insertGetId([
            'nombre' => $nombre,
            'estado' => 1,
            'tipo' => 1,
            'fechaCreacion' => now(),
        ]);

        if ($permissions !== []) {
            $rows = collect($permissions)
                ->map(function ($permission) use ($roleId) {
                    return [
                        'rol_idrol' => $roleId,
                        'modulo' => $permission['modulo'],
                        'accion' => $permission['accion'],
                        'nombre' => $permission['nombre'],
                    ];
                })
                ->all();

            DB::table('inforol')->insert($rows);
        }

        return (int) $roleId;
    }

    private function createInternalRole(string $usuario, array $permissions): int
    {
        $roleId = DB::table('rol')->insertGetId([
            'nombre' => 'rol_' . $usuario,
            'estado' => 1,
            'tipo' => 0,
            'fechaCreacion' => now(),
        ]);

        if ($permissions !== []) {
            $rows = collect($permissions)
                ->map(function ($permission) use ($roleId) {
                    return [
                        'rol_idrol' => $roleId,
                        'modulo' => $permission['modulo'],
                        'accion' => $permission['accion'],
                        'nombre' => $permission['nombre'],
                    ];
                })
                ->all();

            DB::table('inforol')->insert($rows);
        }

        return (int) $roleId;
    }
}
