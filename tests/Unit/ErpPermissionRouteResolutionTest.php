<?php

namespace Tests\Unit;

use App\Support\ErpPermission;
use PHPUnit\Framework\TestCase;

class ErpPermissionRouteResolutionTest extends TestCase
{
    public function test_resolves_expected_permission_key_from_route_name(): void
    {
        $cases = [
            'modules.personal' => 'personal',
            'modules.roles.edit' => 'roles',
            'modules.clientes.index' => 'clientes.cliente',
            'modules.clientes.credenciales.index' => 'clientes.credenciales',
            'modules.clientes.credenciales.crear-rapido' => 'clientes.credenciales',
            'modules.clientes.grupos.index' => 'clientes.grupo_cliente',
            'modules.almacen' => 'almacen.almacen',
            'modules.almacen.index' => 'almacen.almacen',
            'modules.almacen.nota-salida.index' => 'almacen.nota_salida',
            'modules.vehiculos.index' => 'vehiculos',
            'modules.dispositivo-cliente.index' => 'dispositivo_cliente',
            'modules.vehiculos.edit' => 'vehiculos',
            'modules.servicio-cliente.index' => 'servicio_cliente',
            'modules.servicio-cliente.export' => 'servicio_cliente',
            'modules.configuracion.estados.index' => 'configuracion.estado',
            'modules.configuracion.tipos-contacto.index' => 'configuracion.tipo_contacto',
            'modules.configuracion.ubigeos.index' => 'configuracion.ubigeo',
            'modules.configuracion.cargos.index' => 'configuracion.cargo',
            'modules.configuracion.auditoria.index' => 'configuracion.auditoria',
            'modules.configuracion' => 'configuracion',
        ];

        foreach ($cases as $routeName => $expectedPermissionKey) {
            $this->assertSame(
                $expectedPermissionKey,
                ErpPermission::resolvePermissionKeyFromRouteName($routeName)
            );
        }
    }

    public function test_infers_expected_action_from_route_name_and_http_method(): void
    {
        $this->assertSame('crear', ErpPermission::inferActionFromRouteName('modules.clientes.store', 'POST'));
        $this->assertSame('editar', ErpPermission::inferActionFromRouteName('modules.clientes.update', 'PUT'));
        $this->assertSame('eliminar', ErpPermission::inferActionFromRouteName('modules.clientes.destroy', 'DELETE'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.clientes.export', 'GET'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.vehiculos.index', 'GET'));
        $this->assertSame('editar', ErpPermission::inferActionFromRouteName('modules.clientes.lock', 'POST'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.lineas-chips.detallesimcard.import.preview', 'POST'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.lineas-chips.detallesimcard.import.process', 'POST'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.lineas-chips.detallesimcard.preview.export', 'POST'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.lineas-chips.detallesimcard.bulk-deactivate', 'POST'));
        $this->assertSame('ver', ErpPermission::inferActionFromRouteName('modules.lineas-chips.detallesimcard.bulk-deactivate.parse-file', 'POST'));
        $this->assertNull(ErpPermission::inferActionFromRouteName('home', 'GET'));
    }

    public function test_normalizes_credenciales_permission_key(): void
    {
        $this->assertSame('clientes.credenciales', ErpPermission::normalizePermissionKey('clientes.credenciales'));
        $this->assertSame('clientes.credenciales', ErpPermission::normalizePermissionKey('clientes.credencial'));
    }

    public function test_normalizes_almacen_leaf_permission_key(): void
    {
        $this->assertSame('almacen.almacen', ErpPermission::normalizePermissionKey('almacen.almacen'));
    }

    public function test_normalizes_dispositivo_cliente_permission_key(): void
    {
        $this->assertSame('dispositivo_cliente', ErpPermission::normalizePermissionKey('vehiculos.dispositivo_cliente'));
        $this->assertSame('dispositivo_cliente', ErpPermission::normalizePermissionKey('vehiculos.dispositivo-cliente'));
        $this->assertSame('dispositivo_cliente', ErpPermission::normalizePermissionKey('vehiculos.dispositivo cliente'));
        $this->assertSame('dispositivo_cliente', ErpPermission::normalizePermissionKey('dispositivo_cliente'));
        $this->assertSame('dispositivo_cliente', ErpPermission::normalizePermissionKey('dispositivo-cliente'));
        $this->assertSame('dispositivo_cliente', ErpPermission::normalizePermissionKey('dispositivo cliente'));
    }

