<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClienteService
{
    public function getClientList(Request $request, int $perPage): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request));

        $paginated = $query
            ->orderByDesc('c.fechaIngreso')
            ->orderBy('c.idcliente')
            ->paginate($perPage)
            ->withQueryString();

        $paginated->setCollection(
            $paginated->getCollection()->map(fn ($row) => $this->hydrateRow($row, true, true))
        );

        return $paginated;
    }

    public function getClientStatistics(Request $request): array
    {
        $query = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request));

        $totalClientes = (clone $query)->count();
        $clientesActivos = (clone $query)
            ->where(function (Builder $query) {
                $query->whereNull('ec.detalle')
                    ->orWhere('ec.detalle', '!=', 'Inactivo');
            })
            ->count();
        $rubro = (clone $query)->distinct('c.rubro')->count('c.rubro');

        return [
            'Total de Clientes' => $totalClientes,
            'Clientes Activos' => $clientesActivos,
            'Clientes Inactivos' => max($totalClientes - $clientesActivos, 0),
            'Total de Rubro' => $rubro,
        ];
    }

    public function getClientExportRows(Request $request): Collection
    {
        return $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request))
            ->orderByDesc('c.fechaIngreso')
            ->orderBy('c.idcliente')
            ->get()
            ->map(fn ($row) => $this->hydrateRow($row, false, false));
    }

    public function getExportColumns(): array
    {
        return [
            ['key' => 'nombreComercial', 'label' => 'Nombre Comercial'],
            ['key' => 'razonSocial', 'label' => 'Razón Social'],
            ['key' => 'grupo_asignado', 'label' => 'Grupo Asignado'],
            ['key' => 'rubro', 'label' => 'Rubro'],
            ['key' => 'direccion_completa', 'label' => 'Dirección'],
            ['key' => 'estadoDetalle', 'label' => 'Estado'],
        ];
    }

    private function buildBaseQuery(): Builder
    {
        return DB::table('cliente as c')
            ->leftJoin('estadocliente as ec', 'c.estadoCliente_idestadoCliente', '=', 'ec.idestadoCliente')
            ->leftJoin('direccioncliente as dc', function ($join) {
                $join->on('c.idcliente', '=', 'dc.cliente_idcliente')
                    ->where(function ($query) {
                        $query->where('dc.default', 1)
                            ->orWhere(function ($subQuery) {
                                $subQuery->whereNull('dc.default')
                                    ->whereRaw('dc.iddireccionCliente = (select max(inner_dc.iddireccionCliente) from direccioncliente as inner_dc where inner_dc.cliente_idcliente = dc.cliente_idcliente)');
                            });
                    });
            })
            ->leftJoin('ubigeo as u', 'dc.ubigeo_idubigeo', '=', 'u.idubigeo')
            ->leftJoin('detallegrupocliente as dgc', 'c.idcliente', '=', 'dgc.cliente_idcliente')
            ->leftJoin('grupocliente as gc', 'dgc.grupoCliente_idgrupoCliente', '=', 'gc.idgrupoCliente')
            ->select(
                'c.*',
                'ec.detalle as estadoDetalle',
                'dc.tipo as direccionTipo',
                'dc.direccion as direccionTexto',
                'u.departamento',
                'u.provincia',
                'u.distrito',
                'gc.nombreGrupo as grupoNombre'
            );
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $query->where(function (Builder $query) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $query->where('c.idcliente', 'like', $term)
                    ->orWhere('c.razonSocial', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('c.rubro', 'like', $term)
                    ->orWhere('gc.nombreGrupo', 'like', $term)
                    ->orWhere('dc.direccion', 'like', $term);
            });
        }
        
        if ($filters['idcliente'] !== '') {
            $query->where('c.idcliente', 'like', '%' . $filters['idcliente'] . '%');
        }

        if ($filters['estado'] !== '') {
            $query->where('c.estadoCliente_idestadoCliente', $filters['estado']);
        }

        if ($filters['nombre'] !== '') {
            $query->where(function (Builder $query) use ($filters) {
                $term = '%' . $filters['nombre'] . '%';
                $query->where('c.nombreComercial', 'like', $term)
                    ->orWhere('c.razonSocial', 'like', $term);
            });
        }

        if ($filters['rubro'] !== '') {
            $query->where('c.rubro', 'like', '%' . $filters['rubro'] . '%');
        }

        if ($filters['grupo'] !== '') {
            $query->where('gc.nombreGrupo', 'like', '%' . $filters['grupo'] . '%');
        }

        return $query;
    }

    private function extractFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->input('q', '')),
            'idcliente' => trim((string) $request->input('idcliente', '')),
            'estado' => trim((string) $request->input('estado', '')),
            'nombre' => trim((string) $request->input('nombre', '')),
            'rubro' => trim((string) $request->input('rubro', '')),
            'grupo' => trim((string) $request->input('grupo', '')),
        ];
    }

    private function hydrateRow(object $row, bool $useHtml, bool $includeRelations): object
    {
        $direccion = trim((string) ($row->direccionTexto ?? ''));
        $ubigeoText = trim("{$row->departamento} / {$row->provincia} / {$row->distrito}", ' /');

        if (!empty($direccion) && !empty($ubigeoText)) {
            $row->direccion_completa = $useHtml
                ? $direccion . '<br><span class="text-slate-500 text-xs">' . $ubigeoText . '</span>'
                : $direccion . ' ' . $ubigeoText;
        } elseif (!empty($direccion)) {
            $row->direccion_completa = $direccion;
        } else {
            $row->direccion_completa = $useHtml
                ? '<span class="text-slate-500 text-xs">' . $ubigeoText . '</span>'
                : $ubigeoText;
        }

        $row->grupo_asignado = $row->grupoNombre ?? 'Sin grupo';
        $row->relation_groups = $includeRelations ? $this->buildRelationGroups((string) ($row->idcliente ?? '')) : [];

        return $row;
    }

    public function buildRelationGroups(string $clienteId): array
    {
        $clienteId = trim($clienteId);
        if ($clienteId === '') {
            return [];
        }

        $groups = [];

        $servicios = DB::table('serviciocliente as sc')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->select([
                'sc.idservicioCliente',
                'sc.vehiculo_placa',
                'sc.fechaInicio',
                'sc.fecheVencimiento',
                'sc.monto',
                'sc.estado',
                'sc.docReferencia',
                DB::raw('COALESCE(v.marca, "") as vehiculo_marca'),
                DB::raw('COALESCE(v.modelo, "") as vehiculo_modelo'),
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
            ])
            ->where('sc.cliente_idcliente', $clienteId)
            ->orderByDesc('sc.idservicioCliente')
            ->get();

        if ($servicios->isNotEmpty()) {
            $groups[] = [
                'key' => 'servicio_cliente',
                'label' => 'Servicio cliente',
                'columns' => [
                    ['key' => 'idservicioCliente', 'label' => 'ID'],
                    ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
                    ['key' => 'almacen_detalle', 'label' => 'Almacén'],
                    ['key' => 'fechaInicio', 'label' => 'Inicio'],
                    ['key' => 'fecheVencimiento', 'label' => 'Vencimiento'],
                    ['key' => 'monto', 'label' => 'Monto'],
                    ['key' => 'estado', 'label' => 'Estado'],
                    ['key' => 'docReferencia', 'label' => 'Documento'],
                ],
                'records' => $servicios->map(fn ($row) => (array) $row)->all(),
            ];
        }

        $vehiculos = DB::table('vehiculo as v')
            ->leftJoin('tipovehiculo as tv', 'tv.idtipoVehiculo', '=', 'v.tipoUnidad_idtable1')
            ->select([
                'v.placa',
                'v.tipoUnidad_idtable1',
                'v.anio',
                'v.color',
                'v.marca',
                'v.modelo',
                'v.tracto',
                DB::raw('COALESCE(tv.nombre, "") as tipo_vehiculo'),
                // número activo más reciente para el vehículo (si existe)
                DB::raw('(select n.numeroTelefonico_numeroTelefonico from detnumerosdispositivo n join dispositivocliente dc on dc.iddispositivoCliente = n.dispositivoCliente_iddispositivoCliente where dc.vehiculo_placa = v.placa order by n.fechaAsignacion desc, n.iddetNumerosDispositivo desc limit 1) as numero'),
            ])
            ->where('v.cliente_idcliente', $clienteId)
            ->orderBy('v.placa')
            ->get();

        if ($vehiculos->isNotEmpty()) {
            $groups[] = [
                'key' => 'vehiculos',
                'label' => 'Vehículos',
                'columns' => [
                    ['key' => 'placa', 'label' => 'Placa'],
                    ['key' => 'numero', 'label' => 'Número'],
                    ['key' => 'tipo_vehiculo', 'label' => 'Tipo'],
                    ['key' => 'anio', 'label' => 'Año'],
                    ['key' => 'marca', 'label' => 'Marca'],
                    ['key' => 'modelo', 'label' => 'Modelo'],
                    ['key' => 'color', 'label' => 'Color'],
                    ['key' => 'tracto', 'label' => 'Tracto'],
                ],
                'records' => $vehiculos->map(fn ($row) => (array) $row)->all(),
            ];
        }

        $dispositivos = DB::table('dispositivocliente as d')
            ->select([
                'd.iddispositivoCliente',
                'd.vehiculo_placa',
                'd.marcaDispositivo',
                'd.modeloDispositivo',
                'd.fechaInstalacion',
                'd.fechaBaja',
                'd.estado',
                DB::raw('(select n.numeroTelefonico_numeroTelefonico from detnumerosdispositivo n where n.dispositivoCliente_iddispositivoCliente = d.iddispositivoCliente order by n.fechaAsignacion desc, n.iddetNumerosDispositivo desc limit 1) as numero'),
            ])
            ->whereIn('d.vehiculo_placa', DB::table('vehiculo')->where('cliente_idcliente', $clienteId)->select('placa'))
            ->orderBy('d.iddispositivoCliente')
            ->get();

        if ($dispositivos->isNotEmpty()) {
            $groups[] = [
                'key' => 'dispositivo_cliente',
                'label' => 'Dispositivo cliente',
                'columns' => [
                    ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo'],
                    ['key' => 'numero', 'label' => 'Número'],
                    ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
                    ['key' => 'marcaDispositivo', 'label' => 'Marca'],
                    ['key' => 'modeloDispositivo', 'label' => 'Modelo'],
                    ['key' => 'fechaInstalacion', 'label' => 'Fecha instalación'],
                    ['key' => 'fechaBaja', 'label' => 'Fecha baja'],
                    ['key' => 'estado', 'label' => 'Estado'],
                ],
                'records' => $dispositivos->map(fn ($row) => (array) $row)->all(),
            ];
        }

        return $groups;
    }

    public function getEstados(): Collection
    {
        return DB::table('estadocliente')
            ->orderBy('idestadoCliente')
            ->get();
    }

    public function getGrupos(): Collection
    {
        return DB::table('grupocliente')
            ->orderBy('nombreGrupo')
            ->get();
    }

    public function getUbigeos(): Collection
    {
        $ubigeos = DB::table('ubigeo')
            ->orderBy('departamento')
            ->orderBy('provincia')
            ->orderBy('distrito')
            ->get();

        return $ubigeos->map(function ($row) {
            $departamento = $row->departamento ?? '';
            $provincia = $row->provincia ?? '';
            $distrito = $row->distrito ?? '';
            $row->ubigeo_text = trim("{$departamento} / {$provincia} / {$distrito}", ' /');

            return $row;
        });
    }

    public function getAllowedContactTypes(?string $username): array
    {
        if (empty($username)) {
            return [];
        }

        $roleRows = DB::table('detallerol as ur')
            ->join('rol as r', 'ur.rol_idrol', '=', 'r.idrol')
            ->select('r.idrol', 'r.nombre')
            ->where('ur.usuario_usuario', $username)
            ->get();

        $adminRoles = ['admin'];
        foreach ($roleRows as $row) {
            if (in_array(mb_strtolower(trim((string)$row->nombre)), $adminRoles, true)) {
                return ['*'];
            }
        }

        $roleIds = $roleRows->pluck('idrol')->all();

        if (empty($roleIds)) {
            return [];
        }

        $modulos = DB::table('inforol')
            ->whereIn('rol_idrol', $roleIds)
            ->where('accion', 'ver')
            ->where('modulo', 'like', 'cliente.tipo_contacto.%')
            ->pluck('modulo');

        if ($modulos->contains('cliente.tipo_contacto.*')) {
            return ['*'];
        }

        return $modulos
            ->map(function ($module) {
                $module = (string) $module;
                return (int) str_replace('cliente.tipo_contacto.', '', $module);
            })
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function getTiposContacto(?array $allowedIds = null): Collection
    {
        $query = DB::table('tipocontacto')->orderBy('detalle');

        if ($allowedIds !== null && !in_array('*', $allowedIds, true)) {
            if (empty($allowedIds)) {
                return collect();
            }
            $query->whereIn('idtipoContacto', $allowedIds);
        }

        return $query->get();
    }

    public function getDirecciones(?string $cliente = null): Collection
    {
        $direcciones = DB::table('direccioncliente as d')
            ->leftJoin('ubigeo as u', 'd.ubigeo_idubigeo', '=', 'u.idubigeo')
            ->select('d.iddireccionCliente', 'd.tipo', 'd.direccion', 'd.linkUbicacion', 'd.ubigeo_idubigeo', 'u.departamento', 'u.provincia', 'u.distrito')
            ->when($cliente !== null, function ($query) use ($cliente) {
                return $query->where('d.cliente_idcliente', $cliente);
            }, function ($query) {
                return $query->where('d.cliente_idcliente', '')->orWhereNull('d.cliente_idcliente');
            })
            ->orderByDesc('d.default')
            ->orderByDesc('d.iddireccionCliente')
            ->get();

        return $direcciones->map(function ($row) {
            $departamento = $row->departamento ?? '';
            $provincia = $row->provincia ?? '';
            $distrito = $row->distrito ?? '';
            $ubigeo_text = trim("{$departamento} / {$provincia} / {$distrito}", ' /');
            $row->ubigeo_text = $ubigeo_text;

            $row->label_completo = !empty($ubigeo_text)
                ? "{$row->direccion} ({$ubigeo_text})"
                : "{$row->direccion}";

            return $row;
        });
    }

    public function getContactosByCliente(string $cliente, ?array $allowedIds = null): Collection
    {
        $query = DB::table('contacto as c')
            ->leftJoin('tipocontacto as tc', 'c.tipoContacto_idtipoContacto', '=', 'tc.idtipoContacto')
            ->select(
                'c.idcontacto',
                'c.tipoContacto_idtipoContacto',
                'c.nombreApellido',
                'c.cargo',
                'c.correo',
                'c.correo2',
                'c.numero',
                'c.numero2',
                'tc.detalle as tipoDetalle'
            )
            ->where('c.cliente_idcliente', $cliente);

        if ($allowedIds !== null && !in_array('*', $allowedIds, true)) {
            if (empty($allowedIds)) {
                return collect();
            }
            $query->whereIn('c.tipoContacto_idtipoContacto', $allowedIds);
        }

        $contactos = $query
            ->orderByDesc('c.default')
            ->orderByDesc('c.idcontacto')
            ->get();

        return $contactos->map(function ($row) {
            $nombre = trim((string) ($row->nombreApellido ?? ''));
            $tipo = trim((string) ($row->tipoDetalle ?? ''));
            $numero = trim((string) ($row->numero ?? ''));

            $base = $nombre !== '' ? $nombre : ('Contacto #' . $row->idcontacto);
            if ($tipo !== '') {
                $base .= " ({$tipo})";
            }
            if ($numero !== '') {
                $base .= " - {$numero}";
            }

            $row->label_completo = $base;
            return $row;
        });
    }

    public function getCredencialesByCliente(string $cliente): Collection
    {
        $credenciales = DB::table('credenciales')
            ->select('idcredenciales', 'usuario', 'clave', 'fechaCreacion', 'estadoRecepcion')
            ->where('cliente_idcliente', $cliente)
            ->orderByDesc('idcredenciales')
            ->get();

        return $credenciales->map(function ($row) {
            $usuario = trim((string) ($row->usuario ?? ''));
            $fecha = trim((string) ($row->fechaCreacion ?? ''));
            $estado = trim((string) ($row->estadoRecepcion ?? '')) === '1' ? 'Sí' : 'No';

            $label = $usuario !== '' ? $usuario : ('Credencial #' . $row->idcredenciales);
            if ($fecha !== '') {
                $label .= ' - ' . $this->formatFechaParaLabel($fecha);
            }
            $label .= ' - ' . $estado;

            $row->label_completo = $label;
            return $row;
        });
    }

    public function formatFechaParaLabel(string $fecha): string
    {
        try {
            $carbon = Carbon::parse($fecha);
            $month = [
                '01' => 'ene',
                '02' => 'feb',
                '03' => 'mar',
                '04' => 'abr',
                '05' => 'may',
                '06' => 'jun',
                '07' => 'jul',
                '08' => 'ago',
                '09' => 'sep',
                '10' => 'oct',
                '11' => 'nov',
                '12' => 'dic',
            ][$carbon->format('m')] ?? $carbon->format('M');

            return $carbon->format('d') . ' ' . $month . ', ' . $carbon->format('Y');
        } catch (\Exception $exception) {
            return $fecha;
        }
    }

    public function syncClientAddress(string $cliente, string|int $direccionId): void
    {
        if (! is_numeric($direccionId)) {
            return;
        }

        $direccionId = (int) $direccionId;
        DB::table('direccioncliente')->where('cliente_idcliente', $cliente)->update(['default' => 0]);

        $exists = DB::table('direccioncliente')
            ->where('iddireccionCliente', $direccionId)
            ->where(function ($query) use ($cliente) {
                $query->where('cliente_idcliente', $cliente)
                    ->orWhere('cliente_idcliente', '')->orWhereNull('cliente_idcliente');
            })
            ->exists();

        if ($exists) {
            DB::table('direccioncliente')
                ->where('iddireccionCliente', $direccionId)
                ->update(['cliente_idcliente' => $cliente, 'default' => 1]);
        }
    }

    public function syncClientContact(string $cliente, string|int $contactoId): void
    {
        if (! is_numeric($contactoId)) {
            return;
        }

        $contactoId = (int) $contactoId;
        DB::table('contacto')->where('cliente_idcliente', $cliente)->update(['default' => 0]);

        $exists = DB::table('contacto')
            ->where('idcontacto', $contactoId)
            ->where('cliente_idcliente', $cliente)
            ->exists();

        if ($exists) {
            DB::table('contacto')
                ->where('idcontacto', $contactoId)
                ->update(['default' => 1]);
        }
    }

    private function decodeCredencialesPayload(string $payload): array
    {
        $raw = trim($payload);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                'credenciales_payload' => 'El formato de credenciales temporales es invalido.',
            ]);
        }

        return array_values(array_filter($decoded, static fn ($item) => is_array($item)));
    }

    public function insertCredencialesTemporales(string $clienteId, string $payload, ?string &$selectedCredencialId = null): array
    {
        $credenciales = $this->decodeCredencialesPayload($payload);
        if (empty($credenciales)) {
            return [];
        }

        $nextId = ((int) DB::table('credenciales')->max('idcredenciales')) + 1;
        $tempIdMap = [];
        $usuarioUsuario = session('erp_auth.usuario');

        foreach ($credenciales as $credencial) {
            $tempId = isset($credencial['tempId']) ? (string) $credencial['tempId'] : null;
            $validated = Validator::make($credencial, [
                'usuario' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[^;<>`]+$/u'],
                'clave' => ['required', 'string', 'min:8', 'max:100'],
                'fechaCreacion' => ['nullable', 'date'],
                'estadoRecepcion' => ['required', 'in:0,1'],
            ])->validate();

            DB::table('credenciales')->insert([
                'idcredenciales' => $nextId,
                'cliente_idcliente' => $clienteId,
                'usuario_usuario' => $usuarioUsuario,
                'usuario' => $validated['usuario'],
                'clave' => $validated['clave'],
                'fechaCreacion' => $validated['fechaCreacion'] ?? now(),
                'estadoRecepcion' => $validated['estadoRecepcion'],
            ]);

            if ($tempId !== null) {
                $tempIdMap[$tempId] = $nextId;
            }

            $nextId++;
        }

        if ($selectedCredencialId !== null && isset($tempIdMap[$selectedCredencialId])) {
            $selectedCredencialId = (string) $tempIdMap[$selectedCredencialId];
        }

        return $tempIdMap;
    }

    private function decodeContactosPayload(string $payload): array
    {
        $raw = trim($payload);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                'contactos_payload' => 'El formato de contactos temporales es invalido.',
            ]);
        }

        return array_values(array_filter($decoded, static fn ($item) => is_array($item)));
    }

    public function insertContactosTemporales(string $clienteId, string $payload, ?string &$selectedContactId = null): array
    {
        $contactos = $this->decodeContactosPayload($payload);
        if (empty($contactos)) {
            return [];
        }

        $nextId = ((int) DB::table('contacto')->max('idcontacto')) + 1;
        $tempIdMap = [];

        foreach ($contactos as $contacto) {
            $tempId = isset($contacto['tempId']) ? (string) $contacto['tempId'] : null;
            $validated = Validator::make($contacto, [
                'tipoContacto_idtipoContacto' => ['required', 'integer', 'exists:tipocontacto,idtipoContacto'],
                'nombreApellido' => ['required', 'string', 'max:100', 'regex:/^[^;<>`]+$/u'],
                'cargo' => ['nullable', 'string', 'max:50', 'regex:/^[^;<>`]+$/u'],
                'correo' => ['nullable', 'email', 'max:100'],
                'correo2' => ['nullable', 'email', 'max:100'],
                'numero' => ['nullable', 'string', 'max:15', 'regex:/^[0-9+\-\s()]*$/'],
                'numero2' => ['nullable', 'string', 'max:15', 'regex:/^[0-9+\-\s()]*$/'],
            ])->validate();

            DB::table('contacto')->insert([
                'idcontacto' => $nextId,
                'cliente_idcliente' => $clienteId,
                'tipoContacto_idtipoContacto' => $validated['tipoContacto_idtipoContacto'],
                'nombreApellido' => $validated['nombreApellido'],
                'cargo' => $validated['cargo'] ?? null,
                'correo' => $validated['correo'] ?? null,
                'correo2' => $validated['correo2'] ?? null,
                'numero' => $validated['numero'] ?? null,
                'numero2' => $validated['numero2'] ?? null,
            ]);

            if ($tempId !== null) {
                $tempIdMap[$tempId] = $nextId;
            }

            $nextId++;
        }

        if ($selectedContactId !== null && isset($tempIdMap[$selectedContactId])) {
            $selectedContactId = (string) $tempIdMap[$selectedContactId];
        }

        return $tempIdMap;
    }

    private function decodeDireccionesPayload(string $payload): array
    {
        $raw = trim($payload);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                'direcciones_payload' => 'El formato de direcciones temporales es invalido.',
            ]);
        }

        return array_values(array_filter($decoded, static fn ($item) => is_array($item)));
    }

    public function insertDireccionesTemporales(string $clienteId, string $payload, ?string &$selectedAddressId = null): array
    {
        $direcciones = $this->decodeDireccionesPayload($payload);
        if (empty($direcciones)) {
            return [];
        }

        $nextId = ((int) DB::table('direccioncliente')->max('iddireccionCliente')) + 1;
        $tempIdMap = [];

        foreach ($direcciones as $direccion) {
            $tempId = isset($direccion['tempId']) ? (string) $direccion['tempId'] : null;
            $validated = Validator::make($direccion, [
                'tipo' => ['nullable', 'string', 'max:45', 'regex:/^[^;<>`]+$/u'],
                'direccion' => ['required', 'string', 'max:200', 'regex:/^[^;<>`]+$/u'],
                'linkUbicacion' => ['nullable', 'url', 'max:300'],
                'ubigeo_idubigeo' => ['required', 'integer', 'exists:ubigeo,idubigeo'],
            ])->validate();

            DB::table('direccioncliente')->insert([
                'iddireccionCliente' => $nextId,
                'tipo' => $validated['tipo'] ?? null,
                'direccion' => $validated['direccion'],
                'linkUbicacion' => $validated['linkUbicacion'] ?? null,
                'ubigeo_idubigeo' => $validated['ubigeo_idubigeo'],
                'cliente_idcliente' => $clienteId,
                'default' => null,
            ]);

            if ($tempId !== null) {
                $tempIdMap[$tempId] = $nextId;
            }

            $nextId++;
        }

        if ($selectedAddressId !== null && isset($tempIdMap[$selectedAddressId])) {
            $selectedAddressId = (string) $tempIdMap[$selectedAddressId];
        }

        return $tempIdMap;
    }

    public function getSelectedClientExportRows(array $selectedIds): Collection
    {
        $rows = collect();

        // Obtener clientes seleccionados
        $clientes = $this->buildBaseQuery()
            ->whereIn('c.idcliente', $selectedIds)
            ->orderByDesc('c.fechaIngreso')
            ->orderBy('c.idcliente')
            ->get()
            ->map(fn ($row) => $this->hydrateRow($row, false, false));

        foreach ($clientes as $cliente) {
            // Agregar fila del cliente
            $rows->push((object) [
                'tipo' => 'Cliente',
                'idcliente' => $cliente->idcliente ?? '',
                'nombreComercial' => $cliente->nombreComercial ?? '',
                'razonSocial' => $cliente->razonSocial ?? '',
                'grupo_asignado' => $cliente->grupo_asignado ?? '',
                'rubro' => $cliente->rubro ?? '',
                'direccion_completa' => $cliente->direccion_completa ?? '',
                'estadoDetalle' => $cliente->estadoDetalle ?? '',
            ]);

            $clienteId = (string) ($cliente->idcliente ?? '');
            if ($clienteId === '') {
                continue;
            }

            // Agregar servicios del cliente
            $servicios = DB::table('serviciocliente as sc')
                ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
                ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
                ->select([
                    'sc.idservicioCliente',
                    'sc.vehiculo_placa',
                    'sc.fechaInicio',
                    'sc.fecheVencimiento',
                    'sc.monto',
                    'sc.estado',
                    'sc.docReferencia',
                    DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                ])
                ->where('sc.cliente_idcliente', $clienteId)
                ->orderByDesc('sc.idservicioCliente')
                ->get();

            foreach ($servicios as $servicio) {
                $rows->push((object) [
                    'tipo' => 'Servicio',
                    'idcliente' => $clienteId,
                    'nombreComercial' => $cliente->nombreComercial ?? '',
                    'idservicioCliente' => $servicio->idservicioCliente ?? '',
                    'vehiculo_placa' => $servicio->vehiculo_placa ?? '',
                    'almacen_detalle' => $servicio->almacen_detalle ?? '',
                    'fechaInicio' => $servicio->fechaInicio ?? '',
                    'fecheVencimiento' => $servicio->fecheVencimiento ?? '',
                    'monto' => $servicio->monto ?? '',
                    'estadoServicio' => $servicio->estado ?? '',
                    'docReferencia' => $servicio->docReferencia ?? '',
                ]);
            }

            // Agregar vehículos del cliente
            $vehiculos = DB::table('vehiculo as v')
                ->leftJoin('tipovehiculo as tv', 'tv.idtipoVehiculo', '=', 'v.tipoUnidad_idtable1')
                ->select([
                    'v.placa',
                    'v.anio',
                    'v.color',
                    'v.marca',
                    'v.modelo',
                    'v.tracto',
                    DB::raw('COALESCE(tv.nombre, "") as tipo_vehiculo'),
                ])
                ->where('v.cliente_idcliente', $clienteId)
                ->orderBy('v.placa')
                ->get();

            foreach ($vehiculos as $vehiculo) {
                $rows->push((object) [
                    'tipo' => 'Vehículo',
                    'idcliente' => $clienteId,
                    'nombreComercial' => $cliente->nombreComercial ?? '',
                    'placa' => $vehiculo->placa ?? '',
                    'tipo_vehiculo' => $vehiculo->tipo_vehiculo ?? '',
                    'anio' => $vehiculo->anio ?? '',
                    'marca' => $vehiculo->marca ?? '',
                    'modelo' => $vehiculo->modelo ?? '',
                    'color' => $vehiculo->color ?? '',
                    'tracto' => $vehiculo->tracto ?? '',
                ]);
            }

            // Agregar dispositivos del cliente
            $dispositivos = DB::table('dispositivocliente as d')
                ->select([
                    'd.iddispositivoCliente',
                    'd.vehiculo_placa',
                    'd.marcaDispositivo',
                    'd.modeloDispositivo',
                    'd.fechaInstalacion',
                    'd.fechaBaja',
                    'd.estado',
                ])
                ->whereIn('d.vehiculo_placa', DB::table('vehiculo')->where('cliente_idcliente', $clienteId)->select('placa'))
                ->orderBy('d.iddispositivoCliente')
                ->get();

            foreach ($dispositivos as $dispositivo) {
                $rows->push((object) [
                    'tipo' => 'Dispositivo',
                    'idcliente' => $clienteId,
                    'nombreComercial' => $cliente->nombreComercial ?? '',
                    'iddispositivoCliente' => $dispositivo->iddispositivoCliente ?? '',
                    'vehiculo_placa' => $dispositivo->vehiculo_placa ?? '',
                    'marcaDispositivo' => $dispositivo->marcaDispositivo ?? '',
                    'modeloDispositivo' => $dispositivo->modeloDispositivo ?? '',
                    'fechaInstalacion' => $dispositivo->fechaInstalacion ?? '',
                    'fechaBaja' => $dispositivo->fechaBaja ?? '',
                    'estadoDispositivo' => $dispositivo->estado ?? '',
                ]);
            }
        }

        return $rows;
    }

    /**
     * Retorna grupos por cliente con sus servicios, vehiculos y dispositivos.
     * Cada grupo: ['cliente' => object, 'servicios' => Collection, 'vehiculos' => Collection, 'dispositivos' => Collection]
     */
    public function getSelectedClientExportGroups(array $selectedIds): array
    {
        $groups = [];

        $clientes = $this->buildBaseQuery()
            ->whereIn('c.idcliente', $selectedIds)
            ->orderByDesc('c.fechaIngreso')
            ->orderBy('c.idcliente')
            ->get()
            ->map(fn ($row) => $this->hydrateRow($row, false, false));

        foreach ($clientes as $cliente) {
            $clienteId = (string) ($cliente->idcliente ?? '');
            if ($clienteId === '') {
                continue;
            }

            $servicios = DB::table('serviciocliente as sc')
                ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
                ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
                ->select([
                    'sc.idservicioCliente',
                    'sc.vehiculo_placa',
                    'sc.fechaInicio',
                    'sc.fecheVencimiento',
                    'sc.monto',
                    'sc.estado',
                    'sc.docReferencia',
                    DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                ])
                ->where('sc.cliente_idcliente', $clienteId)
                ->orderByDesc('sc.idservicioCliente')
                ->get();

            $vehiculos = DB::table('vehiculo as v')
                ->leftJoin('tipovehiculo as tv', 'tv.idtipoVehiculo', '=', 'v.tipoUnidad_idtable1')
                ->select([
                    'v.placa',
                    'v.anio',
                    'v.color',
                    'v.marca',
                    'v.modelo',
                    'v.tracto',
                    DB::raw('COALESCE(tv.nombre, "") as tipo_vehiculo'),
                ])
                ->where('v.cliente_idcliente', $clienteId)
                ->orderBy('v.placa')
                ->get();

            $dispositivos = DB::table('dispositivocliente as d')
                ->select([
                    'd.iddispositivoCliente',
                    'd.vehiculo_placa',
                    'd.marcaDispositivo',
                    'd.modeloDispositivo',
                    'd.fechaInstalacion',
                    'd.fechaBaja',
                    'd.estado',
                ])
                ->whereIn('d.vehiculo_placa', DB::table('vehiculo')->where('cliente_idcliente', $clienteId)->select('placa'))
                ->orderBy('d.iddispositivoCliente')
                ->get()
                ->map(function ($d) {
                    $d->estado = ((string) $d->estado === '1' || (string) $d->estado === 'Activo' || (string) $d->estado === 'activo') ? 'Activo' : 'Inactivo';
                    return $d;
                });

            $group = [
                'cliente' => $cliente,
                'servicios' => $servicios->values()->all(),
                'vehiculos' => $vehiculos->values()->all(),
                'dispositivos' => $dispositivos->values()->all(),
            ];

            $groups[] = $group;
        }

        return $groups;
    }

    public function getExpandedExportColumns(): array
    {
        return [
            ['key' => 'tipo', 'label' => 'Tipo'],
            ['key' => 'idcliente', 'label' => 'RUC/DNI Cliente'],
            ['key' => 'nombreComercial', 'label' => 'Nombre Comercial'],
            ['key' => 'razonSocial', 'label' => 'Razón Social'],
            ['key' => 'grupo_asignado', 'label' => 'Grupo Asignado'],
            ['key' => 'rubro', 'label' => 'Rubro'],
            ['key' => 'direccion_completa', 'label' => 'Dirección'],
            ['key' => 'estadoDetalle', 'label' => 'Estado Cliente'],
            ['key' => 'idservicioCliente', 'label' => 'ID Servicio'],
            ['key' => 'vehiculo_placa', 'label' => 'Placa Vehículo'],
            ['key' => 'almacen_detalle', 'label' => 'Almacén'],
            ['key' => 'fechaInicio', 'label' => 'Fecha Inicio Servicio'],
            ['key' => 'fecheVencimiento', 'label' => 'Fecha Vencimiento'],
            ['key' => 'monto', 'label' => 'Monto'],
            ['key' => 'estadoServicio', 'label' => 'Estado Servicio'],
            ['key' => 'docReferencia', 'label' => 'Documento Referencia'],
            ['key' => 'placa', 'label' => 'Placa'],
            ['key' => 'tipo_vehiculo', 'label' => 'Tipo Vehículo'],
            ['key' => 'anio', 'label' => 'Año'],
            ['key' => 'marca', 'label' => 'Marca'],
            ['key' => 'modelo', 'label' => 'Modelo'],
            ['key' => 'color', 'label' => 'Color'],
            ['key' => 'tracto', 'label' => 'Tracto'],
            ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo'],
            ['key' => 'marcaDispositivo', 'label' => 'Marca Dispositivo'],
            ['key' => 'modeloDispositivo', 'label' => 'Modelo Dispositivo'],
            ['key' => 'fechaInstalacion', 'label' => 'Fecha Instalación'],
            ['key' => 'fechaBaja', 'label' => 'Fecha Baja Dispositivo'],
            ['key' => 'estadoDispositivo', 'label' => 'Estado Dispositivo'],
        ];
    }
}
