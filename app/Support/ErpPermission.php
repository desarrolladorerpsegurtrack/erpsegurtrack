<?php

namespace App\Support;


class ErpPermission
{
    private const MODULE_CHILDREN = [
        'almacen' => [
            'almacen.almacen',
            'almacen.elemento_almacen',
            'almacen.nota_ingreso',
            'almacen.nota_salida',
        ],
        'ventas' => [
            'ventas.planes_servicios',
            'ventas.cotizaciones',
            'ventas.personal',
        ],
        'clientes' => [
            'clientes.cliente',
            'clientes.credenciales',
            'clientes.grupo_cliente',
        ],
        'configuracion' => [
            'configuracion.estado',
            'configuracion.tipo_contacto',
            'configuracion.ubigeo',
            'configuracion.cargo',
            'configuracion.auditoria',
            'configuracion.moneda',
            'configuracion.tributo',
            'configuracion.unidad_medida',
            'configuracion.empresapropietaria',
            'configuracion.modelo',
            'configuracion.marca',
            'configuracion.tecnologia',
            'configuracion.tipo_gasto',
            'configuracion.tipo_cobro',
            'configuracion.tipo_plataforma',
            'configuracion.plataforma',
            'configuracion.tipo_elemento',
            'configuracion.tipo_documento',
            'configuracion.forma_pago',
            'configuracion.entidad_bancaria',
            'configuracion.operador',
            'configuracion.tipo_vehiculo',
            'configuracion.tipo_operacion',
            'configuracion.lista_precio',
            'configuracion.tipo_pedido',
            'configuracion.proveedor',
            'configuracion.certificadosunat',
            'configuracion.vigencia_oferta',
            'configuracion.detalle_lista_precio',
            'configuracion.paquetes',
        ],
        'sistema' => [
            'sistema.vista',
            'sistema.flujo',
            'sistema.flujoregla',
            'sistema.historialflujo',
        ],
        'lineas_chips' => [
            'lineas_chips.numero_telefonico',
            'lineas_chips.simcard',
            'lineas_chips.detallesimcard',
            'lineas_chips.numero_dispositivo',
            'lineas_chips.cargar_numeros',
            'lineas_chips.bajar_numeros',
        ],
    ];

    private const FIXED_ROUTE_MODULES = [
        'personal' => 'personal',
        'roles' => 'roles',
        'usuarios' => 'usuarios',
        'vehiculo' => 'vehiculos',
        'vehiculos' => 'vehiculos',
        'cuentasporcobrar' => 'cuentasporcobrar',
        'dispositivo-cliente' => 'dispositivo_cliente',
        'servicio-cliente' => 'servicio_cliente',
        'servicio_cliente' => 'servicio_cliente',
        'serviciocliente' => 'servicio_cliente',
        'tickets' => 'tickets',
        'ticket' => 'tickets',
    ];

    private const ALMACEN_ROUTE_RULES = [
        'planes-servicios' => 'ventas.planes_servicios',
        'planes_servicios' => 'ventas.planes_servicios',
        'planes servicios' => 'ventas.planes_servicios',
        'elemento-almacen' => 'almacen.elemento_almacen',
        'elemento_almacen' => 'almacen.elemento_almacen',
        'elemento almacen' => 'almacen.elemento_almacen',
        'nota-ingreso' => 'almacen.nota_ingreso',
        'nota_ingreso' => 'almacen.nota_ingreso',
        'nota ingreso' => 'almacen.nota_ingreso',
        'nota-salida' => 'almacen.nota_salida',
        'nota_salida' => 'almacen.nota_salida',
        'nota salida' => 'almacen.nota_salida',
        '' => 'almacen.almacen',
        'index' => 'almacen.almacen',
    ];

    private const VENTAS_ROUTE_RULES = [
        'planes-servicios' => 'ventas.planes_servicios',
        'planes_servicios' => 'ventas.planes_servicios',
        'planes servicios' => 'ventas.planes_servicios',
        'cotizaciones' => 'ventas.cotizaciones',
        'cotizacion' => 'ventas.cotizaciones',
        '' => 'ventas.planes_servicios',
        'index' => 'ventas.planes_servicios',
    ];

    private const CLIENTES_ROUTE_RULES = [
        'grupos' => 'clientes.grupo_cliente',
        'credenciales' => 'clientes.credenciales',
        'estados' => 'configuracion.estado',
    ];