    public function test_normalizes_lineas_chips_cargar_y_bajar_numeros_permission_keys(): void
    {
        $this->assertSame('lineas_chips.cargar_numeros', ErpPermission::normalizePermissionKey('lineas_chips.cargar_numeros'));
        $this->assertSame('lineas_chips.cargar_numeros', ErpPermission::normalizePermissionKey('lineas-chips.cargar-numeros'));
        $this->assertSame('lineas_chips.cargar_numeros', ErpPermission::normalizePermissionKey('cargar_numeros'));
        $this->assertSame('lineas_chips.cargar_numeros', ErpPermission::normalizePermissionKey('cargar-numeros'));
        $this->assertSame('lineas_chips.bajar_numeros', ErpPermission::normalizePermissionKey('lineas_chips.bajar_numeros'));
        $this->assertSame('lineas_chips.bajar_numeros', ErpPermission::normalizePermissionKey('lineas-chips.bajar-numeros'));
        $this->assertSame('lineas_chips.bajar_numeros', ErpPermission::normalizePermissionKey('bajar_numeros'));
        $this->assertSame('lineas_chips.bajar_numeros', ErpPermission::normalizePermissionKey('bajar-numeros'));
    }

    public function test_lineas_chips_cargar_y_bajar_numeros_are_ver_only_in_permission_matrix(): void
    {
        $matrix = \App\Support\RolePermissionMatrix::defaultMatrix();

        $this->assertArrayHasKey('lineas_chips.cargar_numeros', $matrix);
        $this->assertArrayHasKey('lineas_chips.bajar_numeros', $matrix);
        $this->assertSame(['ver' => false], $matrix['lineas_chips.cargar_numeros']);
        $this->assertSame(['ver' => false], $matrix['lineas_chips.bajar_numeros']);

        $stored = [
            ['modulo' => 'lineas_chips.cargar_numeros', 'accion' => 'crear'],
            ['modulo' => 'lineas_chips.bajar_numeros', 'accion' => 'editar'],
            ['modulo' => 'lineas_chips.cargar_numeros', 'accion' => 'ver'],
        ];

        $mapped = \App\Support\RolePermissionMatrix::matrixFromStoredPermissions($stored);
        $this->assertSame(['ver' => true], $mapped['lineas_chips.cargar_numeros']);
        $this->assertSame(['ver' => false], $mapped['lineas_chips.bajar_numeros']);
    }

    public function test_validate_dependencies_requires_detallesimcard_ver_before_cargar_o_bajar_numeros(): void
    {
        $error = \App\Support\RolePermissionMatrix::validateDependencies([
            ['modulo' => 'lineas_chips.cargar_numeros', 'accion' => 'ver'],
        ]);

        $this->assertStringContainsString('Para asignar permisos de Cargar números o Bajar números debes dar primero permisos de Asignación SimCard: Ver.', $error);

        $errorWithOtherLineas = \App\Support\RolePermissionMatrix::validateDependencies([
            ['modulo' => 'lineas_chips.numero_telefonico', 'accion' => 'ver'],
            ['modulo' => 'lineas_chips.cargar_numeros', 'accion' => 'ver'],
        ]);

        $this->assertStringContainsString('Para asignar permisos de Cargar números o Bajar números debes dar primero permisos de Asignación SimCard: Ver.', $errorWithOtherLineas);

        $this->assertNull(\App\Support\RolePermissionMatrix::validateDependencies([
            ['modulo' => 'lineas_chips.detallesimcard', 'accion' => 'ver'],
            ['modulo' => 'lineas_chips.cargar_numeros', 'accion' => 'ver'],
        ]));
    }

    public function test_expand_permission_keys_does_not_inherit_vehicle_device_access_from_parent_vehicle_permission(): void
    {
        $this->assertSame(['vehiculos'], ErpPermission::expandPermissionKeys('vehiculos'));
    }
}
