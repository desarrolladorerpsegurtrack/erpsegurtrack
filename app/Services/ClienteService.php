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
            $paginated->getCollection()->map(fn ($row) => $this->hydrateRow($row, true))
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

        return [
            'total' => $totalClientes,
            'active' => $clientesActivos,
            'inactive' => max($totalClientes - $clientesActivos, 0),
        ];
    }

    public function getClientExportRows(Request $request): Collection
    {
        return $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request))
            ->orderByDesc('c.fechaIngreso')
            ->orderBy('c.idcliente')
            ->get()
            ->map(fn ($row) => $this->hydrateRow($row, false));
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
            'estado' => trim((string) $request->input('estado', '')),
            'nombre' => trim((string) $request->input('nombre', '')),
            'rubro' => trim((string) $request->input('rubro', '')),
            'grupo' => trim((string) $request->input('grupo', '')),
        ];
    }

    private function hydrateRow(object $row, bool $useHtml): object
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

        return $row;
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

    public function getTiposContacto(): Collection
    {
        return DB::table('tipocontacto')
            ->orderBy('detalle')
            ->get();
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

    public function getContactosByCliente(string $cliente): Collection
    {
        $contactos = DB::table('contacto as c')
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
            ->where('c.cliente_idcliente', $cliente)
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
}
