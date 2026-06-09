<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RelationContext
{
    private const RESOURCE_MAP = [
        'personal' => ['table' => 'personal', 'primaryKey' => 'dniPersonal', 'label' => 'Personal'],
        'roles' => ['table' => 'rol', 'primaryKey' => 'idrol', 'label' => 'Rol'],
        'usuarios' => ['table' => 'usuario', 'primaryKey' => 'usuario', 'label' => 'Usuario'],
        'vehiculos' => ['table' => 'vehiculo', 'primaryKey' => 'placa', 'label' => 'Vehículo'],
        'dispositivo_cliente' => ['table' => 'dispositivocliente', 'primaryKey' => 'iddispositivoCliente', 'label' => 'Dispositivo cliente'],
        'servicio_cliente' => ['table' => 'serviciocliente', 'primaryKey' => 'idservicioCliente', 'label' => 'Servicio cliente'],
        'clientes.cliente' => ['table' => 'cliente', 'primaryKey' => 'idcliente', 'label' => 'Cliente'],
        'clientes.credenciales' => ['table' => 'credenciales', 'primaryKey' => 'idcredenciales', 'label' => 'Credencial'],
        'clientes.grupo_cliente' => ['table' => 'grupocliente', 'primaryKey' => 'idgrupoCliente', 'label' => 'Grupo de cliente'],
        'clientes.grupos' => ['table' => 'grupocliente', 'primaryKey' => 'idgrupoCliente', 'label' => 'Grupo de cliente'],
        'configuracion.estado' => ['table' => 'estadocliente', 'primaryKey' => 'idestadoCliente', 'label' => 'Estado de cliente'],
        'configuracion.estados' => ['table' => 'estadocliente', 'primaryKey' => 'idestadoCliente', 'label' => 'Estado de cliente'],
        'configuracion.tipo_contacto' => ['table' => 'tipocontacto', 'primaryKey' => 'idtipoContacto', 'label' => 'Tipo de contacto'],
        'configuracion.tipos-contacto' => ['table' => 'tipocontacto', 'primaryKey' => 'idtipoContacto', 'label' => 'Tipo de contacto'],
        'configuracion.ubigeo' => ['table' => 'ubigeo', 'primaryKey' => 'idubigeo', 'label' => 'Ubigeo'],
        'configuracion.ubigeos' => ['table' => 'ubigeo', 'primaryKey' => 'idubigeo', 'label' => 'Ubigeo'],
        'configuracion.cargo' => ['table' => 'cargopersonal', 'primaryKey' => 'idcargoPersonal', 'label' => 'Cargo de personal'],
        'configuracion.cargos' => ['table' => 'cargopersonal', 'primaryKey' => 'idcargoPersonal', 'label' => 'Cargo de personal'],
        'configuracion.moneda' => ['table' => 'moneda', 'primaryKey' => 'idmoneda', 'label' => 'Moneda'],
        'configuracion.monedas' => ['table' => 'moneda', 'primaryKey' => 'idmoneda', 'label' => 'Moneda'],
        'configuracion.tributo' => ['table' => 'tributo', 'primaryKey' => 'idtributo', 'label' => 'Tributo'],
        'configuracion.tributos' => ['table' => 'tributo', 'primaryKey' => 'idtributo', 'label' => 'Tributo'],
        'configuracion.unidad_medida' => ['table' => 'unidadmedida', 'primaryKey' => 'idunidadMedida', 'label' => 'Unidad de medida'],
        'configuracion.unidad-medida' => ['table' => 'unidadmedida', 'primaryKey' => 'idunidadMedida', 'label' => 'Unidad de medida'],
        'configuracion.empresapropietaria' => ['table' => 'empresapropietaria', 'primaryKey' => 'RUC', 'label' => 'Empresa propietaria'],
        'configuracion.modelo' => ['table' => 'modelo', 'primaryKey' => 'idmodelo', 'label' => 'Modelo'],
        'configuracion.marca' => ['table' => 'marca', 'primaryKey' => 'idmarca', 'label' => 'Marca'],
        'configuracion.tecnologia' => ['table' => 'tecnologia', 'primaryKey' => 'idtecnologia', 'label' => 'Tecnología'],
        'configuracion.tipo_gasto' => ['table' => 'tipogasto', 'primaryKey' => 'idtipoGasto', 'label' => 'Tipo de gasto'],
        'configuracion.tipos-gasto' => ['table' => 'tipogasto', 'primaryKey' => 'idtipoGasto', 'label' => 'Tipo de gasto'],
        'configuracion.tipo_cobro' => ['table' => 'tipocobro', 'primaryKey' => 'idtipoCobros', 'label' => 'Tipo de cobro'],
        'configuracion.tipos-cobro' => ['table' => 'tipocobro', 'primaryKey' => 'idtipoCobros', 'label' => 'Tipo de cobro'],
        'configuracion.tipo_plataforma' => ['table' => 'tipoplataforma', 'primaryKey' => 'idtipoPlataforma', 'label' => 'Tipo de plataforma'],
        'configuracion.tipos-plataforma' => ['table' => 'tipoplataforma', 'primaryKey' => 'idtipoPlataforma', 'label' => 'Tipo de plataforma'],
        'configuracion.plataforma' => ['table' => 'plataforma', 'primaryKey' => 'idplataforma', 'label' => 'Plataforma'],
        'configuracion.tipo_elemento' => ['table' => 'tipoelemento', 'primaryKey' => 'idtipoElemento', 'label' => 'Tipo de elemento'],
        'configuracion.tipos-elemento' => ['table' => 'tipoelemento', 'primaryKey' => 'idtipoElemento', 'label' => 'Tipo de elemento'],
        'configuracion.tipo_documento' => ['table' => 'tipodocumento', 'primaryKey' => 'idtipoDocumento', 'label' => 'Tipo de documento'],
        'configuracion.tipos-documento' => ['table' => 'tipodocumento', 'primaryKey' => 'idtipoDocumento', 'label' => 'Tipo de documento'],
        'configuracion.forma_pago' => ['table' => 'formapago', 'primaryKey' => 'idformaPago', 'label' => 'Forma de pago'],
        'configuracion.formas-pago' => ['table' => 'formapago', 'primaryKey' => 'idformaPago', 'label' => 'Forma de pago'],
        'configuracion.entidad_bancaria' => ['table' => 'entidadbancaria', 'primaryKey' => 'identidadBancaria', 'label' => 'Entidad bancaria'],
        'configuracion.entidades-bancarias' => ['table' => 'entidadbancaria', 'primaryKey' => 'identidadBancaria', 'label' => 'Entidad bancaria'],
        'configuracion.operador' => ['table' => 'operador', 'primaryKey' => 'idoperador', 'label' => 'Operador'],
        'configuracion.operadores' => ['table' => 'operador', 'primaryKey' => 'idoperador', 'label' => 'Operador'],
        'configuracion.tipo_vehiculo' => ['table' => 'tipovehiculo', 'primaryKey' => 'idtipoVehiculo', 'label' => 'Tipo de vehículo'],
        'configuracion.tipos-vehiculo' => ['table' => 'tipovehiculo', 'primaryKey' => 'idtipoVehiculo', 'label' => 'Tipo de vehículo'],
        'configuracion.tipo_operacion' => ['table' => 'tipooperacion', 'primaryKey' => 'idtipoOperacion', 'label' => 'Tipo de operación'],
        'configuracion.tipos-operacion' => ['table' => 'tipooperacion', 'primaryKey' => 'idtipoOperacion', 'label' => 'Tipo de operación'],
        'configuracion.lista_precio' => ['table' => 'listaprecio', 'primaryKey' => 'idlistaPrecio', 'label' => 'Lista de precio'],
        'configuracion.listas-precio' => ['table' => 'listaprecio', 'primaryKey' => 'idlistaPrecio', 'label' => 'Lista de precio'],
        'configuracion.detalle_lista_precio' => ['table' => 'detallelistaprecio', 'primaryKey' => 'iddetalleListaPrecio', 'label' => 'Detalle de lista de precio'],
        'configuracion.detalle-lista-precio' => ['table' => 'detallelistaprecio', 'primaryKey' => 'iddetalleListaPrecio', 'label' => 'Detalle de lista de precio'],
        'almacen.elemento_almacen' => ['table' => 'elementoalmacen', 'primaryKey' => 'imei', 'label' => 'Elemento de almacén'],
        'almacen.elemento-almacen' => ['table' => 'elementoalmacen', 'primaryKey' => 'imei', 'label' => 'Elemento de almacén'],
        'almacen.nota_ingreso' => ['table' => 'compras', 'primaryKey' => 'idcompras', 'label' => 'Nota de ingreso'],
        'almacen.nota_salida' => ['table' => 'compras', 'primaryKey' => 'idcompras', 'label' => 'Nota de salida'],
        'configuracion.tipo_pedido' => ['table' => 'tipopedido', 'primaryKey' => 'idtipoPedido', 'label' => 'Tipo de pedido'],
        'configuracion.tipos-pedido' => ['table' => 'tipopedido', 'primaryKey' => 'idtipoPedido', 'label' => 'Tipo de pedido'],
        'configuracion.proveedor' => ['table' => 'proveedor', 'primaryKey' => 'idproveedor', 'label' => 'Proveedor'],
        'configuracion.proveedores' => ['table' => 'proveedor', 'primaryKey' => 'idproveedor', 'label' => 'Proveedor'],
        'configuracion.certificadosunat' => ['table' => 'certificadosunat', 'primaryKey' => 'idcertificadosSunat', 'label' => 'Certificado SUNAT'],
        'configuracion.certificados-sunat' => ['table' => 'certificadosunat', 'primaryKey' => 'idcertificadosSunat', 'label' => 'Certificado SUNAT'],
        'configuracion.vigencia_oferta' => ['table' => 'vigenciaoferta', 'primaryKey' => 'idvigenciaOferta', 'label' => 'Vigencia de oferta'],
        'configuracion.vigencias-oferta' => ['table' => 'vigenciaoferta', 'primaryKey' => 'idvigenciaOferta', 'label' => 'Vigencia de oferta'],
        'lineas_chips.numero_telefonico' => ['table' => 'numerotelefonico', 'primaryKey' => 'numeroTelefonico', 'label' => 'Número telefónico'],
        'lineas-chips.numeros-telefonico' => ['table' => 'numerotelefonico', 'primaryKey' => 'numeroTelefonico', 'label' => 'Número telefónico'],
        'lineas_chips.simcard' => ['table' => 'simcard', 'primaryKey' => 'idsimcard', 'label' => 'Simcard'],
        'lineas-chips.simcard' => ['table' => 'simcard', 'primaryKey' => 'idsimcard', 'label' => 'Simcard'],
        'lineas_chips.detallesimcard' => ['table' => 'detallesimcard', 'primaryKey' => 'iddetallesimcard', 'label' => 'Asignación simcard'],
        'lineas-chips.detallesimcard' => ['table' => 'detallesimcard', 'primaryKey' => 'iddetallesimcard', 'label' => 'Asignación simcard'],
        'lineas_chips.numero_dispositivo' => ['table' => 'detnumerosdispositivo', 'primaryKey' => 'iddetNumerosDispositivo', 'label' => 'Número de dispositivo'],
        'lineas-chips.numeros-dispositivo' => ['table' => 'detnumerosdispositivo', 'primaryKey' => 'iddetNumerosDispositivo', 'label' => 'Número de dispositivo'],
    ];

    private const TABLE_LABELS = [
        'rol' => ['label' => 'Rol', 'primaryKey' => 'idrol', 'previewColumns' => ['nombre']],
        'cliente' => ['label' => 'Cliente', 'primaryKey' => 'idcliente', 'previewColumns' => ['idcliente', 'nombreComercial', 'razonSocial']],
        'credenciales' => ['label' => 'Credencial', 'primaryKey' => 'idcredenciales', 'previewColumns' => ['idcredenciales', 'usuario', 'correo']],
        'grupocliente' => ['label' => 'Grupo de cliente', 'primaryKey' => 'idgrupoCliente', 'previewColumns' => ['nombreGrupo', 'idgrupoCliente']],
        'vehiculo' => ['label' => 'Vehículo', 'primaryKey' => 'placa', 'previewColumns' => ['placa', 'marca', 'modelo']],
        'dispositivocliente' => ['label' => 'Dispositivo cliente', 'primaryKey' => 'iddispositivoCliente', 'previewColumns' => ['iddispositivoCliente']],
        'serviciocliente' => ['label' => 'Servicio cliente', 'primaryKey' => 'idservicioCliente', 'previewColumns' => ['idservicioCliente']],
        'estadocliente' => ['label' => 'Estado de cliente', 'primaryKey' => 'idestadoCliente', 'previewColumns' => ['idestadoCliente', 'detalle']],
        'tipocontacto' => ['label' => 'Tipo de contacto', 'primaryKey' => 'idtipoContacto', 'previewColumns' => ['idtipoContacto', 'nombre']],
        'ubigeo' => ['label' => 'Ubigeo', 'primaryKey' => 'idubigeo', 'previewColumns' => ['idubigeo', 'departamento', 'provincia', 'distrito']],
        'cargopersonal' => ['label' => 'Cargo de personal', 'primaryKey' => 'idcargoPersonal', 'previewColumns' => ['descripcion']],
        'moneda' => ['label' => 'Moneda', 'primaryKey' => 'idmoneda', 'previewColumns' => ['idmoneda', 'nombre']],
        'tributo' => ['label' => 'Tributo', 'primaryKey' => 'idtributo', 'previewColumns' => ['idtributo', 'nombreTributo']],
        'unidadmedida' => ['label' => 'Unidad de medida', 'primaryKey' => 'idunidadMedida', 'previewColumns' => ['idunidadMedida', 'detalle', 'nomenclatura']],
        'empresapropietaria' => ['label' => 'Empresa propietaria', 'primaryKey' => 'RUC', 'previewColumns' => ['RUC', 'razonSocial', 'rubro']],
        'modelo' => ['label' => 'Modelo', 'primaryKey' => 'idmodelo', 'previewColumns' => ['idmodelo', 'nombreModelo']],
        'marca' => ['label' => 'Marca', 'primaryKey' => 'idmarca', 'previewColumns' => ['idmarca', 'nombre']],
        'tecnologia' => ['label' => 'Tecnología', 'primaryKey' => 'idtecnologia', 'previewColumns' => ['idtecnologia', 'nombreTecnologia', 'detalle' ]],
        'tipogasto' => ['label' => 'Tipo de gasto', 'primaryKey' => 'idtipoGasto', 'previewColumns' => ['idtipoGasto', 'nombre']],
        'tipocobro' => ['label' => 'Tipo de cobro', 'primaryKey' => 'idtipoCobros', 'previewColumns' => ['idtipoCobros', 'nombre', 'recurrencia']],
        'tipoplataforma' => ['label' => 'Tipo de plataforma', 'primaryKey' => 'idtipoPlataforma', 'previewColumns' => ['idtipoPlataforma', 'nombre']],
        'plataforma' => ['label' => 'Plataforma', 'primaryKey' => 'idplataforma', 'previewColumns' => ['idplataforma', 'nombrePlataforma']],
        'tipoelemento' => ['label' => 'Tipo de elemento', 'primaryKey' => 'idtipoElemento', 'previewColumns' => ['idtipoElemento', 'nombre']],
        'tipodocumento' => ['label' => 'Tipo de documento', 'primaryKey' => 'idtipoDocumento', 'previewColumns' => ['idtipoDocumento', 'nombre']],
        'formapago' => ['label' => 'Forma de pago', 'primaryKey' => 'idformaPago', 'previewColumns' => ['idformaPago', 'nombre']],
        'entidadbancaria' => ['label' => 'Entidad bancaria', 'primaryKey' => 'identidadBancaria', 'previewColumns' => ['identidadBancaria', 'nombre']],
        'operador' => ['label' => 'Operador', 'primaryKey' => 'idoperador', 'previewColumns' => ['idoperador', 'nombre']],
        'tipovehiculo' => ['label' => 'Tipo de vehículo', 'primaryKey' => 'idtipoVehiculo', 'previewColumns' => ['idtipoVehiculo', 'nombre']],
        'tipooperacion' => ['label' => 'Tipo de operación', 'primaryKey' => 'idtipoOperacion', 'previewColumns' => ['idtipoOperacion', 'nombre']],
        'listaprecio' => ['label' => 'Lista de precio', 'primaryKey' => 'idlistaPrecio', 'previewColumns' => ['idlistaPrecio', 'nombreLista']],
        'detallelistaprecio' => ['label' => 'Detalle de lista de precio', 'primaryKey' => 'iddetalleListaPrecio', 'previewColumns' => ['almacen_idalmacen', 'ListaPrecio_idListaPrecio', 'precio']],
        'elementoalmacen' => ['label' => 'Elemento de almacén', 'primaryKey' => 'imei', 'previewColumns' => ['imei', 'dispositivo_iddispositivo']],
        'tipopedido' => ['label' => 'Tipo de pedido', 'primaryKey' => 'idtipoPedido', 'previewColumns' => ['idtipoPedido', 'nomenclatura']],
        'proveedor' => ['label' => 'Proveedor', 'primaryKey' => 'idproveedor', 'previewColumns' => ['idproveedor', 'razonSocial', 'nombreComercial']],
        'certificadosunat' => ['label' => 'Certificado SUNAT', 'primaryKey' => 'idcertificadosSunat', 'previewColumns' => ['idcertificadosSunat', 'nombre']],
        'vigenciaoferta' => ['label' => 'Vigencia de oferta', 'primaryKey' => 'idvigenciaOferta', 'previewColumns' => ['idvigenciaOferta', 'nombre']],
        'numerotelefonico' => ['label' => 'Número telefónico', 'primaryKey' => 'numeroTelefonico', 'previewColumns' => ['numeroTelefonico']],
        'simcard' => ['label' => 'Simcard', 'primaryKey' => 'idsimcard', 'previewColumns' => ['idsimcard', 'numeroSimCard']],
        'detallesimcard' => ['label' => 'Asignación simcard', 'primaryKey' => 'iddetallesimcard', 'previewColumns' => ['numeroTelefonico_numeroTelefonico', 'simCard_idsimCard']],
        'numerodispositivo' => ['label' => 'Número de dispositivo', 'primaryKey' => 'idnumeroDispositivo', 'previewColumns' => ['numeroDispositivo', 'idnumeroDispositivo']],
        'detnumerosdispositivo' => ['label' => 'Números de dispositivo', 'primaryKey' => 'iddetNumerosDispositivo', 'previewColumns' => ['dispositivoCliente_iddispositivoCliente', 'numeroTelefonico_numeroTelefonico']],
        'detallegrupocliente' => ['label' => 'Cliente en grupo', 'primaryKey' => 'iddetalleGrupoCliente', 'previewColumns' => ['cliente_idcliente', 'nombreComercial', 'razonSocial']],
    ];

    public static function summarize(string $resource, string $recordId): array
    {
        $context = self::resolveResourceContext($resource);
        if ($context === null) {
            return [
                'resource' => $resource,
                'recordId' => $recordId,
                'recordLabel' => null,
                'relations' => [],
            ];
        }

        $database = DB::getDatabaseName();
        $relations = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select(['TABLE_NAME', 'COLUMN_NAME'])
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('REFERENCED_TABLE_SCHEMA', $database)
            ->where('REFERENCED_TABLE_NAME', $context['table'])
            ->where('REFERENCED_COLUMN_NAME', $context['primaryKey'])
            ->distinct()
            ->orderBy('TABLE_NAME')
            ->get();

        $resolvedRelations = [];

        foreach ($relations as $relation) {
            $table = (string) $relation->TABLE_NAME;
            $column = (string) $relation->COLUMN_NAME;

            $relationQuery = DB::table($table)->where($column, $recordId);
            $previewQuery = DB::table($table)->where($column, $recordId);

            if ($context['table'] === 'numerotelefonico' && $table === 'detallesimcard' && $column === 'numeroTelefonico_numeroTelefonico') {
                $relationQuery = $relationQuery->where('estado', '0');
                $previewQuery = $previewQuery->where('estado', '0');
            }

            if ($context['table'] === 'simcard' && $table === 'detallesimcard' && $column === 'simCard_idsimCard') {
                $relationQuery = $relationQuery->where('estado', '0');
                $previewQuery = $previewQuery->where('estado', '0');
            }

            if ($context['table'] === 'dispositivocliente' && $table === 'detnumerosdispositivo' && $column === 'dispositivoCliente_iddispositivoCliente') {
                $latestActivePerDevice = DB::table('detnumerosdispositivo as d2')
                    ->leftJoin('numerotelefonico as n2', 'n2.numeroTelefonico', '=', 'd2.numeroTelefonico_numeroTelefonico')
                    ->select('d2.dispositivoCliente_iddispositivoCliente', DB::raw('MAX(d2.fechaAsignacion) as max_fecha'))
                    ->where('n2.estado', '1')
                    ->groupBy('d2.dispositivoCliente_iddispositivoCliente');

                $relationQuery = DB::table('detnumerosdispositivo as d')
                    ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico')
                    ->joinSub($latestActivePerDevice, 'latest', function ($join) {
                        $join->on('latest.dispositivoCliente_iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente');
                        $join->on('latest.max_fecha', '=', 'd.fechaAsignacion');
                    })
                    ->where('d.dispositivoCliente_iddispositivoCliente', $recordId)
                    ->where('n.estado', '1');

                $previewQuery = DB::table('detnumerosdispositivo as d')
                    ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico')
                    ->joinSub($latestActivePerDevice, 'latest', function ($join) {
                        $join->on('latest.dispositivoCliente_iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente');
                        $join->on('latest.max_fecha', '=', 'd.fechaAsignacion');
                    })
                    ->where('d.dispositivoCliente_iddispositivoCliente', $recordId)
                    ->where('n.estado', '1');
            }

            $count = (int) $relationQuery->count();

            if ($count <= 0) {
                continue;
            }

            $previewRows = $previewQuery
                ->limit(5)
                ->get();

            // Crear labels y calcular timestamps para detectar el registro vigente (máxima fechaAsignacion)
            $labels = [];
            $timestamps = [];
            foreach ($previewRows as $pr) {
                $labels[] = self::formatPreviewRecord($table, $pr);
                $f = data_get($pr, 'fechaAsignacion') ?? data_get($pr, 'fecha_asignacion') ?? null;
                $ts = null;
                if (!empty($f)) {
                    $ts = strtotime($f);
                    if ($ts === false) $ts = null;
                }
                $timestamps[] = $ts;
            }

            $vigenteIndex = null;
            if (!empty(array_filter($timestamps, fn($t) => $t !== null))) {
                $validTs = array_filter($timestamps, fn($t) => $t !== null);
                $maxTs = max($validTs);
                foreach ($timestamps as $i => $t) {
                    if ($t !== null && $t === $maxTs) {
                        $vigenteIndex = $i;
                        break;
                    }
                }
            }

            $resolvedRelations[] = [
                'table' => $table,
                'column' => $column,
                'label' => self::tableLabel($table),
                'count' => $count,
                'records' => array_values(array_filter($labels, fn($v) => trim((string)$v) !== '')),
                'vigente_index' => $vigenteIndex,
                'vigente_label' => ($vigenteIndex !== null && isset($labels[$vigenteIndex])) ? $labels[$vigenteIndex] : null,
            ];
        }

        $recordDetails = [];
        $extraRelations = [];
        $deleteActions = [];

        try {
            $record = DB::table($context['table'])
                ->where($context['primaryKey'], $recordId)
                ->first();

            if ($record) {
                $recordDetails = self::buildRecordDetails($context['table'], $record);
                $deleteActions = self::buildDeleteActions($context['table'], $recordId, $record);

                if ($context['table'] === 'detallesimcard') {
                    $numero = trim((string) data_get($record, 'numeroTelefonico_numeroTelefonico', ''));
                    if ($numero !== '') {
                        $deviceRow = DB::table('detnumerosdispositivo as d')
                            ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico')
                            ->where('d.numeroTelefonico_numeroTelefonico', $numero)
                            ->where('n.estado', '1')
                            ->orderByDesc('d.fechaAsignacion')
                            ->orderByDesc('d.iddetNumerosDispositivo')
                            ->first();

                        if ($deviceRow) {
                            $extraRelations[] = [
                                'table' => 'detnumerosdispositivo',
                                'column' => 'numeroTelefonico_numeroTelefonico',
                                'label' => self::tableLabel('detnumerosdispositivo'),
                                'count' => 1,
                                'records' => [self::formatPreviewRecord('detnumerosdispositivo', $deviceRow)],
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // preserve existing relations if detail lookup fails
        }

        $resolvedRelations = array_merge($resolvedRelations, $extraRelations);

        return [
            'resource' => $resource,
            'recordId' => $recordId,
            'recordLabel' => self::resolveRecordLabel($context, $recordId),
            'details' => $recordDetails,
            'relations' => $resolvedRelations,
            'deleteActions' => $deleteActions,
        ];
    }

    public static function resolveResourceContext(string $resource): ?array
    {
        $normalized = mb_strtolower(trim($resource));
        if ($normalized === '') {
            return null;
        }

        if (isset(self::RESOURCE_MAP[$normalized])) {
            return self::RESOURCE_MAP[$normalized];
        }

        $base = $normalized;
        if (str_contains($base, '.')) {
            $base = (string) Str::of($base)->afterLast('.');
        }

        $base = str_replace(['-', '_'], '', $base);
        if ($base === '') {
            return null;
        }

        $singular = (string) Str::singular($base);
        $tableInfo = self::TABLE_LABELS[$singular] ?? null;
        $primaryKey = $tableInfo['primaryKey'] ?? ('id' . Str::studly($singular));

        return [
            'table' => $singular,
            'primaryKey' => $primaryKey,
            'label' => self::guessLabelFromResource($normalized),
        ];
    }

    private static function resolveRecordLabel(array $context, string $recordId): string
    {
        try {
            $record = DB::table($context['table'])
                ->where($context['primaryKey'], $recordId)
                ->first();

            if (!$record) {
                return (string) $recordId;
            }

            // Caso especial: detallegrupocliente debe mostrar el nombre del cliente
            if ($context['table'] === 'detallegrupocliente') {
                $clientId = data_get($record, 'cliente_idcliente');
                if ($clientId) {
                    $client = DB::table('cliente')
                        ->where('idcliente', $clientId)
                        ->first();
                    if ($client) {
                        $nombreComercial = data_get($client, 'nombreComercial');
                        $razonSocial = data_get($client, 'razonSocial');
                        if ($nombreComercial) {
                            return (string) $nombreComercial;
                        }
                        if ($razonSocial) {
                            return (string) $razonSocial;
                        }
                    }
                }
                return (string) $clientId;
            }

                if ($context['table'] === 'detallelistaprecio') {
                    $almacenId = data_get($record, 'almacen_idalmacen');
                    $listaPrecioId = data_get($record, 'ListaPrecio_idListaPrecio');
                    $precio = trim((string) data_get($record, 'precio', ''));

                    $parts = [];

                    if ($almacenId !== null && $almacenId !== '') {
                        $almacen = DB::table('almacen')->where('idalmacen', $almacenId)->first();
                        if ($almacen) {
                            $almacenLabel = trim((string) data_get($almacen, 'detalle', ''));
                            if ($almacenLabel === '') {
                                $almacenLabel = trim((string) data_get($almacen, 'idalmacen', ''));
                            }
                            if ($almacenLabel !== '') {
                                $parts[] = $almacenLabel;
                            }
                        }
                    }

                    if ($listaPrecioId !== null && $listaPrecioId !== '') {
                        $listaPrecio = DB::table('listaprecio')->where('idListaPrecio', $listaPrecioId)->first();
                        if ($listaPrecio) {
                            $listaPrecioLabel = trim((string) data_get($listaPrecio, 'nombreLista', ''));
                            if ($listaPrecioLabel === '') {
                                $listaPrecioLabel = trim((string) data_get($listaPrecio, 'idListaPrecio', ''));
                            }
                            if ($listaPrecioLabel !== '') {
                                $parts[] = $listaPrecioLabel;
                            }
                        }
                    }

                    if ($precio !== '') {
                        $parts[] = $precio;
                    }

                    if (!empty($parts)) {
                        return implode(' - ', $parts);
                    }

                    return (string) data_get($record, 'iddetalleListaPrecio', '');
                }

            $preferredColumns = [
                'nombre',
                'nombreTecnologia',
                'nombreLista',
                'imei',
                'nombreComercial',
                'razonSocial',
                'detalle',
                'nomenclatura',
                'nombreMarca',
                'nombreTributo',
                'descripcion',
                'titulo',
                'usuario',
                'recurrencia',
                'correo',
                'placa',
                'numeroTelefonico',
                'numeroDispositivo',
                'nombreGrupo',
            ];

            foreach ($preferredColumns as $column) {
                $value = data_get($record, $column);
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }

            $tableInfo = self::TABLE_LABELS[$context['table']] ?? null;
            $previewColumns = $tableInfo['previewColumns'] ?? [$context['primaryKey']];
            $parts = [];
            $idPart = null;

            foreach ($previewColumns as $column) {
                $value = data_get($record, $column);
                if ($value === null || $value === '') {
                    continue;
                }

                $lowerColumn = Str::lower((string) $column);
                if ($column === $context['primaryKey'] || Str::startsWith($lowerColumn, 'id')) {
                    if ($idPart === null) {
                        $idPart = (string) $value;
                    }
                    continue;
                }

                $parts[] = (string) $value;
            }

            if (!empty($parts)) {
                return implode(' - ', array_unique($parts));
            }

            if ($idPart !== null && $idPart !== '') {
                return $idPart;
            }

            return (string) $recordId;
        } catch (\Throwable) {
            return (string) $recordId;
        }
    }

    private static function buildRecordDetails(string $table, object $record): array
    {
        if ($table === 'detnumerosdispositivo') {
            $deviceId = data_get($record, 'dispositivoCliente_iddispositivoCliente');
            $deviceLabel = '';

            if ($deviceId !== null && $deviceId !== '') {
                $device = DB::table('dispositivocliente')->where('iddispositivoCliente', $deviceId)->first();
                if ($device) {
                    $vehicle = trim((string) data_get($device, 'vehiculo_placa', ''));
                    $brand = trim((string) data_get($device, 'marcaDispositivo', ''));
                    $model = trim((string) data_get($device, 'modeloDispositivo', ''));
                    $deviceLabel = trim(implode(' ', array_filter([$vehicle, $brand, $model], fn ($part) => $part !== '')));
                }
                if ($deviceLabel === '') {
                    $deviceLabel = (string) $deviceId;
                }
            }

            return array_filter([
                [
                    'label' => 'Número telefónico',
                    'value' => (string) data_get($record, 'numeroTelefonico_numeroTelefonico', ''),
                ],
                [
                    'label' => 'Dispositivo',
                    'value' => $deviceLabel,
                ],
            ], fn ($item) => trim($item['value']) !== '');
        }

        if ($table === 'detallesimcard') {
            $simcardId = data_get($record, 'simCard_idsimCard');
            $simcardLabel = '';

            if ($simcardId !== null && $simcardId !== '') {
                $simcard = DB::table('simcard')->where('idsimcard', $simcardId)->first();
                if ($simcard) {
                    $simcardLabel = trim((string) data_get($simcard, 'numeroSimCard', ''));
                }
                if ($simcardLabel === '') {
                    $simcardLabel = (string) $simcardId;
                }
            }

            return array_filter([
                [
                    'label' => 'Número telefónico',
                    'value' => (string) data_get($record, 'numeroTelefonico_numeroTelefonico', ''),
                ],
                [
                    'label' => 'SimCard',
                    'value' => $simcardLabel,
                ],
            ], fn ($item) => trim($item['value']) !== '');
        }

        return [];
    }

    private static function formatPreviewRecord(string $table, object $record): string
    {
        if ($table === 'personal') {
            $nombre = trim((string) data_get($record, 'nombre', ''));
            $apellido = trim((string) data_get($record, 'apellido', ''));
            $dni = trim((string) data_get($record, 'dniPersonal', ''));

            $parts = array_filter([$nombre, $apellido]);
            $namePart = implode(' ', $parts);
            $suffix = $dni !== '' ? (' - ' . $dni) : '';

            if ($namePart === '') {
                return $dni ?: '';
            }

            return $namePart . $suffix;
        }

        if ($table === 'detallegrupocliente') {
            $clientId = data_get($record, 'cliente_idcliente');
            if ($clientId) {
                $client = DB::table('cliente')
                    ->where('idcliente', $clientId)
                    ->first();
                if ($client) {
                    $nombreComercial = data_get($client, 'nombreComercial');
                    $razonSocial = data_get($client, 'razonSocial');
                    if ($nombreComercial) {
                        return (string) $nombreComercial;
                    }
                    if ($razonSocial) {
                        return (string) $razonSocial;
                    }
                }
            }

            return (string) $clientId;
        }

        if ($table === 'detallelistaprecio') {
            $almacenId = data_get($record, 'almacen_idalmacen');
            $listaPrecioId = data_get($record, 'ListaPrecio_idListaPrecio');
            $precio = trim((string) data_get($record, 'precio', ''));

            $parts = [];

            if ($almacenId !== null && $almacenId !== '') {
                $almacen = DB::table('almacen')->where('idalmacen', $almacenId)->first();
                if ($almacen) {
                    $almacenLabel = trim((string) data_get($almacen, 'detalle', ''));
                    if ($almacenLabel === '') {
                        $almacenLabel = trim((string) data_get($almacen, 'idalmacen', ''));
                    }
                    if ($almacenLabel !== '') {
                        $parts[] = $almacenLabel;
                    }
                }
            }

            if ($listaPrecioId !== null && $listaPrecioId !== '') {
                $listaPrecio = DB::table('listaprecio')->where('idListaPrecio', $listaPrecioId)->first();
                if ($listaPrecio) {
                    $listaPrecioLabel = trim((string) data_get($listaPrecio, 'nombreLista', ''));
                    if ($listaPrecioLabel === '') {
                        $listaPrecioLabel = trim((string) data_get($listaPrecio, 'idListaPrecio', ''));
                    }
                    if ($listaPrecioLabel !== '') {
                        $parts[] = $listaPrecioLabel;
                    }
                }
            }

            if ($precio !== '') {
                $parts[] = $precio;
            }

            if (!empty($parts)) {
                return implode(' - ', $parts);
            }

            return (string) data_get($record, 'iddetalleListaPrecio', '');
        }

        if ($table === 'elementoalmacen') {
            $imei = trim((string) data_get($record, 'imei', ''));
            $dispositivoId = data_get($record, 'dispositivo_iddispositivo');
            $dispositivoLabel = '';

            if ($dispositivoId !== null && $dispositivoId !== '') {
                $dispositivo = DB::table('almacen')
                    ->where('idalmacen', $dispositivoId)
                    ->first();

                if ($dispositivo) {
                    $dispositivoLabel = trim((string) data_get($dispositivo, 'detalle', ''));
                    if ($dispositivoLabel === '') {
                        $dispositivoLabel = trim((string) data_get($dispositivo, 'idalmacen', ''));
                    }
                }
            }

            $parts = array_values(array_filter([$imei, $dispositivoLabel], fn ($part) => $part !== ''));

            if (!empty($parts)) {
                return implode(' - ', $parts);
            }

            return $imei !== '' ? $imei : (string) data_get($record, 'dispositivo_iddispositivo', '');
        }

        $tableInfo = self::TABLE_LABELS[$table] ?? null;
        $columns = $tableInfo['previewColumns'] ?? array_keys((array) $record);
        $primaryKey = $tableInfo['primaryKey'] ?? null;
        $parts = [];

        foreach ($columns as $column) {
            // skip primary key and likely identifier columns (start with 'id' or contain '_id')
            $lowerColumn = Str::lower((string) $column);
            if ($primaryKey !== null && $column === $primaryKey) {
                continue;
            }
            if (Str::startsWith($lowerColumn, 'id') || Str::contains($lowerColumn, '_id')) {
                continue;
            }

            // skip renovation column which often contains 0 and isn't useful in previews
            if ($lowerColumn === 'renovacion') {
                continue;
            }

            $value = data_get($record, $column);
            if ($value !== null && $value !== '') {
                $parts[] = (string) $value;
            }
        }

        if (empty($parts)) {
            return '';
        }

        return implode(' - ', array_unique($parts));
    }

    private static function buildDeleteActions(string $table, string $recordId, object $record): array
    {
        if ($table === 'numerotelefonico') {
            $pastHistory = DB::table('detallesimcard')
                ->where('numeroTelefonico_numeroTelefonico', $recordId)
                ->where('estado', '1')
                ->count();

            $deviceHistory = DB::table('detnumerosdispositivo')
                ->where('numeroTelefonico_numeroTelefonico', $recordId)
                ->count();

            if ($pastHistory > 0 || $deviceHistory > 0) {
                return [];
            }

            $currentDetail = DB::table('detallesimcard')
                ->where('numeroTelefonico_numeroTelefonico', $recordId)
                ->where('estado', '0')
                ->orderByDesc('iddetalleSimCard')
                ->first();

            if (!$currentDetail) {
                return [];
            }

            $simCardId = trim((string) ($currentDetail->simCard_idsimCard ?? ''));
            if ($simCardId === '' || self::hasSimCardPastHistory($simCardId)) {
                return [];
            }

            return [[
                'mode' => 'delete_with_simcard',
                'label' => 'Eliminar con SimCard',
                'description' => 'Elimina el número y la SimCard relacionada.',
            ]];
        }

        if ($table === 'simcard') {
            $pastHistory = DB::table('detallesimcard')
                ->where('simCard_idsimCard', $recordId)
                ->where('estado', '1')
                ->count();

            if ($pastHistory > 0) {
                return [];
            }

            $currentDetail = DB::table('detallesimcard')
                ->where('simCard_idsimCard', $recordId)
                ->where('estado', '0')
                ->orderByDesc('iddetalleSimCard')
                ->first();

            if (!$currentDetail) {
                return [];
            }

            $numeroTelefonico = trim((string) ($currentDetail->numeroTelefonico_numeroTelefonico ?? ''));
            if ($numeroTelefonico === '' || self::hasNumeroPastHistory($numeroTelefonico)) {
                return [];
            }

            if (DB::table('detnumerosdispositivo')->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)->count() > 0) {
                return [];
            }

            return [[
                'mode' => 'delete_with_number',
                'label' => 'Eliminar con Número',
                'description' => 'Elimina la SimCard y el número relacionado.',
            ]];
        }

        if ($table === 'detallesimcard') {
            if (trim((string) data_get($record, 'estado', '')) !== '0') {
                return [];
            }

            $simCardId = trim((string) data_get($record, 'simCard_idsimCard', ''));
            $numeroTelefonico = trim((string) data_get($record, 'numeroTelefonico_numeroTelefonico', ''));

            if ($simCardId === '' || $numeroTelefonico === '') {
                return [];
            }

            $simCardCount = DB::table('detallesimcard')
                ->where('simCard_idsimCard', $simCardId)
                ->count();
            $numeroCount = DB::table('detallesimcard')
                ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
                ->count();

            if ($simCardCount > 1 || $numeroCount > 1) {
                return [];
            }

            if (self::hasSimCardPastHistory($simCardId) || self::hasNumeroPastHistory($numeroTelefonico)) {
                return [];
            }

            if (DB::table('detnumerosdispositivo')->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)->count() > 0) {
                return [];
            }

            return [[
                'mode' => 'delete_with_number_and_simcard',
                'label' => 'Eliminar Número y SimCard',
                'description' => 'Elimina la relación, el número y la SimCard relacionados.',
            ]];
        }

        return [];
    }

    private static function hasSimCardPastHistory(string $simCardId): bool
    {
        return DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '1')
            ->exists();
    }

    private static function hasNumeroPastHistory(string $numeroTelefonico): bool
    {
        return DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '1')
            ->exists();
    }

    private static function tableLabel(string $table): string
    {
        if (isset(self::TABLE_LABELS[$table]['label'])) {
            return self::TABLE_LABELS[$table]['label'];
        }

        return Str::title(trim(str_replace(['_', '-'], ' ', $table)));
    }

    private static function guessLabelFromResource(string $resource): string
    {
        return match ($resource) {
            'vehiculos' => 'Vehículo',
            'dispositivo_cliente' => 'Dispositivo cliente',
            'servicio_cliente' => 'Servicio cliente',
            'clientes.cliente' => 'Cliente',
            'clientes.credenciales' => 'Credencial',
            'clientes.grupo_cliente' => 'Grupo de cliente',
            default => Str::headline(str_replace(['.', '_', '-'], ' ', $resource)),
        };
    }
}