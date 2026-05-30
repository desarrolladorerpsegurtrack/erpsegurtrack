<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RolesPermissionsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_loads_modules_from_inforol_and_enforces_module_access(): void
    {
        $this->seedPersonal('44556677');
        $this->seedUsuario('operador.roles', 'ClaveCorrecta1!', '44556677');

        $roleId = DB::table('rol')->insertGetId([
            'nombre' => 'operador',
            'estado' => 1,
            'fechaCreacion' => now(),
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'operador.roles',
            'rol_idrol' => $roleId,
        ]);

        DB::table('inforol')->insert([
            'rol_idrol' => $roleId,
            'modulo' => 'roles',
            'accion' => 'ver',
            'nombre' => 'Ver Roles',
        ]);

        $loginResponse = $this->post(route('login.attempt'), [
            'usuario' => 'operador.roles',
            'password' => 'ClaveCorrecta1!',
        ]);

        $loginResponse->assertRedirect(route('home'));

        $authData = session('erp_auth');
        $this->assertIsArray($authData);
        $this->assertContains('roles', $authData['modules']);
        $this->assertNotContains('usuarios', $authData['modules']);
        $this->assertArrayHasKey('permissions', $authData);
        $this->assertContains('ver', $authData['permissions']['roles'] ?? []);

        $this->get(route('modules.roles'))->assertOk();
        $this->get(route('modules.usuarios'))->assertForbidden();
    }

    public function test_roles_crud_buttons_routes_work_with_roles_module_permission(): void
    {
        $this->withRolesSession();

        $this->get(route('modules.roles'))->assertOk();
        $this->get(route('modules.roles.create'))->assertOk();

        $storeResponse = $this->post(route('modules.roles.store'), [
            'nombre' => 'qa-role',
            'estado' => '1',
            'permissions' => [
                'roles' => [
                    'ver' => '1',
                    'crear' => '1',
                ],
                'usuarios' => [
                    'ver' => '1',
                ],
            ],
        ]);

        $storeResponse->assertRedirect(route('modules.roles'));

        $createdRole = DB::table('rol')->where('nombre', 'qa-role')->first();
        $this->assertNotNull($createdRole);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'roles',
            'accion' => 'ver',
        ]);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'roles',
            'accion' => 'crear',
        ]);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'usuarios',
            'accion' => 'ver',
        ]);

        $this->get(route('modules.roles.edit', $createdRole->idrol))->assertOk();

        $updateResponse = $this->put(route('modules.roles.update', $createdRole->idrol), [
            'nombre' => 'qa-role-updated',
            'estado' => '1',
            'permissions' => [
                'personal' => [
                    'ver' => '1',
                ],
                'roles' => [
                    'editar' => '1',
                ],
            ],
        ]);

        $updateResponse->assertRedirect(route('modules.roles'));

        $this->assertDatabaseHas('rol', [
            'idrol' => $createdRole->idrol,
            'nombre' => 'qa-role-updated',
        ]);

        $this->assertDatabaseMissing('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'usuarios',
            'accion' => 'ver',
        ]);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'personal',
            'accion' => 'ver',
        ]);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'roles',
            'accion' => 'editar',
        ]);

        $deleteResponse = $this->delete(route('modules.roles.destroy', $createdRole->idrol));
        $deleteResponse->assertRedirect(route('modules.roles'));

        $this->assertDatabaseMissing('rol', ['idrol' => $createdRole->idrol]);
        $this->assertDatabaseMissing('inforol', ['rol_idrol' => $createdRole->idrol]);
    }

    public function test_roles_can_assign_lineas_chips_cargar_y_bajar_numeros_permissions(): void
    {
        $this->withRolesSession();

        $response = $this->post(route('modules.roles.store'), [
            'nombre' => 'qa-lineas-numeros',
            'estado' => '1',
            'permissions' => [
                'roles' => [
                    'ver' => '1',
                ],
                'lineas_chips.cargar_numeros' => [
                    'ver' => '1',
                ],
                'lineas_chips.bajar_numeros' => [
                    'ver' => '1',
                ],
            ],
        ]);

        $response->assertRedirect(route('modules.roles'));

        $createdRole = DB::table('rol')->where('nombre', 'qa-lineas-numeros')->first();
        $this->assertNotNull($createdRole);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'lineas_chips.cargar_numeros',
            'accion' => 'ver',
        ]);

        $this->assertDatabaseHas('inforol', [
            'rol_idrol' => $createdRole->idrol,
            'modulo' => 'lineas_chips.bajar_numeros',
            'accion' => 'ver',
        ]);
    }

    public function test_roles_cannot_assign_credenciales_without_cliente_permission(): void
    {
        $this->withRolesSession();

        $response = $this->post(route('modules.roles.store'), [
            'nombre' => 'role-with-credenciales',
            'estado' => '1',
            'permissions' => [
                'roles' => [
                    'ver' => '1',
                    'crear' => '1',
                ],
                'clientes.cliente' => [
                    'ver' => '1',
                ],
                'clientes.credenciales' => [
                    'ver' => '1',
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['permissions']);
        $this->assertDatabaseMissing('rol', ['nombre' => 'role-with-credenciales']);
    }

    public function test_roles_module_denies_access_without_roles_permission(): void
    {
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'empleado',
                'personal_dni' => '11220011',
                'roles' => ['empleado'],
                'modules' => ['personal'],
                'permissions' => [
                    'personal' => ['ver'],
                ],
            ],
        ]);

        $this->get(route('modules.roles'))->assertForbidden();
        $this->get(route('modules.roles.create'))->assertForbidden();
    }

    public function test_configuracion_ver_y_crear_permite_store_y_bloquea_update_destroy(): void
    {
        DB::table('ubigeo')->insert([
            'idubigeo' => 1001,
            'departamento' => 'LIMA',
            'provincia' => 'LIMA',
            'distrito' => 'MIRAFLORES',
            'pais' => 'PERU',
        ]);

        $this->withSession([
            'erp_auth' => [
                'usuario' => 'operador.config',
                'personal_dni' => '22334455',
                'roles' => ['operador'],
                'modules' => ['configuracion'],
                'permissions' => [
                    'configuracion' => ['ver', 'crear'],
                    'configuracion.ubigeo' => ['ver', 'crear'],
                ],
            ],
        ]);

        $this->get(route('modules.configuracion.ubigeos.index'))->assertOk();
        $this->get(route('modules.configuracion.ubigeos.create'))->assertOk();

        $this->post(route('modules.configuracion.ubigeos.store'), [
            'idubigeo' => 1002,
            'departamento' => 'CUSCO',
            'provincia' => 'CUSCO',
            'distrito' => 'SANTIAGO',
            'pais' => 'PERU',
        ])->assertRedirect(route('modules.configuracion.ubigeos.index'));

        $this->assertDatabaseHas('ubigeo', [
            'idubigeo' => 1002,
            'departamento' => 'CUSCO',
        ]);

        $this->put(route('modules.configuracion.ubigeos.update', 1001), [
            'idubigeo' => 1001,
            'departamento' => 'AREQUIPA',
            'provincia' => 'AREQUIPA',
            'distrito' => 'CERRO COLORADO',
            'pais' => 'PERU',
        ])->assertForbidden();

        $this->delete(route('modules.configuracion.ubigeos.destroy', 1001))
            ->assertForbidden();
    }

    public function test_clientes_ver_crear_sin_editar_ni_eliminar_oculta_acciones(): void
    {
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'operador.ventas',
                'personal_dni' => '55667788',
                'roles' => ['ventas'],
                'modules' => ['clientes'],
                'permissions' => [
                    'clientes' => ['ver', 'crear'],
                    'clientes.cliente' => ['ver', 'crear'],
                ],
            ],
        ]);

        DB::table('estadocliente')->insert([
            'idestadoCliente' => 1,
            'detalle' => 'Activo',
        ]);

        DB::table('cliente')->insert([
            'idcliente' => 'C1',
            'nombreComercial' => 'Cliente QA',
            'estadoCliente_idestadoCliente' => 1,
            'fechaIngreso' => now(),
            'tipoCliente' => '1',
        ]);

        $index = $this->get(route('modules.clientes'));
        $index->assertOk();
        $index->assertSee('Nuevo Cliente');
        $index->assertDontSee('Editar');
        $index->assertDontSee('Eliminar');

        $this->get(route('modules.clientes.create'))->assertOk();
        $this->delete(route('modules.clientes.destroy', 'C1'))->assertForbidden();
    }

    public function test_clientes_grupo_cliente_no_hereda_permiso_de_clientes_cliente(): void
    {
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'operador.grupos',
                'personal_dni' => '22330011',
                'roles' => ['operador'],
                'modules' => ['clientes'],
                'permissions' => [
                    'clientes' => ['ver', 'crear'],
                    'clientes.grupo_cliente' => ['ver', 'crear'],
                ],
            ],
        ]);

        $this->get(route('modules.clientes.grupos.index'))->assertOk();
        $this->get(route('modules.clientes'))->assertForbidden();
    }

    public function test_configuracion_submodulo_no_hereda_permiso_entre_hermanos(): void
    {
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'operador.config.cargo',
                'personal_dni' => '99003311',
                'roles' => ['operador'],
                'modules' => ['configuracion'],
                'permissions' => [
                    'configuracion' => ['ver'],
                    'configuracion.cargo' => ['ver'],
                ],
            ],
        ]);

        $this->get(route('modules.configuracion.cargos.index'))->assertOk();
        $this->get(route('modules.configuracion.ubigeos.index'))->assertForbidden();
    }

    public function test_home_menu_hides_almacen_leaf_when_only_submodules_are_allowed(): void
    {
        $this->seedPersonal('44550012');
        $this->seedUsuario('operador.almacen.menu', 'ClaveQA2!', '44550012');

        $roleId = DB::table('rol')->insertGetId([
            'nombre' => 'operador-almacen-menu',
            'estado' => 1,
            'fechaCreacion' => now(),
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'operador.almacen.menu',
            'rol_idrol' => $roleId,
        ]);

        DB::table('inforol')->insert([
            ['rol_idrol' => $roleId, 'modulo' => 'almacen.nota_ingreso', 'accion' => 'ver', 'nombre' => 'Ver Nota de ingreso'],
            ['rol_idrol' => $roleId, 'modulo' => 'almacen.nota_salida', 'accion' => 'ver', 'nombre' => 'Ver Nota de salida'],
        ]);

        $this->post(route('login.attempt'), [
            'usuario' => 'operador.almacen.menu',
            'password' => 'ClaveQA2!',
        ])->assertRedirect(route('home'));

        $response = $this->get(route('home'));
        $response->assertOk();
        $response->assertSee('Nota de ingreso');
        $response->assertSee('Nota de salida');
        $response->assertDontSee('href="http://localhost/modulos/almacen" class="side-menu__link');
    }

    public function test_almacen_nota_salida_no_hereda_acciones_del_nodo_padre(): void
    {
        $this->seedPersonal('44550011');
        $this->seedUsuario('operador.almacen.salida', 'ClaveQA1!', '44550011');

        $roleId = DB::table('rol')->insertGetId([
            'nombre' => 'operador',
            'estado' => 1,
            'fechaCreacion' => now(),
        ]);

        DB::table('detallerol')->insert([
            'usuario_usuario' => 'operador.almacen.salida',
            'rol_idrol' => $roleId,
        ]);

        DB::table('inforol')->insert([
            'rol_idrol' => $roleId,
            'modulo' => 'almacen.nota_salida',
            'accion' => 'ver',
            'nombre' => 'Ver Nota de salida',
        ]);

        $this->post(route('login.attempt'), [
            'usuario' => 'operador.almacen.salida',
            'password' => 'ClaveQA1!',
        ])->assertRedirect(route('home'));

        $this->get(route('modules.almacen.nota-salida.index'))->assertOk();
        $this->get(route('modules.almacen.nota-salida.create'))->assertForbidden();
    }

    private function withRolesSession(): void
    {
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'tester.roles',
                'personal_dni' => '99001122',
                'roles' => ['operador'],
                'modules' => ['roles'],
                'permissions' => [
                    'roles' => ['ver', 'crear', 'editar', 'eliminar'],
                ],
            ],
        ]);
    }

    private function seedUsuario(string $usuario, string $plainPassword, string $dni): void
    {
        DB::table('usuario')->insert([
            'usuario' => $usuario,
            'personal_dniPersonal' => $dni,
            'clave' => Hash::make($plainPassword),
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
            'nombre' => 'Roles',
            'cargoPersonal_idcargoPersonal' => 1,
            'foto' => 'foto.png',
            'firma' => 'firma.png',
            'correo' => 'roles@example.com',
            'estado' => '1',
        ]);
    }
}
