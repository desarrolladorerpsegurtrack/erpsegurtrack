<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class BulkDestroyController extends Controller
{
    private function getResourceMap(): array
    {
        return [
            'modules.personal.bulk-destroy' => [
                'table' => 'personal',
                'primaryKey' => 'dni',
                'lockResource' => 'personal',
                'redirectRoute' => 'modules.personal',
                'label' => 'personal',
            ],
            'modules.roles.bulk-destroy' => [
                'table' => 'rol',
                'primaryKey' => 'idrol',
                'lockResource' => 'roles',
                'redirectRoute' => 'modules.roles',
                'label' => 'rol',
            ],
            'modules.usuarios.bulk-destroy' => [
                'table' => 'usuario',
                'primaryKey' => 'usuario',
                'lockResource' => 'usuarios',
                'redirectRoute' => 'modules.usuarios',
                'label' => 'usuario',
            ],
            'modules.servicio-cliente.bulk-destroy' => [
                'table' => 'serviciocliente',
                'primaryKey' => 'idservicioCliente',
                'lockResource' => 'servicio_cliente',
                'redirectRoute' => 'modules.servicio-cliente',
                'label' => 'servicio cliente',
            ],
            'modules.vehiculos.bulk-destroy' => [
                'table' => 'vehiculo',
                'primaryKey' => 'placa',
                'lockResource' => 'vehiculo',
                'redirectRoute' => 'modules.vehiculos',
                'label' => 'vehículo',
            ],
            'modules.almacen.bulk-destroy' => [
                'table' => 'almacen',
                'primaryKey' => 'idalmacen',
                'lockResource' => 'almacen',
                'redirectRoute' => 'modules.almacen',
                'label' => 'almacen',
            ],
            'modules.almacen.nota-ingreso.bulk-destroy' => [
                'table' => 'elementoalmacen',
                'primaryKey' => 'imei',
                'lockResource' => 'almacen.nota_ingreso',
                'redirectRoute' => 'modules.almacen.nota-ingreso.index',
                'label' => 'nota de ingreso',
            ],
            'modules.almacen.nota-salida.bulk-destroy' => [
                'table' => 'elementoalmacen',
                'primaryKey' => 'imei',
                'lockResource' => 'almacen.nota_salida',
                'redirectRoute' => 'modules.almacen.nota-salida.index',
                'label' => 'nota de salida',
            ],
            'modules.clientes.bulk-destroy' => [
                'table' => 'cliente',
                'primaryKey' => 'cliente',
                'lockResource' => 'clientes',
                'redirectRoute' => 'modules.clientes',
                'label' => 'cliente',
            ],
            'modules.clientes.grupos.bulk-destroy' => [
                'table' => 'grupocliente',
                'primaryKey' => 'idgrupoCliente',
                'lockResource' => 'clientes.grupos',
                'redirectRoute' => 'modules.clientes.grupos.index',
                'label' => 'grupo',
            ],
            'modules.lineas-chips.numeros-telefonico.bulk-destroy' => [
                'table' => 'numerotelefonico',
                'primaryKey' => 'numeroTelefonico',
                'lockResource' => 'lineas_chips.numero_telefonico',
                'redirectRoute' => 'modules.lineas-chips.numeros-telefonico.index',
                'label' => 'número telefónico',
            ],
            'modules.lineas-chips.numeros-dispositivo.bulk-destroy' => [
                'table' => 'detnumerosdispositivo',
                'primaryKey' => 'iddetNumerosDispositivo',
                'lockResource' => 'lineas_chips.numero_dispositivo',
                'redirectRoute' => 'modules.lineas-chips.numeros-dispositivo.index',
                'label' => 'número de dispositivo',
            ],
            'modules.lineas-chips.simcard.bulk-destroy' => [
                'table' => 'simcard',
                'primaryKey' => 'idsimCard',
                'lockResource' => 'lineas_chips.simcard',
                'redirectRoute' => 'modules.lineas-chips.simcard.index',
                'label' => 'simcard',
            ],
            'modules.lineas-chips.detallesimcard.bulk-destroy' => [
                'table' => 'detallesimcard',
                'primaryKey' => 'iddetalleSimCard',
                'lockResource' => 'lineas_chips.detallesimcard',
                'redirectRoute' => 'modules.lineas-chips.detallesimcard.index',
                'label' => 'asignación de SIM card',
            ],
            'modules.configuracion.estados.bulk-destroy' => [
                'table' => 'estadocliente',
                'primaryKey' => 'idestadoCliente',
                'lockResource' => 'configuracion.estados',
                'redirectRoute' => 'modules.configuracion.estados.index',
                'label' => 'estado de cliente',
            ],
            'modules.configuracion.tipos-contacto.bulk-destroy' => [
                'table' => 'tipocontacto',
                'primaryKey' => 'idtipoContacto',
                'lockResource' => 'configuracion.tipo-contacto',
                'redirectRoute' => 'modules.configuracion.tipos-contacto.index',
                'label' => 'tipo de contacto',
            ],
            'modules.configuracion.monedas.bulk-destroy' => [
                'table' => 'moneda',
                'primaryKey' => 'idmoneda',
                'lockResource' => 'configuracion.moneda',
                'redirectRoute' => 'modules.configuracion.monedas.index',
                'label' => 'moneda',
            ],
            'modules.configuracion.tributos.bulk-destroy' => [
                'table' => 'tributo',
                'primaryKey' => 'idtributo',
                'lockResource' => 'configuracion.tributo',
                'redirectRoute' => 'modules.configuracion.tributos.index',
                'label' => 'tributo',
            ],
            'modules.configuracion.unidad-medida.bulk-destroy' => [
                'table' => 'unidadmedida',
                'primaryKey' => 'idunidadMedida',
                'lockResource' => 'configuracion.unidad_medida',
                'redirectRoute' => 'modules.configuracion.unidad-medida.index',
                'label' => 'unidad de medida',
            ],
            'modules.configuracion.tipos-plataforma.bulk-destroy' => [
                'table' => 'tipoplataforma',
                'primaryKey' => 'idtipoPlataforma',
                'lockResource' => 'configuracion.tipo_plataforma',
                'redirectRoute' => 'modules.configuracion.tipos-plataforma.index',
                'label' => 'tipo de plataforma',
            ],
            'modules.configuracion.plataforma.bulk-destroy' => [
                'table' => 'plataforma',
                'primaryKey' => 'idplataforma',
                'lockResource' => 'configuracion.plataforma',
                'redirectRoute' => 'modules.configuracion.plataforma.index',
                'label' => 'plataforma',
            ],
            'modules.configuracion.tipos-elemento.bulk-destroy' => [
                'table' => 'tipoelemento',
                'primaryKey' => 'idtipoElemento',
                'lockResource' => 'configuracion.tipo_elemento',
                'redirectRoute' => 'modules.configuracion.tipos-elemento.index',
                'label' => 'tipo de elemento',
            ],
            'modules.configuracion.tipos-documento.bulk-destroy' => [
                'table' => 'tipodocumento',
                'primaryKey' => 'idtipoDocumento',
                'lockResource' => 'configuracion.tipo_documento',
                'redirectRoute' => 'modules.configuracion.tipos-documento.index',
                'label' => 'tipo de documento',
            ],
            'modules.configuracion.formas-pago.bulk-destroy' => [
                'table' => 'formapago',
                'primaryKey' => 'idformaPago',
                'lockResource' => 'configuracion.forma_pago',
                'redirectRoute' => 'modules.configuracion.formas-pago.index',
                'label' => 'forma de pago',
            ],
            'modules.configuracion.entidades-bancarias.bulk-destroy' => [
                'table' => 'entidadbancaria',
                'primaryKey' => 'identidadBancaria',
                'lockResource' => 'configuracion.entidad_bancaria',
                'redirectRoute' => 'modules.configuracion.entidades-bancarias.index',
                'label' => 'entidad bancaria',
            ],
            'modules.configuracion.operadores.bulk-destroy' => [
                'table' => 'operador',
                'primaryKey' => 'idoperador',
                'lockResource' => 'configuracion.operador',
                'redirectRoute' => 'modules.configuracion.operadores.index',
                'label' => 'operador',
            ],
            'modules.configuracion.tipos-vehiculo.bulk-destroy' => [
                'table' => 'tipovehiculo',
                'primaryKey' => 'idtipoVehiculo',
                'lockResource' => 'configuracion.tipo_vehiculo',
                'redirectRoute' => 'modules.configuracion.tipos-vehiculo.index',
                'label' => 'tipo de vehículo',
            ],
            'modules.configuracion.tipos-operacion.bulk-destroy' => [
                'table' => 'tipooperacion',
                'primaryKey' => 'idtipoOperacion',
                'lockResource' => 'configuracion.tipo_operacion',
                'redirectRoute' => 'modules.configuracion.tipos-operacion.index',
                'label' => 'tipo de operación',
            ],
            'modules.configuracion.listas-precio.bulk-destroy' => [
                'table' => 'listaprecio',
                'primaryKey' => 'idListaPrecio',
                'lockResource' => 'configuracion.lista_precio',
                'redirectRoute' => 'modules.configuracion.listas-precio.index',
                'label' => 'lista de precio',
            ],
            'modules.configuracion.detalle-lista-precio.bulk-destroy' => [
                'table' => 'detallelistaprecio',
                'primaryKey' => 'iddetalleListaPrecio',
                'lockResource' => 'configuracion.detalle_lista_precio',
                'redirectRoute' => 'modules.configuracion.detalle-lista-precio.index',
                'label' => 'detalle de lista de precio',
            ],
            'modules.configuracion.elemento-almacen.bulk-destroy' => [
                'table' => 'elementoalmacen',
                'primaryKey' => 'imei',
                'lockResource' => 'configuracion.elemento_almacen',
                'redirectRoute' => 'modules.configuracion.elemento-almacen.index',
                'label' => 'elemento de almacén',
            ],
            'modules.configuracion.tipos-pedido.bulk-destroy' => [
                'table' => 'tipopedido',
                'primaryKey' => 'idtipoPedido',
                'lockResource' => 'configuracion.tipo_pedido',
                'redirectRoute' => 'modules.configuracion.tipos-pedido.index',
                'label' => 'tipo de pedido',
            ],
            'modules.configuracion.proveedores.bulk-destroy' => [
                'table' => 'proveedor',
                'primaryKey' => 'idproveedor',
                'lockResource' => 'configuracion.proveedor',
                'redirectRoute' => 'modules.configuracion.proveedores.index',
                'label' => 'proveedor',
            ],
            'modules.configuracion.vigencias-oferta.bulk-destroy' => [
                'table' => 'vigenciaoferta',
                'primaryKey' => 'idvigenciaoferta',
                'lockResource' => 'configuracion.vigencia_oferta',
                'redirectRoute' => 'modules.configuracion.vigencias-oferta.index',
                'label' => 'vigencia de oferta',
            ],
            'modules.configuracion.certificados-sunat.bulk-destroy' => [
                'table' => 'certificadosunat',
                'primaryKey' => 'idcertificadoSunat',
                'lockResource' => 'configuracion.certificadosunat',
                'redirectRoute' => 'modules.configuracion.certificados-sunat.index',
                'label' => 'certificado SUNAT',
            ],
            'modules.configuracion.empresapropietaria.bulk-destroy' => [
                'table' => 'empresapropietaria',
                'primaryKey' => 'RUC',
                'lockResource' => 'configuracion.empresapropietaria',
                'redirectRoute' => 'modules.configuracion.empresapropietaria.index',
                'label' => 'empresa propietaria',
            ],
            'modules.configuracion.marcas.bulk-destroy' => [
                'table' => 'marca',
                'primaryKey' => 'idmarca',
                'lockResource' => 'configuracion.marca',
                'redirectRoute' => 'modules.configuracion.marcas.index',
                'label' => 'marca',
            ],
            'modules.configuracion.modelo.bulk-destroy' => [
                'table' => 'modelo',
                'primaryKey' => 'idmodelo',
                'lockResource' => 'configuracion.modelo',
                'redirectRoute' => 'modules.configuracion.modelo.index',
                'label' => 'modelo',
            ],
            'modules.configuracion.tecnologias.bulk-destroy' => [
                'table' => 'tecnologia',
                'primaryKey' => 'idtecnologia',
                'lockResource' => 'configuracion.tecnologia',
                'redirectRoute' => 'modules.configuracion.tecnologias.index',
                'label' => 'tecnología',
            ],
            'modules.configuracion.tipos-gasto.bulk-destroy' => [
                'table' => 'tipogasto',
                'primaryKey' => 'idtipoGasto',
                'lockResource' => 'configuracion.tipo_gasto',
                'redirectRoute' => 'modules.configuracion.tipos-gasto.index',
                'label' => 'tipo de gasto',
            ],
            'modules.configuracion.tipos-cobro.bulk-destroy' => [
                'table' => 'tipocobro',
                'primaryKey' => 'idtipoCobro',
                'lockResource' => 'configuracion.tipo_cobro',
                'redirectRoute' => 'modules.configuracion.tipos-cobro.index',
                'label' => 'tipo de cobro',
            ],
            'modules.configuracion.ubigeos.bulk-destroy' => [
                'table' => 'ubigeo',
                'primaryKey' => 'idubigeo',
                'lockResource' => 'configuracion.ubigeo',
                'redirectRoute' => 'modules.configuracion.ubigeos.index',
                'label' => 'ubigeo',
            ],
            'modules.configuracion.cargos.bulk-destroy' => [
                'table' => 'cargo',
                'primaryKey' => 'idcargo',
                'lockResource' => 'configuracion.cargos',
                'redirectRoute' => 'modules.configuracion.cargos.index',
                'label' => 'cargo',
            ],
        ];
    }

    public function destroy(Request $request): RedirectResponse
    {
        $routeName = Route::currentRouteName();
        $mapping = $this->getResourceMap();

        if (empty($routeName) || !isset($mapping[$routeName])) {
            return redirect()->back()->with('error', 'No se encontró la ruta de eliminación masiva para este módulo.');
        }

        $resource = $mapping[$routeName];
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [$selectedIds];
        }

        $selectedIds = array_values(array_filter(array_map('trim', $selectedIds), fn ($value) => $value !== ''));
        if (empty($selectedIds)) {
            return redirect()->route($resource['redirectRoute'])->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $id) {
            if (!is_string($id) && !is_int($id)) {
                continue;
            }
            if (!empty($resource['lockResource'])) {
                if ($redirect = $this->assertLockAvailable($request, $resource['lockResource'], (string) $id, $resource['label'], $resource['redirectRoute'])) {
                    return $redirect;
                }
            }
        }

        try {
            DB::transaction(function () use ($resource, $selectedIds, $request) {
                DB::table($resource['table'])->whereIn($resource['primaryKey'], $selectedIds)->delete();

                foreach ($selectedIds as $id) {
                    $this->publishResourceEvent($resource['lockResource'], (string) $id, 'deleted');
                    if (!empty($resource['lockResource'])) {
                        $this->releaseLockIfOwned($request, $resource['lockResource'], (string) $id);
                    }
                }
            });

            $count = count($selectedIds);
            $successMessage = $count === 1
                ? '1 fila exitosamente eliminada'
                : "{$count} filas exitosamente eliminadas";
            return redirect()->route($resource['redirectRoute'])->with('success', $successMessage);
        } catch (QueryException $exception) {
            return redirect()->route($resource['redirectRoute'])->with('error', 'No se puede eliminar los registros seleccionados porque tienen relaciones asociadas.');
        }
    }
}