    private const LINEAS_CHIPS_ROUTE_RULES = [
        'numeros-telefonico' => 'lineas_chips.numero_telefonico',
        'numeros_telefonico' => 'lineas_chips.numero_telefonico',
        'numero-telefonico' => 'lineas_chips.numero_telefonico',
        'numero_telefonico' => 'lineas_chips.numero_telefonico',
        'simcard' => 'lineas_chips.simcard',
        'sim-card' => 'lineas_chips.simcard',
        'detallesimcard' => 'lineas_chips.detallesimcard',
        'detalle-simcard' => 'lineas_chips.detallesimcard',
        'detalles-simcard' => 'lineas_chips.detallesimcard',
        'numeros-dispositivo' => 'lineas_chips.numero_dispositivo',
        'numeros_dispositivo' => 'lineas_chips.numero_dispositivo',
        'numero-dispositivo' => 'lineas_chips.numero_dispositivo',
        'numero_dispositivo' => 'lineas_chips.numero_dispositivo',
        '' => 'lineas_chips',
        'index' => 'lineas_chips',
    ];

    private const CONFIGURACION_ROUTE_RULES = [
        'estados' => 'configuracion.estado',
        'tipos-contacto' => 'configuracion.tipo_contacto',
        'tipos_contacto' => 'configuracion.tipo_contacto',
        'tipo-contacto' => 'configuracion.tipo_contacto',
        'tipo_contacto' => 'configuracion.tipo_contacto',
        'ubigeos' => 'configuracion.ubigeo',
        'ubigeo' => 'configuracion.ubigeo',
        'cargos' => 'configuracion.cargo',
        'cargo' => 'configuracion.cargo',
        'auditoria' => 'configuracion.auditoria',
        'monedas' => 'configuracion.moneda',
        'moneda' => 'configuracion.moneda',
        'tributos' => 'configuracion.tributo',
        'tributo' => 'configuracion.tributo',
        'unidad-medida' => 'configuracion.unidad_medida',
        'unidad_medida' => 'configuracion.unidad_medida',
        'unidadmedida' => 'configuracion.unidad_medida',
        'marcas' => 'configuracion.marca',
        'marca' => 'configuracion.marca',
        'tecnologias' => 'configuracion.tecnologia',
        'tecnologia' => 'configuracion.tecnologia',
        'tipos-gasto' => 'configuracion.tipo_gasto',
        'tipos_gasto' => 'configuracion.tipo_gasto',
        'tipo-gasto' => 'configuracion.tipo_gasto',
        'tipo_gasto' => 'configuracion.tipo_gasto',
        'tipos-cobro' => 'configuracion.tipo_cobro',
        'tipos_cobro' => 'configuracion.tipo_cobro',
        'tipo-cobro' => 'configuracion.tipo_cobro',
        'tipo_cobro' => 'configuracion.tipo_cobro',
        'tipos-plataforma' => 'configuracion.tipo_plataforma',
        'tipos_plataforma' => 'configuracion.tipo_plataforma',
        'tipo-plataforma' => 'configuracion.tipo_plataforma',
        'tipo_plataforma' => 'configuracion.tipo_plataforma',
        'plataforma' => 'configuracion.plataforma',
        'plataformas' => 'configuracion.plataforma',
        'tipo-elemento' => 'configuracion.tipo_elemento',
        'tipo_elemento' => 'configuracion.tipo_elemento',
        'tipoelemento' => 'configuracion.tipo_elemento',
        'tipos-elemento' => 'configuracion.tipo_elemento',
        'tipos_elemento' => 'configuracion.tipo_elemento',
        'empresapropietaria' => 'configuracion.empresapropietaria',
        'empresa-propietaria' => 'configuracion.empresapropietaria',
        'empresa_propietaria' => 'configuracion.empresapropietaria',
        'empresa propietaria' => 'configuracion.empresapropietaria',
        'modelo' => 'configuracion.modelo',
        'modelos' => 'configuracion.modelo',
        'tipos-documento' => 'configuracion.tipo_documento',
        'tipos_documento' => 'configuracion.tipo_documento',
        'tipo-documento' => 'configuracion.tipo_documento',
        'tipo_documento' => 'configuracion.tipo_documento',
        'formas-pago' => 'configuracion.forma_pago',
        'formas_pago' => 'configuracion.forma_pago',
        'forma-pago' => 'configuracion.forma_pago',
        'forma_pago' => 'configuracion.forma_pago',
        'entidades-bancarias' => 'configuracion.entidad_bancaria',
        'entidades_bancarias' => 'configuracion.entidad_bancaria',
        'entidad-bancaria' => 'configuracion.entidad_bancaria',
        'entidad_bancaria' => 'configuracion.entidad_bancaria',
        'operadores' => 'configuracion.operador',
        'operador' => 'configuracion.operador',
        'tipos-vehiculo' => 'configuracion.tipo_vehiculo',
        'tipos_vehiculo' => 'configuracion.tipo_vehiculo',
        'tipo-vehiculo' => 'configuracion.tipo_vehiculo',
        'tipo_vehiculo' => 'configuracion.tipo_vehiculo',
        'tipos-operacion' => 'configuracion.tipo_operacion',
        'tipos_operacion' => 'configuracion.tipo_operacion',
        'tipo-operacion' => 'configuracion.tipo_operacion',
        'tipo_operacion' => 'configuracion.tipo_operacion',
        'listas-precio' => 'configuracion.lista_precio',
        'listas_precio' => 'configuracion.lista_precio',
        'lista-precio' => 'configuracion.lista_precio',
        'lista_precio' => 'configuracion.lista_precio',
        'detalle-lista-precio' => 'configuracion.detalle_lista_precio',
        'detalle_lista_precio' => 'configuracion.detalle_lista_precio',
        'detallelistaprecio' => 'configuracion.detalle_lista_precio',
        'elemento-almacen' => 'almacen.elemento_almacen',
        'elemento_almacen' => 'almacen.elemento_almacen',
        'elementoalmacen' => 'almacen.elemento_almacen',
        'tipos-pedido' => 'configuracion.tipo_pedido',
        'tipos_pedido' => 'configuracion.tipo_pedido',
        'tipo-pedido' => 'configuracion.tipo_pedido',
        'tipo_pedido' => 'configuracion.tipo_pedido',
        'proveedores' => 'configuracion.proveedor',
        'proveedor' => 'configuracion.proveedor',
        'certificados-sunat' => 'configuracion.certificadosunat',
        'certificados_sunat' => 'configuracion.certificadosunat',
        'certificadosunat' => 'configuracion.certificadosunat',
        'vigencias-oferta' => 'configuracion.vigencia_oferta',
        'vigencias_oferta' => 'configuracion.vigencia_oferta',
            'paquetes' => 'configuracion.paquetes',
            'paquete' => 'configuracion.paquetes',
        'vigencia-oferta' => 'configuracion.vigencia_oferta',
        'vigencia_oferta' => 'configuracion.vigencia_oferta',

    ];

