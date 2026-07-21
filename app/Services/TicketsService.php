<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketsService
{
    // =========================================================================
    // AUTH CONTEXT
    // =========================================================================

    /**
     * Extrae del array de sesión 'erp_auth' los valores de usuario,
     * vistas permitidas e indicadores de rol/permiso.
     *
     * @param  array<string,mixed> $authData  Valor de session('erp_auth', [])
     * @return array{currentUser:string,allowedVistaIds:int[],isAdmin:bool,canSeeFlow:bool,canAttendTickets:bool}
     */
    public function resolveAuthContext(array $authData): array
    {
        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn($role) => mb_strtolower(trim((string) $role)))
            ->filter()
            ->values();

        $isAdmin = $userRoles->contains('admin');

        $currentPermissions = collect($authData['permissions']['tickets'] ?? [])
            ->map(fn($value) => \App\Support\ErpPermission::normalizeAction((string) $value))
            ->filter()
            ->unique()
            ->values();

        $allowedVistaIds = collect($authData['allowed_vistas'] ?? [])
            ->map(fn($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        return [
            'currentUser'      => (string) ($authData['usuario'] ?? 'anonimo'),
            'allowedVistaIds'  => $allowedVistaIds,
            'isAdmin'          => $isAdmin,
            'canSeeFlow'       => $isAdmin || $currentPermissions->contains('ver_flujo'),
            'canAttendTickets' => $isAdmin || $currentPermissions->contains('ver'),
        ];
    }

    public function resolveUserDisplayNameFromUsername(?string $username): string
    {
        $username = trim((string) ($username ?? ''));
        if ($username === '') {
            return '';
        }

        $userRow = DB::table('usuario')
            ->where('usuario', $username)
            ->first();

        if ($userRow && !empty($userRow->personal_dniPersonal)) {
            $personalRow = DB::table('personal')
                ->where('dniPersonal', $userRow->personal_dniPersonal)
                ->first();

            $displayName = trim((string) (($personalRow->nombre ?? '') . ' ' . ($personalRow->apellido ?? '')));
            if ($displayName !== '') {
                return substr($displayName, 0, 50);
            }
        }

        return substr($username, 0, 50);
    }

    public function resolveClienteData(object $cotizacion): array
    {
        $nombreCliente = trim((string) ($cotizacion->razonSocial ?? ''));
        if ($nombreCliente === '') {
            $nombreCliente = trim((string) ($cotizacion->nombreComercial ?? ''));
        }
        if ($nombreCliente === '') {
            $nombreCliente = trim((string) ($cotizacion->cliente_idcliente ?? ''));
        }
        if ($nombreCliente === '') {
            $nombreCliente = 'Cliente sin nombre';
        }

        $docCliente = trim((string) ($cotizacion->cliente_idcliente ?? ''));
        if ($docCliente === '') {
            $docCliente = trim((string) ($cotizacion->idcliente ?? ''));
        }

        return [
            'nombre_cliente' => $nombreCliente,
            'doc_cliente' => $docCliente,
        ];
    }

    private function mergeTempSessionData(array $dbTempData, array $sessionTempData): array
    {
        $merged = $dbTempData;

        foreach ($sessionTempData as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergeTempSessionArrays($merged[$key], $value);
                continue;
            }

            if (is_array($value) && empty($value) && isset($merged[$key])) {
                continue;
            }

            if ($value === '' && isset($merged[$key])) {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function mergeTempSessionArrays(array $dbArray, array $sessionArray): array
    {
        $merged = $dbArray;

        foreach ($sessionArray as $index => $value) {
            if (is_array($value) && isset($merged[$index]) && is_array($merged[$index])) {
                $merged[$index] = $this->mergeTempSessionArrays($merged[$index], $value);
                continue;
            }

            if ($value === '' && isset($merged[$index])) {
                continue;
            }

            if (is_array($value) && empty($value) && isset($merged[$index])) {
                continue;
            }

            $merged[$index] = $value;
        }

        return $merged;
    }

    // =========================================================================
    // SHOW — EXTRA DATA (cotizaciones, equipamiento, IMEIs, vehículos, servicios)
    // =========================================================================

    /**
     * Construye el array $extraData que se pasa a la vista del ticket.
     * Solo aplica cuando el ticket tiene pedidoReferencia y la vista es 1–6.
     *
     * @param  object $ticket    Fila del ticket desde la BD
     * @param  int    $vistaId   ID de la vista actual
     * @param  int    $ticketId  ID del ticket
     * @return array<string,mixed>
     */
    public function buildShowExtraData(object $ticket, int $vistaId, int $ticketId): array
    {
        $extraData = [];

        if (!in_array($vistaId, [1, 2, 3, 4, 5, 6]) || empty($ticket->pedidoReferencia)) {
            return $extraData;
        }

        $ref = $ticket->pedidoReferencia;

        // --- Cotizaciones con joins de forma de pago, moneda y vigencia ---
        $cotizacionesQuery = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'c.cliente_idcliente', '=', 'cli.idcliente')
            ->leftJoin('detallegrupocliente as dgc', 'c.cliente_idcliente', '=', 'dgc.cliente_idcliente')
            ->leftJoin('grupocliente as gc', 'dgc.grupoCliente_idgrupoCliente', '=', 'gc.idgrupoCliente')
            ->leftJoin('formapago as fp', 'c.formaPago_idformaPago', '=', 'fp.idformaPago')
            ->leftJoin('moneda as m', 'c.moneda_idmoneda', '=', 'm.idmoneda')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->select(
                'c.*',
                'cli.razonSocial',
                'cli.nombreComercial',
                DB::raw("COALESCE(NULLIF(TRIM(cli.razonSocial), ''), NULLIF(TRIM(c.cliente_idcliente), ''), 'Cliente sin nombre') as cliente_label"),
                DB::raw("(select dc.direccion from direccioncliente as dc where dc.cliente_idcliente = c.cliente_idcliente order by dc.default desc, dc.iddireccionCliente desc limit 1) as cliente_direccion"),
                DB::raw("(select ct.numero from contacto as ct where ct.cliente_idcliente = c.cliente_idcliente order by ct.default desc, ct.idcontacto desc limit 1) as cliente_telefono"),
                DB::raw("(select ct.correo from contacto as ct where ct.cliente_idcliente = c.cliente_idcliente order by ct.default desc, ct.idcontacto desc limit 1) as cliente_correo"),
                DB::raw("(select gc2.nombreGrupo from detallegrupocliente as dgc2 join grupocliente as gc2 on gc2.idgrupoCliente = dgc2.grupoCliente_idgrupoCliente where dgc2.cliente_idcliente = c.cliente_idcliente order by gc2.nombreGrupo limit 1) as grupo_cliente_label"),
                'fp.detalle as formapago_label',
                'm.detalle as moneda_label',
                'v.detalle as vigencia_label'
            );

        $cotizaciones = strlen($ref) > 20
            ? $cotizacionesQuery->where('c.batch_id', $ref)->get()
            : $cotizacionesQuery->where('c.nroCotizacion', $ref)->get();

        $nros       = $cotizaciones->pluck('nroCotizacion')->toArray();
        $clienteIds = $cotizaciones->pluck('cliente_idcliente')->filter()->unique()->toArray();

        // --- Equipamiento (items tipo Equipo / Equipos) ---
        $equipamiento = DB::table('detallecotizacion as d')
            ->join('almacen as a', 'd.almacen_idalmacen', '=', 'a.idalmacen')
            ->join('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->whereIn('d.cotizacion_nroCotizacion', $nros)
            ->whereRaw("LOWER(TRIM(te.nombre)) LIKE ?", ['equipo%'])
            ->select('d.*', 'a.detalle as producto', 'a.cantidadDisponible', 'te.nombre as tipo_nombre')
            ->get();

        // --- IMEIs disponibles por dispositivo ---
        $deviceIds = collect($equipamiento)
            ->pluck('almacen_idalmacen')
            ->filter(fn($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $imeisByDevice = collect([]);
        if (!empty($deviceIds)) {
            $imeisByDevice = DB::table('elementoalmacen')
                ->whereIn('dispositivo_iddispositivo', $deviceIds)
                ->where('estado', 1)
                ->orderBy('imei')
                ->get(['dispositivo_iddispositivo', 'imei'])
                ->groupBy('dispositivo_iddispositivo')
                ->map(fn($rows) => $rows->pluck('imei')->values()->all());
        }

        // --- Datos temporales: usar la BD como fuente de verdad (no sesión) ---
        $decodedTempData = [];
        if (!empty($ticket->temp_data)) {
            $decodedTempData = json_decode($ticket->temp_data, true);
            if (!is_array($decodedTempData)) {
                $decodedTempData = [];
            }
        }

        $tempData = is_array($decodedTempData) ? $decodedTempData : [];

        // Decorar equipamiento con IMEIs disponibles y selecciones temporales
        $equipamiento = collect($equipamiento)
            ->map(function ($item) use ($imeisByDevice, $tempData) {
                $item->availableImeis      = $imeisByDevice->get((int) $item->almacen_idalmacen, []);
                $item->imeis_seleccionados = $tempData['imeis'][$item->iddetalleCotizacion] ?? [];
                $item->placas_seleccionadas = $tempData['placas'][$item->iddetalleCotizacion] ?? [];
                $item->planes_seleccionados = $tempData['planes'][$item->iddetalleCotizacion] ?? [];
                $item->numeros_seleccionados = $tempData['numeros'][$item->iddetalleCotizacion] ?? [];
                return $item;
            })
            ->all();

        // --- Traer los comentarios de las cotizaciones ---
        // Seleccionamos sólo el número de cotización y su comentario,
        // luego eliminamos vacíos y duplicados por cotización, y limitamos a 3.
        $cotizacionesComentarios = DB::table('detallecotizacion as d')
            ->join('cotizacion as c', 'd.cotizacion_nroCotizacion', '=', 'c.nroCotizacion')
            ->whereIn('d.cotizacion_nroCotizacion', $nros)
            ->whereNotNull('c.comentario')
            ->where('c.comentario', '!=', '')
            ->select('c.nroCotizacion', 'c.comentario')
            ->distinct()
            ->get()
            ->filter(function ($r) {
                return trim((string) ($r->comentario ?? '')) !== '';
            })
            ->unique('nroCotizacion')
            ->values()
            ->take(3);

        $extraData['cotizaciones']      = $cotizaciones;
        $extraData['equipamiento']      = $equipamiento;
        $extraData['cotizacionesComentarios'] = $cotizacionesComentarios;
        $extraData['comentarioTemporal'] = $tempData['comentario'] ?? null;
        $extraData['tempData']          = $tempData;

        // --- Datos específicos para vistas 3, 4, 5, 6 ---
        if (in_array($vistaId, [3, 4, 5, 6])) {
            $vehiculos = collect([]);
            if (!empty($clienteIds)) {
                $vehiculos = DB::table('vehiculo')
                    ->whereIn('cliente_idcliente', $clienteIds)
                    ->get();
            }

            if (!empty($tempData['vehiculos']) && is_array($tempData['vehiculos'])) {
                $existingPlacas = collect($vehiculos)
                    ->pluck('placa')
                    ->map(fn($placa) => trim((string) $placa))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                foreach ($tempData['vehiculos'] as $vehiculoTemp) {
                    $placaTemp = trim((string) ($vehiculoTemp['placa'] ?? ''));
                    if ($placaTemp === '' || in_array($placaTemp, $existingPlacas, true)) {
                        continue;
                    }

                    $vehiculos->push((object) [
                        'placa' => $placaTemp,
                        'cliente_idcliente' => $vehiculoTemp['cliente_idcliente'] ?? null,
                        'tipoUnidad_idtable1' => $vehiculoTemp['tipoUnidad_idtable1'] ?? null,
                        'anio' => $vehiculoTemp['anio'] ?? null,
                        'marca' => $vehiculoTemp['marca'] ?? null,
                        'modelo' => $vehiculoTemp['modelo'] ?? null,
                        'color' => $vehiculoTemp['color'] ?? null,
                        'tracto' => $vehiculoTemp['tracto'] ?? null,
                    ]);
                    $existingPlacas[] = $placaTemp;
                }
            }

            $clientes = collect([]);
            if (!empty($clienteIds)) {
                $groupIds = DB::table('detallegrupocliente')
                    ->whereIn('cliente_idcliente', $clienteIds)
                    ->pluck('grupoCliente_idgrupoCliente')
                    ->filter(fn($id) => !empty($id))
                    ->unique()
                    ->values()
                    ->all();

                if (!empty($groupIds)) {
                    $clientes = DB::table('cliente as c')
                        ->join('detallegrupocliente as dgc', 'c.idcliente', '=', 'dgc.cliente_idcliente')
                        ->whereIn('dgc.grupoCliente_idgrupoCliente', $groupIds)
                        ->select([
                            'c.idcliente',
                            DB::raw("COALESCE(NULLIF(TRIM(c.nombreComercial), ''), NULLIF(TRIM(c.razonSocial), ''), c.idcliente) as cliente_label"),
                        ])
                        ->distinct()
                        ->orderBy('cliente_label')
                        ->get();
                }

                if ($clientes->isEmpty()) {
                    $clientes = DB::table('cliente')
                        ->whereIn('idcliente', $clienteIds)
                        ->select([
                            'idcliente',
                            DB::raw("COALESCE(NULLIF(TRIM(nombreComercial), ''), NULLIF(TRIM(razonSocial), ''), idcliente) as cliente_label"),
                        ])
                        ->orderBy('cliente_label')
                        ->get();
                }
            }

            $tipoVehiculos = DB::table('tipovehiculo')
                ->orderBy('nombre')
                ->get();

            $servicios = DB::table('detallecotizacion as d')
                ->join('almacen as a', 'd.almacen_idalmacen', '=', 'a.idalmacen')
                ->join('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
                ->whereIn('d.cotizacion_nroCotizacion', $nros)
                ->where('te.nombre', 'not like', '%EQUIP%')
                ->select('d.*', 'a.detalle as producto', 'te.nombre as tipo_nombre')
                ->get();

            $numerosTelefonicos = DB::table('numerotelefonico as nt')
                ->join('detallesimcard as ds', 'nt.numeroTelefonico', '=', 'ds.numeroTelefonico_numeroTelefonico')
                ->where('ds.estado', 0)
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                          ->from('detnumerosdispositivo as dnd')
                          ->join('dispositivocliente as dc', 'dnd.dispositivoCliente_iddispositivoCliente', '=', 'dc.iddispositivoCliente')
                          ->whereColumn('dnd.numeroTelefonico_numeroTelefonico', 'nt.numeroTelefonico')
                          ->where('dc.estado', 1);
                })
                ->select('nt.numeroTelefonico')
                ->distinct()
                ->get();

            $extraData['vehiculos']      = $vehiculos;
            $extraData['clientes']       = $clientes;
            $extraData['tipoVehiculos']  = $tipoVehiculos;
            $extraData['clienteId']      = !empty($clienteIds) ? $clienteIds[0] : null;
            $extraData['servicios']      = $servicios;
            $extraData['numerosTelefonicos'] = $numerosTelefonicos;
        }

        return $extraData;
    }

    // =========================================================================
    // ADVANCE — PERSISTENCIA DEL FINISH (pedido, detalle, órdenes, servicios)
    // =========================================================================

    /**
     * Persiste toda la información temporal en BD cuando la acción es 'finish'.
     * Crea el Pedido, DetallePedido, OrdenPedidoAlmacen y ServicioCliente,
     * y actualiza el estado de las cotizaciones referenciadas.
     *
     * @param  object   $ticket        Fila del ticket desde la BD
     * @param  int      $ticketId      ID del ticket
     * @param  array<string,mixed> $tempData  Datos temporales de sesión
     * @param  string   $currentUser   Usuario actual
     * @param  callable $publishEvent  Closure para emitir eventos realtime:
     *                                 fn(string $resource, string $id, string $event, array $payload)
     */
    public function persistFinish(
        object $ticket,
        int $ticketId,
        array $tempData,
        string $currentUser,
        callable $publishEvent
    ): void {
        if (empty($ticket->pedidoReferencia)) {
            return;
        }

        $tipoOp = DB::table('tipooperacion')
            ->where('idtipoOperacion', $ticket->tipoOperacion_idtipoOperacion)
            ->first();

        $esCotizacion = $tipoOp && (
            trim($tipoOp->nomenclatura ?? '') === 'CT' ||
            mb_strpos(mb_strtolower($tipoOp->detalle ?? ''), 'cotizaci') !== false
        );

        if (!$esCotizacion) {
            return;
        }

        $ref = $ticket->pedidoReferencia;

        // --- 1. Obtener cotizaciones y equipamiento ---
        $cotizacionesQuery = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'c.cliente_idcliente', '=', 'cli.idcliente')
            ->select('c.*', 'cli.razonSocial', 'cli.nombreComercial', 'cli.idcliente');

        $cotizaciones = strlen($ref) > 20
            ? $cotizacionesQuery->where('c.batch_id', $ref)->get()
            : $cotizacionesQuery->where('c.nroCotizacion', $ref)->get();

        $cotizaciones = $cotizaciones->map(function ($cotizacion) {
            $clienteData = $this->resolveClienteData($cotizacion);
            $cotizacion->nombre_cliente = $clienteData['nombre_cliente'];
            $cotizacion->doc_cliente = $clienteData['doc_cliente'];

            return $cotizacion;
        });

        $nros       = $cotizaciones->pluck('nroCotizacion')->toArray();
        $primeraCot = $cotizaciones->first();

        $equipamiento = DB::table('detallecotizacion as d')
            ->join('almacen as a', 'd.almacen_idalmacen', '=', 'a.idalmacen')
            ->join('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->whereIn('d.cotizacion_nroCotizacion', $nros)
            ->where(function ($query) {
                $query->where('te.nombre', 'like', '%EQUIP%')
                    ->orWhere('te.nombre', 'like', '%SENSOR%');
            })
            ->select('d.*', 'a.detalle as producto', 'a.cantidadDisponible', 'te.nombre as tipo_nombre')
            ->get();

        $servicios = DB::table('detallecotizacion as d')
            ->join('almacen as a', 'd.almacen_idalmacen', '=', 'a.idalmacen')
            ->join('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->whereIn('d.cotizacion_nroCotizacion', $nros)
            ->where('te.nombre', 'not like', '%EQUIP%')
            ->select('d.*', 'a.detalle as producto', 'te.nombre as tipo_nombre', 'd.precioUnitario')
            ->get();

        // --- 2. Obtener tipoPedido CT ---
        $tipoPedido   = DB::table('tipopedido')
            ->where('nomenclatura', 'CT')
            ->orWhere('detalle', 'like', '%Cotizaci%')
            ->first();
        $tipoPedidoId = $tipoPedido ? (int) $tipoPedido->idtipoPedido : 1;

        // --- 3. Obtener id del personal emisor y el nombre visible del usuario ---
        $emisorUsuario = $tempData['personalEmisor'] ?? $currentUser;
        $emisorUsuarioRow = DB::table('usuario')
            ->where('usuario', $emisorUsuario)
            ->first();
        $emisorPersonal = null;
        if ($emisorUsuarioRow && !empty($emisorUsuarioRow->personal_dniPersonal)) {
            $emisorPersonal = DB::table('personal')
                ->where('dniPersonal', $emisorUsuarioRow->personal_dniPersonal)
                ->first();
        }
        $emisorId = $emisorPersonal ? (int) ($emisorPersonal->idpersonal ?? null) : null;
        $emisorLabel = trim((string) (($emisorPersonal->nombre ?? '') . ' ' . ($emisorPersonal->apellido ?? '')));
        if ($emisorLabel === '') {
            $emisorLabel = $emisorUsuario;
        }

        // --- 4. Crear el Pedido ---
        $idPedido = strtoupper(substr(uniqid('PED-'), 0, 15));
        $documentoReferencia = is_array($nros) ? implode(',', array_slice($nros, 0, 15)) : ($nros[0] ?? null);
        if ($documentoReferencia !== null && mb_strlen($documentoReferencia) > 15) {
            $documentoReferencia = null;
        }

        DB::table('pedido')->insert([
            'idpedido'               => $idPedido,
            'tipoPedido_idtipoPedido' => $tipoPedidoId,
            'fechaHora'              => now()->format('Y-m-d H:i:s'),
            'emisor'                 => $emisorUsuarioRow ? preg_replace('/\D/', '', (string) ($emisorUsuarioRow->personal_dniPersonal ?? '')) : null,
            'documentoReferencia'    => $documentoReferencia,
            'cliente'                => $primeraCot ? ($primeraCot->nombre_cliente ?? $primeraCot->cliente_idcliente) : null,
            'identificadorCliente'   => $primeraCot ? $primeraCot->cliente_idcliente : null,
            'avanceEntrega'          => 0,
            'estado'                 => '0',
            'comentario'             => mb_substr($tempData['comentario'] ?? '', 0, 100),
        ]);

        // --- 5. Crear DetallePedido y OrdenPedidoAlmacen por cada equipo ---
        $ticketReceptorUsuario = $currentUser;
        $ticketReceptorLabel = $this->resolveUserDisplayNameFromUsername($ticketReceptorUsuario);

        $personalReceptorUsuario = $tempData['personalReceptor'] ?? null;
        $personalReceptorLabel = $personalReceptorUsuario ? $this->resolveUserDisplayNameFromUsername($personalReceptorUsuario) : null;
        $fechaHoraRecepcion = $tempData['fechaHoraRecepcion'] ?? null;

        foreach ($equipamiento as $item) {
            $imeisItem  = $tempData['imeis'][$item->iddetalleCotizacion] ?? [];
            $placasItem = $tempData['placas'][$item->iddetalleCotizacion] ?? [];
            $numerosItem = $tempData['numeros'][$item->iddetalleCotizacion] ?? [];

            // Si es un SENSOR y no tiene IMEIs asignados manualmente, autocompletar con los disponibles en almacén
            if (empty($imeisItem) && stripos($item->tipo_nombre ?? '', 'SENSOR') !== false) {
                $sensorImeis = DB::table('elementoalmacen')
                    ->where('dispositivo_iddispositivo', $item->almacen_idalmacen)
                    ->where('estado', 1)
                    ->limit((int) $item->cantidad)
                    ->pluck('imei')
                    ->toArray();
                
                // Si no hay suficientes en almacén, rellenar con un identificador genérico para que de todas formas guarde la orden
                $imeisItem = count($sensorImeis) > 0 ? $sensorImeis : array_fill(0, (int) $item->cantidad, 'SENSOR-SF-' . substr(uniqid(), -6));
            }

            $validImeisCount = 0;
            foreach ($imeisItem as $imei) {
                if (!empty($imei)) {
                    $validImeisCount++;
                }
            }

            $detallePedidoId = DB::table('detallepedido')->insertGetId([
                'pedido_idpedido'   => $idPedido,
                'almacen_idalmacen' => (int) $item->almacen_idalmacen,
                'cantidad'          => (float) $item->cantidad,
                'avanceIndividual'  => $validImeisCount,
                'estadoIndividual'  => '0',
            ]);

            foreach ($imeisItem as $idx => $imei) {
                if (empty($imei)) {
                    continue;
                }

                DB::table('ordenpedidoalmacen')->insert([
                    'personalEmisor'                => $emisorLabel,
                    'personalReceptor'              => $personalReceptorLabel,
                    'detallePedido_iddetallePedido' => (int) $detallePedidoId,
                    'elementoAlmacen_imei'          => $imei,
                    'fechaHoraEntrega'              => now()->format('Y-m-d H:i:s'),
                    'fechaHoraRecepcion'            => $fechaHoraRecepcion,
                    'estado'                        => '0',
                ]);

                // Marcar IMEI como consumido/inactivo
                DB::table('elementoalmacen')
                    ->where('imei', $imei)
                    ->update(['estado' => 0]);

                // Descontar la cantidad de stock en almacén, tanto para equipo como para sensor.
                DB::table('almacen')
                    ->where('idalmacen', $item->almacen_idalmacen)
                    ->decrement('cantidadDisponible', 1);

                $placa  = $placasItem[$idx] ?? null;
                $numeroTelef = $numerosItem[$idx] ?? null;
                
                // --- 6. Asegurar que el vehículo existe antes de crear el Dispositivo Cliente ---
                if ($placa) {
                    $vehiculoExists = DB::table('vehiculo')
                        ->where('placa', $placa)
                        ->exists();

                    if (!$vehiculoExists) {
                        $vehiculoTemp = collect($tempData['vehiculos'] ?? [])->firstWhere('placa', $placa);
                        if ($vehiculoTemp) {
                            DB::table('vehiculo')->insert([
                                'placa' => $placa,
                                'cliente_idcliente' => $vehiculoTemp['cliente_idcliente'] ?? null,
                                'tipoUnidad_idtable1' => $vehiculoTemp['tipoUnidad_idtable1'] ?? null,
                                'anio' => $vehiculoTemp['anio'] ?? null,
                                'color' => $vehiculoTemp['color'] ?? null,
                                'marca' => $vehiculoTemp['marca'] ?? null,
                                'modelo' => $vehiculoTemp['modelo'] ?? null,
                                'tracto' => $vehiculoTemp['tracto'] ?? null,
                            ]);
                        }
                    }

                    $vehiculoTemp = collect($tempData['vehiculos'] ?? [])->firstWhere('placa', $placa);
                    $modeloDispositivo = null;
                    $marcaDispositivo = null;

                    if (!empty($item->almacen_idalmacen)) {
                        $almacenItem = DB::table('almacen')->where('idalmacen', $item->almacen_idalmacen)->first();
                        if ($almacenItem && !empty($almacenItem->modelo_idmodelo)) {
                            $modeloRow = DB::table('modelo as mo')
                                ->leftJoin('marca as ma', 'mo.marca_idmarca', '=', 'ma.idmarca')
                                ->where('mo.idmodelo', $almacenItem->modelo_idmodelo)
                                ->select('mo.nombreModelo', 'ma.nombreMarca as marcaNombre')
                                ->first();

                            if ($modeloRow) {
                                $modeloDispositivo = trim((string) ($modeloRow->nombreModelo ?? '')) ?: null;
                                $marcaDispositivo = trim((string) ($modeloRow->marcaNombre ?? '')) ?: null;

                                if ($marcaDispositivo !== null) {
                                    $marcaDispositivo = mb_substr($marcaDispositivo, 0, 50);
                                }
                                if ($modeloDispositivo !== null) {
                                    $modeloDispositivo = mb_substr($modeloDispositivo, 0, 100);
                                }
                            }
                        }
                    }

                    DB::table('dispositivocliente')->updateOrInsert(
                        ['iddispositivoCliente' => $imei],
                        [
                            'vehiculo_placa' => $placa,
                            'marcaDispositivo' => $marcaDispositivo,
                            'modeloDispositivo' => $modeloDispositivo,
                            'fechaInstalacion' => now()->format('Y-m-d H:i:s'),
                            'estado' => '1'
                        ]
                    );
                    
                    if ($numeroTelef) {
                        DB::table('detnumerosdispositivo')->insert([
                            'dispositivoCliente_iddispositivoCliente' => $imei,
                            'numeroTelefonico_numeroTelefonico' => $numeroTelef,
                            'fechaAsignacion' => now()->format('Y-m-d H:i:s')
                        ]);
                    }
                }

                // --- 7. Crear ServicioCliente con placa y plan ---
                $planesArray = $tempData['planes'][$item->iddetalleCotizacion][$idx] ?? [];

                if ($placa && $primeraCot) {
                    if (empty($planesArray)) {
                        DB::table('serviciocliente')->insert([
                            'cliente_idcliente' => $primeraCot->cliente_idcliente,
                            'vehiculo_placa'    => $placa,
                            'almacen_idalmacen' => (int) $item->almacen_idalmacen,
                            'fechaInicio'       => now()->format('Y-m-d H:i:s'),
                            'fecheVencimiento'  => null,
                            'monto'             => null,
                            'estado'            => 'activo',
                            'docReferencia'     => mb_substr($idPedido, 0, 15),
                        ]);
                    } else {
                        // For string backwards compatibility just in case, wrap in array if not array
                        if (!is_array($planesArray)) $planesArray = [$planesArray];
                        
                        foreach ($planesArray as $pIdVal) {
                            $pId = explode('_', (string)$pIdVal)[0];
                            $planMonto = null;
                            $planAlmacenId = (int) $item->almacen_idalmacen;
                            if ($pId) {
                                $planItem  = $servicios->firstWhere('iddetalleCotizacion', $pId);
                                $planMonto = $planItem ? (float) ($planItem->precioUnitario ?? 0) : null;
                                // Usar el almacén del plan específico en lugar del equipo
                                if ($planItem) {
                                    $planAlmacenId = (int) ($planItem->almacen_idalmacen ?? $item->almacen_idalmacen);
                                }
                            }

                            DB::table('serviciocliente')->insert([
                                'cliente_idcliente' => $primeraCot->cliente_idcliente,
                                'vehiculo_placa'    => $placa,
                                'almacen_idalmacen' => $planAlmacenId,
                                'fechaInicio'       => now()->format('Y-m-d H:i:s'),
                                'fecheVencimiento'  => null,
                                'monto'             => $planMonto,
                                'estado'            => 'activo',
                                'docReferencia'     => mb_substr($idPedido, 0, 15),
                            ]);
                        }
                    }
                }
            }
        }

        // --- 7. Actualizar estado de ticket y receptor ---
        if (!empty($ticket->idticket)) {
            DB::table('ticket')
                ->where('idticket', $ticket->idticket)
                ->update(['usuarioReceptor' => $ticketReceptorLabel]);
        }

        // --- 8. Actualizar estado de cotizaciones ---
        if (strlen($ref) > 20) {
            $cotizacionesToUpdate = DB::table('cotizacion')->where('batch_id', $ref)->get(['nroCotizacion', 'estado']);
            
            foreach ($cotizacionesToUpdate as $cotizacion) {
                $estadoAnterior = $cotizacion->estado;
                $nuevoEstado = ($estadoAnterior === \App\Services\CotizacionService::STATE_APROBADO_SP) 
                    ? \App\Services\CotizacionService::STATE_EJECUTADO_SP 
                    : \App\Services\CotizacionService::STATE_FINALIZADO;

                DB::table('cotizacion')->where('nroCotizacion', $cotizacion->nroCotizacion)->update(['estado' => $nuevoEstado]);
                ($publishEvent)('ventas.cotizaciones', (string) $cotizacion->nroCotizacion, 'updated', ['estado' => $nuevoEstado]);
            }
        } else {
            $cotizacion = DB::table('cotizacion')->where('nroCotizacion', $ref)->first(['estado']);
            if ($cotizacion) {
                $estadoAnterior = $cotizacion->estado;
                $nuevoEstado = ($estadoAnterior === \App\Services\CotizacionService::STATE_APROBADO_SP) 
                    ? \App\Services\CotizacionService::STATE_EJECUTADO_SP 
                    : \App\Services\CotizacionService::STATE_FINALIZADO;

                DB::table('cotizacion')->where('nroCotizacion', $ref)->update(['estado' => $nuevoEstado]);
                ($publishEvent)('ventas.cotizaciones', (string) $ref, 'updated', ['estado' => $nuevoEstado]);
            }
        }
    }

    // =========================================================================
    // ADVANCE — GUARDAR DATOS TEMPORALES EN SESIÓN POR VISTA
    // =========================================================================

    /**
     * Lee los inputs del request y persiste en sesión los datos relevantes
     * según la vista que se está avanzando (1, 2 o 3).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int                      $vistaId
     * @param  string                   $sessionKey  Clave de sesión (ticket_temp_{id})
    * @param  int                      $ticketId
    * @param  string                   $currentUser
    * @return array<string,mixed>  El $tempData actualizado
    */
    public function persistTempSession(
        \Illuminate\Http\Request $request,
        int $vistaId,
        string $sessionKey,
        int $ticketId,
        string $currentUser
    ): array {
        // Leer temp_data directamente desde la BD y no depender de la sesión
        $tempData = [];
        $ticketTemp = DB::table('ticket')->where('idticket', $ticketId)->value('temp_data');
        if ($ticketTemp) {
            $decodedTempData = json_decode($ticketTemp, true);
            if (is_array($decodedTempData)) {
                $tempData = $decodedTempData;
            }
        }

        if ($request->has('imeis')) {
            $tempData['imeis'] = $request->input('imeis');
        }

        if ($request->has('imeis_completados')) {
            $completados = $request->input('imeis_completados', []);
            // Merge into existing imeis: each key override individually so other keys are preserved
            $existingImeis = $tempData['imeis'] ?? [];
            foreach ($completados as $detId => $imeiList) {
                $existingImeis[$detId] = $imeiList;
            }
            $tempData['imeis'] = $existingImeis;
        }

        if ($request->has('comentario')) {
            $tempData['comentario'] = $request->input('comentario');
        }

        if ($request->has('placas')) {
            $tempData['placas'] = $request->input('placas');
        }

        if ($request->has('numeros')) {
            $tempData['numeros'] = $request->input('numeros');
        }

        if ($request->has('planes')) {
            $tempData['planes'] = $request->input('planes');
        }

        if ($request->has('personalReceptor')) {
            $tempData['personalReceptor'] = $request->input('personalReceptor');
        }

        if ($request->has('personalEmisor')) {
            $tempData['personalEmisor'] = $request->input('personalEmisor');
        }

        if ($vistaId === 1) {
            $tempData['personalEmisor'] = $currentUser;
        }

        if ($vistaId === 2) {
            $tempData['personalReceptor'] = $currentUser;
            if (!isset($tempData['fechaHoraRecepcion'])) {
                $tempData['fechaHoraRecepcion'] = now()->format('Y-m-d H:i:s');
            }
        }

        // Persistir exclusivamente en la BD (fuente de verdad)
        DB::table('ticket')
            ->where('idticket', $ticketId)
            ->update(['temp_data' => json_encode($tempData, JSON_UNESCAPED_UNICODE)]);

        return $tempData;
    }

    /**
     * @param  object     $row    Fila decorada del listado
     * @return array{receptor:string,fase_texto:?string,accion_texto:?string}
     */
    public function resolveTicketListDisplayInfo(object $row): array
    {
        $lockUser         = trim((string) ($row->lock_usuario ?? ''));
        $historialUser    = trim((string) ($row->historial_usuario ?? ''));
        $historialResultado = trim((string) ($row->historial_resultado ?? ''));
        $originalReceptor = trim((string) ($row->usuarioReceptor ?? ''));
        $ticketId         = (int) ($row->idticket ?? 0);
        $tipoOperacionId  = (int) ($row->tipo_operacion_id ?? 0);

        $lockUserDisplayName = $lockUser !== '' ? $this->resolveUserDisplayNameFromUsername($lockUser) : '';
        $historialUserDisplayName = $historialUser !== '' ? $this->resolveUserDisplayNameFromUsername($historialUser) : '';

        $receptor = $lockUser !== ''
            ? $lockUserDisplayName
            : ($historialUser !== '' && $historialResultado !== 'creado' ? $historialUserDisplayName : ($originalReceptor !== '' ? $originalReceptor : '-'));

        $accionTexto = null;
        if ($lockUser !== '') {
            $accionTexto = 'Atendiendo: ' . $lockUserDisplayName;
        } elseif ($historialUser !== '' && $row->historial_resultado !== 'creado') {
            $accionTexto = 'Último en atender: ' . $historialUserDisplayName;
        }

        $faseTexto = null;
        $historialRow = null;

        if ($ticketId > 0) {
            $historialRow = $this->getLatestHistorialForTicket($ticketId);
            if (!$historialRow && $tipoOperacionId > 0) {
                $historialRow = $this->ensureInitialHistorialForTicket(
                    $ticketId,
                    $tipoOperacionId,
                    (string) ($row->historial_usuario ?? $row->usuarioEmisor ?? $row->lock_usuario ?? '')
                );
            }
        }

        if ($tipoOperacionId > 0) {
            $flujo = DB::table('flujo')
                ->where('tipoOperacion_idtipoOperacion', $tipoOperacionId)
                ->orderBy('idflujo')
                ->first();

            if ($flujo) {
                $reglas = DB::table('flujoregla')
                    ->where('flujo_idflujo', (int) $flujo->idflujo)
                    ->where('estado', '1')
                    ->orderBy('orden')
                    ->orderBy('idflujoregla')
                    ->get();

                if ($reglas->isNotEmpty()) {
                    $historialObj = (object) [
                        'flujoregla_idflujoregla' => $historialRow?->flujoregla_idflujoregla ?? $row->historial_flujoregla_id ?? null,
                        'resultado'               => $historialRow?->resultado ?? $row->historial_resultado ?? null,
                        'usuario_usuario'         => $historialRow?->usuario_usuario ?? $row->historial_usuario ?? null,
                        'vista_idvista'           => $historialRow?->vista_idvista ?? $row->vista_actual_id ?? null,
                    ];

                    $currentRule = $this->resolveCurrentRuleFromCollection($historialObj, $reglas);
                    if ($currentRule) {
                        $phaseIndex = $reglas->search(
                            fn($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla
                        );
                        if ($phaseIndex !== false) {
                            $faseTexto = 'Fase: ' . ($phaseIndex + 1) . ' de ' . $reglas->count();
                        }
                    }
                }
            }
        }

        return [
            'receptor'     => $receptor,
            'fase_texto'   => $faseTexto,
            'accion_texto' => $accionTexto,
        ];
    }

    public function ensureInitialHistorialForTicket(int $ticketId, int $tipoOperacionId, ?string $usuario = null): ?object
    {
        if ($ticketId <= 0 || $tipoOperacionId <= 0) {
            return null;
        }

        $existingHistorial = $this->getLatestHistorialForTicket($ticketId);
        if ($existingHistorial) {
            return $existingHistorial;
        }

        $flujo = DB::table('flujo')
            ->where('tipoOperacion_idtipoOperacion', $tipoOperacionId)
            ->orderBy('idflujo')
            ->first();

        if (!$flujo) {
            return null;
        }

        $reglas = DB::table('flujoregla')
            ->where('flujo_idflujo', (int) $flujo->idflujo)
            ->where('estado', '1')
            ->orderBy('orden')
            ->orderBy('idflujoregla')
            ->get();

        $firstRule = $reglas->first();
        if (!$firstRule) {
            return null;
        }

        $usuario = trim((string) $usuario);
        if ($usuario === '') {
            $usuario = $this->resolveCurrentAuthUsuario() ?? $this->resolveDefaultUsuario();
        } else {
            $usuario = $this->resolveUsuarioFromFullnameOrDni($usuario);
        }

        DB::table('historialflujo')->insert([
            'flujoregla_idflujoregla' => (int) $firstRule->idflujoregla,
            'ticket_idticket' => $ticketId,
            'usuario_usuario' => $usuario,
            'vista_idvista' => (int) $firstRule->vista_idvista,
            'detalle' => 'Gestión creada',
            'resultado' => 'creado',
            'fechaejecucion' => now()->format('Y-m-d H:i:s'),
        ]);

        return $this->getLatestHistorialForTicket($ticketId);
    }

    private function resolveUsuarioFromFullnameOrDni(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return $this->resolveCurrentAuthUsuario() ?? $this->resolveDefaultUsuario();
        }

        $usuarioRow = DB::table('usuario')
            ->where('usuario', $input)
            ->first();

        if ($usuarioRow) {
            return (string) $usuarioRow->usuario;
        }

        $usuarioRow = DB::table('usuario')
            ->where('personal_dniPersonal', $input)
            ->first();

        if ($usuarioRow) {
            return (string) $usuarioRow->usuario;
        }

        $parts = explode(' ', $input);
        if (count($parts) >= 2) {
            $usuarioRow = DB::table('usuario as u')
                ->join('personal as p', 'u.personal_dniPersonal', '=', 'p.dniPersonal')
                ->where(DB::raw("CONCAT(TRIM(p.nombre), ' ', TRIM(p.apellido))"), 'like', '%' . $input . '%')
                ->select('u.usuario')
                ->first();

            if ($usuarioRow) {
                return (string) $usuarioRow->usuario;
            }
        }

        return $this->resolveCurrentAuthUsuario() ?? $this->resolveDefaultUsuario();
    }

    private function resolveCurrentAuthUsuario(): ?string
    {
        $currentUser = trim((string) session('erp_auth.usuario', ''));
        return $currentUser !== '' ? $currentUser : null;
    }

    private function resolveDefaultUsuario(): string
    {
        return (string) DB::table('usuario')->orderBy('usuario')->value('usuario');
    }

    private function getLatestHistorialForTicket(int $ticketId): ?object
    {
        return DB::table('historialflujo')
            ->where('ticket_idticket', $ticketId)
            ->orderByDesc('idhistorialflujo')
            ->first();
    }

    /**
     * Resuelve la regla actual a partir de una colección de reglas y el último historial.
     * Usado internamente por resolveTicketListDisplayInfo.
     */
    private function resolveCurrentRuleFromCollection(?object $historial, Collection $reglas): ?object
    {
        if ($historial && !empty($historial->flujoregla_idflujoregla)) {
            $rule = $reglas->firstWhere('idflujoregla', (int) $historial->flujoregla_idflujoregla);
            if ($rule) {
                return $rule;
            }
        }

        return $reglas->first();
    }

    /**
     * Normaliza un valor de estado a su forma canónica.
     * Copia del helper del controlador para no acoplarse a él.
     */
    private function normalizeEstadoValue(?string $estado): string
    {
        $estado = mb_strtolower(trim((string) $estado));

        return match ($estado) {
            'activo', 'nuevo', 'asignado'    => 'Activo',
            'en proceso', 'en progreso'      => 'En proceso',
            'resuelto', 'cancelado'          => 'Resuelto',
            default                          => 'Activo',
        };
    }
}