    private const SISTEMA_ROUTE_RULES = [
        'vistas' => 'sistema.vista',
        'vista' => 'sistema.vista',
        'flujos' => 'sistema.flujo',
        'flujo' => 'sistema.flujo',
        'flujo-reglas' => 'sistema.flujoregla',
        'flujo_reglas' => 'sistema.flujoregla',
        'flujoregla' => 'sistema.flujoregla',
        'flujoreglas' => 'sistema.flujoregla',
        'historial-flujos' => 'sistema.historialflujo',
        'historial_flujos' => 'sistema.historialflujo',
        'historialflujo' => 'sistema.historialflujo',
        'historial-flujo' => 'sistema.historialflujo',
        'historial_flujo' => 'sistema.historialflujo',
    ];

    private const COMPOUND_PERMISSION_RULES = [
        ['prefix' => 'clientes.', 'containsAny' => ['grupo'], 'permission' => 'clientes.grupo_cliente'],
        ['prefix' => 'clientes.', 'containsAny' => ['credencial'], 'permission' => 'clientes.credenciales'],
        ['prefix' => 'clientes.', 'containsAny' => ['estado'], 'permission' => 'configuracion.estado'],
        ['prefix' => 'clientes.', 'permission' => 'clientes.cliente'],
        ['prefix' => 'vehiculo', 'permission' => 'vehiculos'],
        ['prefix' => 'vehiculos', 'permission' => 'vehiculos'],
        ['prefix' => 'almacen', 'permission' => 'almacen'],
        ['prefix' => 'servicio-cliente', 'permission' => 'servicio_cliente'],
        ['prefix' => 'servicio_cliente', 'permission' => 'servicio_cliente'],
        ['prefix' => 'serviciocliente', 'permission' => 'servicio_cliente'],
        ['prefix' => 'cuentasporcobrar', 'permission' => 'cuentasporcobrar'],
        ['prefix' => 'cuentas-por-cobrar', 'permission' => 'cuentasporcobrar'],
        ['prefix' => 'cuenta-por-cobrar', 'permission' => 'cuentasporcobrar'],
        ['prefix' => 'cuentas por cobrar', 'permission' => 'cuentasporcobrar'],
        ['prefix' => 'cuenta por cobrar', 'permission' => 'cuentasporcobrar'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_gasto', 'tipogasto', 'gasto'], 'permission' => 'configuracion.tipo_gasto'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_cobro', 'tipocobro', 'cobro'], 'permission' => 'configuracion.tipo_cobro'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_pedido', 'tipopedido', 'pedido'], 'permission' => 'configuracion.tipo_pedido'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_operacion', 'tipooperacion', 'operacion'], 'permission' => 'configuracion.tipo_operacion'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_vehiculo', 'tipovehiculo', 'vehiculo'], 'permission' => 'configuracion.tipo_vehiculo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_plataforma', 'tipoplataforma', 'tipo-plataforma', 'tipo plataforma'], 'permission' => 'configuracion.tipo_plataforma'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_documento', 'tipodocumento', 'documento'], 'permission' => 'configuracion.tipo_documento'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_elemento', 'tipoelemento', 'tipo-elemento', 'tipos-elemento', 'tipo elemento'], 'permission' => 'configuracion.tipo_elemento'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tipo_contacto', 'tipocontacto', 'tipo-contacto', 'tipos-contacto', 'contacto'], 'permission' => 'configuracion.tipo_contacto'],
        ['prefix' => 'configuracion.', 'containsAny' => ['ubigeo'], 'permission' => 'configuracion.ubigeo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['cargo'], 'permission' => 'configuracion.cargo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['marca'], 'permission' => 'configuracion.marca'],
        ['prefix' => 'configuracion.', 'containsAny' => ['empresapropietaria', 'empresa-propietaria', 'empresa_propietaria', 'empresa propietaria'], 'permission' => 'configuracion.empresapropietaria'],
        ['prefix' => 'configuracion.', 'containsAny' => ['modelo'], 'permission' => 'configuracion.modelo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tecnologia'], 'permission' => 'configuracion.tecnologia'],
        ['prefix' => 'configuracion.', 'containsAny' => ['plataforma'], 'containsNone' => ['tipo'], 'permission' => 'configuracion.plataforma'],
        ['prefix' => 'configuracion.', 'containsAny' => ['flujoregla', 'flujo-regla', 'flujo_regla', 'flujo regla'], 'permission' => 'configuracion.flujoregla'],
        ['prefix' => 'configuracion.', 'containsAny' => ['historialflujo', 'historial-flujo', 'historial_flujo', 'historial flujo'], 'permission' => 'configuracion.historialflujo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['vista'], 'permission' => 'configuracion.vista'],
        ['prefix' => 'configuracion.', 'containsAny' => ['flujo'], 'containsNone' => ['flujoregla', 'historial'], 'permission' => 'configuracion.flujo'],

        ['prefix' => 'sistema.', 'containsAny' => ['flujoregla', 'flujo-regla', 'flujo_regla', 'flujo regla'], 'permission' => 'sistema.flujoregla'],
        ['prefix' => 'sistema.', 'containsAny' => ['historialflujo', 'historial-flujo', 'historial_flujo', 'historial flujo'], 'permission' => 'sistema.historialflujo'],
        ['prefix' => 'sistema.', 'containsAny' => ['vista'], 'permission' => 'sistema.vista'],
        ['prefix' => 'sistema.', 'containsAny' => ['flujo'], 'containsNone' => ['flujoregla', 'historial'], 'permission' => 'sistema.flujo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['forma_pago', 'formapago', 'pago'], 'permission' => 'configuracion.forma_pago'],
        ['prefix' => 'configuracion.', 'containsAny' => ['entidad_bancaria', 'entidadbancaria', 'bancaria'], 'permission' => 'configuracion.entidad_bancaria'],
        ['prefix' => 'configuracion.', 'containsAny' => ['operador'], 'permission' => 'configuracion.operador'],
        ['prefix' => 'configuracion.', 'containsAny' => ['numero_dispositivo', 'numerosdispositivo', 'numerodispositivo', 'numero-dispositivo', 'numeros-dispositivo'], 'permission' => 'lineas_chips.numero_dispositivo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['numero_telefonico', 'numerotelefonico', 'telefono', 'telefonico'], 'permission' => 'lineas_chips.numero_telefonico'],
        ['prefix' => 'configuracion.', 'containsAny' => ['lista_precio', 'listaprecio'], 'permission' => 'configuracion.lista_precio'],
        ['prefix' => 'configuracion.', 'containsAny' => ['detalle_lista_precio', 'detallelistaprecio', 'detalle-lista-precio', 'detalle lista precio'], 'permission' => 'configuracion.detalle_lista_precio'],
        ['prefix' => 'configuracion.', 'containsAny' => ['elemento_almacen', 'elementoalmacen', 'elemento-almacen', 'elemento almacen'], 'permission' => 'almacen.elemento_almacen'],
        ['prefix' => 'configuracion.', 'containsAny' => ['proveedor'], 'permission' => 'configuracion.proveedor'],
        ['prefix' => 'configuracion.', 'containsAny' => ['certificado', 'sunat'], 'permission' => 'configuracion.certificadosunat'],
        ['prefix' => 'configuracion.', 'containsAny' => ['paquete', 'paquetes'], 'permission' => 'configuracion.paquetes'],
        ['prefix' => 'configuracion.', 'containsAny' => ['vigencia_oferta', 'vigenciaoferta', 'vigencia'], 'permission' => 'configuracion.vigencia_oferta'],
        ['prefix' => 'configuracion.', 'containsAny' => ['audit'], 'permission' => 'configuracion.auditoria'],
        ['prefix' => 'configuracion.', 'containsAny' => ['moneda'], 'permission' => 'configuracion.moneda'],
        ['prefix' => 'configuracion.', 'containsAny' => ['tributo'], 'permission' => 'configuracion.tributo'],
        ['prefix' => 'configuracion.', 'containsAny' => ['unidad'], 'permission' => 'configuracion.unidad_medida'],
        ['prefix' => 'configuracion.', 'containsAny' => ['estado'], 'permission' => 'configuracion.estado'],
    ];

    public static function allPermissionKeys(): array
    {
        $permissionKeys = ['inicio', 'tickets', 'almacen', 'personal', 'roles', 'usuarios', 'vehiculos', 'dispositivo_cliente', 'servicio_cliente', 'cuentasporcobrar'];

        foreach (self::MODULE_CHILDREN as $children) {
            $permissionKeys = array_merge($permissionKeys, $children);
        }

        return array_values(array_unique($permissionKeys));
    }

    public static function permissionKeyToModule(?string $permissionKey): ?string
    {
        $normalized = self::normalizePermissionKey($permissionKey);
        if ($normalized === null) {
            return null;
        }

        if (!str_contains($normalized, '.')) {
            return $normalized;
        }

        [$module] = explode('.', $normalized, 2);
        return $module !== '' ? $module : null;
    }

    public static function expandPermissionKeys(?string $module): array
    {
        $normalized = self::normalizePermissionKey($module);
        if ($normalized === null) {
            return [];
        }

        if ($normalized === 'vehiculos') {
            return ['vehiculos'];
        }

        if (isset(self::MODULE_CHILDREN[$normalized])) {
            return self::MODULE_CHILDREN[$normalized];
        }

        return [$normalized];
    }

    public static function resolvePermissionKeyFromRouteName(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        if (!str_starts_with($routeName, 'modules.')) {
            return null;
        }

        $segments = explode('.', mb_strtolower($routeName));
        $module = $segments[1] ?? null;
        $resource = $segments[2] ?? '';

        if (isset(self::FIXED_ROUTE_MODULES[$module])) {
            return self::FIXED_ROUTE_MODULES[$module];
        }

        if ($module === 'almacen') {
            return self::ALMACEN_ROUTE_RULES[$resource] ?? 'almacen.almacen';
        }

        if ($module === 'ventas') {
            return self::VENTAS_ROUTE_RULES[$resource] ?? 'ventas';
        }

        if ($module === 'clientes') {
            return self::CLIENTES_ROUTE_RULES[$resource] ?? 'clientes.cliente';
        }

        if ($module === 'configuracion') {
            return self::CONFIGURACION_ROUTE_RULES[$resource] ?? 'configuracion';
        }

        if ($module === 'sistema') {
            return self::SISTEMA_ROUTE_RULES[$resource] ?? 'sistema';
        }

        if ($module === 'lineas-chips' || $module === 'lineas_chips') {
            return self::LINEAS_CHIPS_ROUTE_RULES[$resource] ?? 'lineas_chips';
        }

        return self::FIXED_ROUTE_MODULES[$module] ?? null;
    }

    public static function normalizePermissionKey(?string $module): ?string
    {
        if ($module === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($module));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'inicio' => 'inicio',
            'ticket', 'tickets' => 'tickets',
            'ventas' => 'ventas',
            'ventas.planes_servicios', 'ventas.planes-servicios', 'ventas.planes servicios' => 'ventas.planes_servicios',
            'almacen.planes_servicios', 'almacen.planes-servicios', 'almacen.planes servicios' => 'ventas.planes_servicios',
            'ventas.cotizaciones', 'ventas.cotizacion', 'cotizaciones', 'cotizacion' => 'ventas.cotizaciones',
            'ventas.personal', 'ventas.dni_personal', 'ventas.dnipersonal', 'ventas.dni-personal' => 'ventas.personal',
            'cuentasporcobrar', 'cuentas-por-cobrar', 'cuenta-por-cobrar', 'cuentas por cobrar', 'cuenta por cobrar' => 'cuentasporcobrar',
            'clientes.cliente', 'clientes.clientes', 'clientes.direccion', 'clientes.direcciones', 'clientes.contacto', 'clientes.contactos' => 'clientes.cliente',
            'cliente', 'clientes', 'direccioncliente', 'direccion cliente' => 'clientes',
            'clientes.credenciales', 'clientes.credencial' => 'clientes.credenciales',
            'clientes.grupo_cliente', 'clientes.grupo-cliente', 'clientes.grupo', 'clientes.grupos' => 'clientes.grupo_cliente',
            'servicio-cliente', 'servicio_cliente', 'serviciocliente' => 'servicio_cliente',
            'vehiculo', 'vehiculos' => 'vehiculos',
            'dispositivo_cliente', 'dispositivo-cliente', 'dispositivo cliente' => 'dispositivo_cliente',
            'vehiculos.dispositivo_cliente', 'vehiculos.dispositivo-cliente', 'vehiculos.dispositivo cliente' => 'dispositivo_cliente',
            'lineas_chips', 'lineas-chips', 'lineas chips' => 'lineas_chips',
            'lineas_chips.numero_telefonico', 'lineas_chips.numeros_telefonico', 'lineas_chips.numero-telefonico', 'lineas_chips.numeros-telefonico' => 'lineas_chips.numero_telefonico',
            'lineas_chips.simcard', 'lineas-chips.simcard', 'simcard' => 'lineas_chips.simcard',
            'lineas_chips.detallesimcard', 'lineas-chips.detallesimcard', 'detallesimcard', 'detalle-simcard' => 'lineas_chips.detallesimcard',
            'lineas_chips.numero_dispositivo', 'lineas-chips.numero-dispositivo', 'lineas_chips.numeros_dispositivo', 'lineas-chips.numeros-dispositivo', 'numeros_dispositivo', 'numeros-dispositivo', 'numero_dispositivo', 'numero-dispositivo' => 'lineas_chips.numero_dispositivo',
            'lineas_chips.cargar_numeros', 'lineas-chips.cargar-numeros', 'cargar_numeros', 'cargar-numeros', 'cargar numeros' => 'lineas_chips.cargar_numeros',
            'lineas_chips.bajar_numeros', 'lineas-chips.bajar-numeros', 'bajar_numeros', 'bajar-numeros', 'bajar numeros' => 'lineas_chips.bajar_numeros',
            'almacen' => 'almacen',
            'almacen.almacen' => 'almacen.almacen',
            'almacen.elemento_almacen', 'almacen.elementoalmacen', 'almacen.elemento-almacen' => 'almacen.elemento_almacen',
            'almacen.nota_ingreso', 'almacen.nota-ingreso', 'almacen.nota ingreso' => 'almacen.nota_ingreso',
            'almacen.nota_salida', 'almacen.nota-salida', 'almacen.nota salida' => 'almacen.nota_salida',
            'configuracion', 'configuración', 'settings', 'setting' => 'configuracion',
            'configuracion.estado', 'configuracion.estados', 'configuracion.estado_cliente', 'configuracion.estado-cliente' => 'configuracion.estado',
            'configuracion.tipo_contacto', 'configuracion.tipos_contacto', 'configuracion.tipo-contacto', 'configuracion.tipos-contacto' => 'configuracion.tipo_contacto',
            'configuracion.ubigeo', 'configuracion.ubigeos' => 'configuracion.ubigeo',
            'configuracion.cargo', 'configuracion.cargos' => 'configuracion.cargo',
            'configuracion.auditoria', 'configuracion.audit', 'configuracion.audits' => 'configuracion.auditoria',
            'configuracion.moneda', 'configuracion.monedas' => 'configuracion.moneda',
            'configuracion.tributo', 'configuracion.tributos' => 'configuracion.tributo',
            'configuracion.unidad_medida', 'configuracion.unidad-medida', 'configuracion.unidadmedida' => 'configuracion.unidad_medida',
            'configuracion.marca', 'configuracion.marcas' => 'configuracion.marca',
            'configuracion.empresapropietaria', 'configuracion.empresapropietarias', 'configuracion.empresa-propietaria', 'configuracion.empresa_propietaria', 'empresapropietaria', 'empresa-propietaria', 'empresa_propietaria', 'empresa propietaria' => 'configuracion.empresapropietaria',
            'configuracion.modelo', 'configuracion.modelos', 'modelo', 'modelos' => 'configuracion.modelo',
            'configuracion.tecnologia', 'configuracion.tecnologias' => 'configuracion.tecnologia',
            'configuracion.tipo_gasto', 'configuracion.tipos_gasto', 'configuracion.tipo-gasto', 'configuracion.tipos-gasto' => 'configuracion.tipo_gasto',
            'configuracion.tipo_cobro', 'configuracion.tipos_cobro', 'configuracion.tipo-cobro', 'configuracion.tipos-cobro' => 'configuracion.tipo_cobro',
            'configuracion.operador', 'configuracion.operadores' => 'configuracion.operador',
            'configuracion.tipo_vehiculo', 'configuracion.tipos_vehiculo', 'configuracion.tipo-vehiculo', 'configuracion.tipos-vehiculo' => 'configuracion.tipo_vehiculo',
            'configuracion.tipo_operacion', 'configuracion.tipos_operacion', 'configuracion.tipo-operacion', 'configuracion.tipos-operacion' => 'configuracion.tipo_operacion',
            'configuracion.detalle_lista_precio', 'configuracion.detallelistaprecio', 'configuracion.detalle-lista-precio', 'detalle_lista_precio', 'detallelistaprecio', 'detalle-lista-precio' => 'configuracion.detalle_lista_precio',
            'configuracion.elemento_almacen', 'configuracion.elementoalmacen', 'configuracion.elemento-almacen', 'elemento_almacen', 'elementoalmacen', 'elemento-almacen' => 'almacen.elemento_almacen',
            'configuracion.vista', 'configuracion.vistas', 'vista', 'vistas' => 'configuracion.vista',
            'configuracion.flujo', 'configuracion.flujos', 'flujo', 'flujos' => 'configuracion.flujo',
            'configuracion.flujoregla', 'configuracion.flujoreglas', 'configuracion.flujo-regla', 'configuracion.flujo_regla', 'flujoregla', 'flujoreglas' => 'configuracion.flujoregla',
            'configuracion.historialflujo', 'configuracion.historialflujos', 'configuracion.historial-flujo', 'configuracion.historial_flujo', 'historialflujo', 'historialflujos' => 'configuracion.historialflujo',
            'personal' => 'personal',
            'rol', 'roles' => 'roles',
            'usuario', 'usuarios' => 'usuarios',
            'sistema', 'sistemas' => 'sistema',
            'sistema.vista', 'sistema.vistas' => 'sistema.vista',
            'sistema.flujo', 'sistema.flujos' => 'sistema.flujo',
            'sistema.flujoregla', 'sistema.flujoreglas', 'sistema.flujo-regla', 'sistema.flujo_regla' => 'sistema.flujoregla',
            'sistema.historialflujo', 'sistema.historialflujos', 'sistema.historial-flujo', 'sistema.historial_flujo' => 'sistema.historialflujo',

            'estadocliente', 'estado cliente' => 'configuracion.estado',
            default => self::normalizeCompoundPermissionKey($normalized),
        };
    }

    private static function normalizeCompoundPermissionKey(string $normalized): ?string
    {
        return self::resolvePermissionByRules($normalized, self::COMPOUND_PERMISSION_RULES, null);
    }

    private static function resolvePermissionByRules(string $value, array $rules, ?string $default): ?string
    {
        foreach ($rules as $rule) {
            $prefix = $rule['prefix'] ?? null;
            if ($prefix !== null && !str_starts_with($value, $prefix)) {
                continue;
            }

            $containsAny = $rule['containsAny'] ?? [];
            if ($containsAny !== []) {
                $matched = false;
                foreach ($containsAny as $needle) {
                    if (str_contains($value, $needle)) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            $containsNone = $rule['containsNone'] ?? [];
            $blocked = false;
            foreach ($containsNone as $needle) {
                if (str_contains($value, $needle)) {
                    $blocked = true;
                    break;
                }
            }

            if ($blocked) {
                continue;
            }

            return $rule['permission'] ?? $default;
        }

        return $default;
    }

    public static function normalizeModule(?string $module): ?string
    {
        return self::permissionKeyToModule($module);
    }

    public static function normalizeAction(?string $action): ?string
    {
        if ($action === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($action));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'ver', 'read', 'view', 'index', 'listar', 'list' => 'ver',
            'ver flujo', 'ver_flujo', 'verflujo' => 'ver_flujo',
            'crear', 'create', 'store', 'new' => 'crear',
            'editar', 'edit', 'update', 'actualizar' => 'editar',
            'eliminar', 'delete', 'destroy', 'remove' => 'eliminar',
            'aprobar', 'approve' => 'aprobar',
            'anular', 'cancel', 'canceled', 'cancelar' => 'anular',
            'exportar', 'export', 'download', 'descargar', 'xlsx', 'pdf' => 'exportar',
            default => null,
        };
    }

    public static function normalizeRouteModule(?string $routeName): ?string
    {
        $permissionKey = self::resolvePermissionKeyFromRouteName($routeName);
        return self::permissionKeyToModule($permissionKey);
    }

    public static function inferActionFromRouteName(string $routeName, string $method): ?string
    {
        if (!str_starts_with($routeName, 'modules.')) {
            return null;
        }

        $routeNameLower = mb_strtolower($routeName);
        if (
            str_contains($routeNameLower, 'modules.lineas-chips.detallesimcard.import.')
            || str_contains($routeNameLower, 'modules.lineas-chips.detallesimcard.preview.export')
            || str_contains($routeNameLower, 'modules.lineas-chips.detallesimcard.bulk-deactivate')
        ) {
            return 'ver';
        }

        $segments = explode('.', $routeName);
        $last = mb_strtolower((string) end($segments));

        if (str_starts_with($routeNameLower, 'modules.tickets.vehiculos.')) {
            return 'ver';
        }

        if (str_starts_with($routeNameLower, 'modules.tickets.vehiculos.')) {
            return 'ver';
        }

        if (str_starts_with($routeNameLower, 'modules.tickets.') && in_array($last, ['advance', 'cancel'], true)) {
            return 'ver';
        }

        if (in_array($last, ['store', 'crear-rapido'], true)) {
            return 'crear';
        }

        if (in_array($last, ['create'], true)) {
            return 'crear';
        }

        if (in_array($last, ['edit', 'update', 'actualizar-rapido', 'lock', 'unlock'], true)) {
            return 'editar';
        }

        if (in_array($last, ['destroy', 'eliminar-rapido'], true)) {
            return 'eliminar';
        }

        if (in_array($last, ['approve'], true)) {
            return 'aprobar';
        }

        if (in_array($last, ['anular', 'cancel'], true)) {
            return 'anular';
        }

        if (in_array($last, ['index', 'export', 'opciones', 'lock-status'], true)) {
            return 'ver';
        }

        return match (mb_strtoupper($method)) {
            'POST' => 'crear',
            'PUT', 'PATCH' => 'editar',
            'DELETE' => 'eliminar',
            default => 'ver',
        };
    }

    public static function getDefaultRedirectRoute(array $authData): string
    {
        $roles = collect($authData['roles'] ?? [])->map(fn($role) => mb_strtolower(trim((string) $role)))->filter();
        if ($roles->contains('admin')) {
            return 'home';
        }
        $permissions = collect($authData['permissions'] ?? []);
        if (collect($permissions->get('inicio', []))->contains('ver')) {
            return 'home';
        }
        $moduleRouteMap = [
            'tickets' => 'modules.tickets',
            'ventas.planes_servicios' => 'modules.ventas.planes-servicios.index',
            'cuentasporcobrar' => 'modules.cuentasporcobrar',
            'clientes.cliente' => 'modules.clientes',
            'clientes.grupo_cliente' => 'modules.clientes.grupos.index',
            'servicio_cliente' => 'modules.servicio-cliente',
            'vehiculos' => 'modules.vehiculos',
            'dispositivo_cliente' => 'modules.dispositivo-cliente',
            'lineas_chips.numero_telefonico' => 'modules.lineas-chips.numeros-telefonico.index',
            'almacen.almacen' => 'modules.almacen',
            'configuracion.estado' => 'modules.configuracion.estados.index',
            'personal' => 'modules.personal',
            'roles' => 'modules.roles',
            'usuarios' => 'modules.usuarios',
            'sistema.vista' => 'modules.sistema.vistas.index',
        ];
        foreach ($moduleRouteMap as $permissionKey => $routeName) {
            $parentModule = self::permissionKeyToModule($permissionKey);
            $hasPermission = collect($permissions->get($permissionKey, []))->contains('ver') ||
                ($parentModule && collect($permissions->get($parentModule, []))->contains('ver'));

            if ($parentModule === 'tickets') {
                $hasPermission = collect($permissions->get($permissionKey, []))->intersect(['ver', 'ver_flujo'])->isNotEmpty();
            }
            if ($hasPermission) {
                return $routeName;
            }
        }
        return 'home';
    }
}