<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GrupoClienteController extends Controller
{
    use ExportableList;

    public function index(Request $request): View
    {
        $baseQuery = DB::table('grupocliente');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query
                    ->where('idgrupoCliente', 'like', $term)
                    ->orWhere('nombreGrupo', 'like', $term);
            });
        }

        if ($request->filled('nombre')) {
            $nombre = trim((string) $request->input('nombre', ''));
            if ($nombre !== '') {
                $baseQuery->where('nombreGrupo', 'like', '%' . $nombre . '%');
            }
        }

        $statsQuery = clone $baseQuery;
        $stats = [
            ['label' => 'Total Grupos', 'value' => (clone $statsQuery)->count()],
        ];

        $grupos = $baseQuery
            ->orderBy('idgrupoCliente')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();
        
        $columns = [
            ['key' => 'idgrupoCliente', 'label' => 'ID', 'type' => 'text'],
            ['key' => 'nombreGrupo', 'label' => 'Nombre', 'type' => 'text'],
        ];
        
        // Adjuntar relation_groups para mostrar los clientes dentro de cada grupo
        $ids = collect($grupos->items())->pluck('idgrupoCliente')->filter()->unique()->values()->all();
        if (!empty($ids)) {
            $clientesRows = DB::table('detallegrupocliente as dgc')
                ->join('cliente as c', 'dgc.cliente_idcliente', '=', 'c.idcliente')
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
                ->leftJoin('contacto as ct', function ($join) {
                    $join->on('c.idcliente', '=', 'ct.cliente_idcliente')
                        ->where(function ($query) {
                            $query->where('ct.default', 1)
                                ->orWhere(function ($subQuery) {
                                    $subQuery->whereNull('ct.default')
                                        ->whereRaw('ct.idcontacto = (select max(inner_ct.idcontacto) from contacto as inner_ct where inner_ct.cliente_idcliente = ct.cliente_idcliente)')
                                        ->whereNotExists(function ($defaultQuery) {
                                            $defaultQuery->select(DB::raw(1))
                                                ->from('contacto as default_ct')
                                                ->whereColumn('default_ct.cliente_idcliente', 'ct.cliente_idcliente')
                                                ->where('default_ct.default', 1);
                                        });
                                });
                        });
                })
                ->whereIn('dgc.grupoCliente_idgrupoCliente', $ids)
                ->select(
                    'dgc.grupoCliente_idgrupoCliente',
                    'c.idcliente',
                    'c.nombreComercial',
                    'c.razonSocial',
                    'c.rubro',
                    'ct.numero as telefono',
                    'ct.correo as email',
                    'dc.direccion',
                    'u.departamento',
                    'u.provincia',
                    'u.distrito',
                    'c.estadoCliente_idestadoCliente'
                )
                ->get()
                ->map(function ($cliente) {
                    $direccion = trim((string) ($cliente->direccion ?? ''));
                    $ubigeoText = trim(("{$cliente->departamento} / {$cliente->provincia} / {$cliente->distrito}"), ' /');
                    $cliente->direccion_completa = trim($direccion . ($direccion !== '' && $ubigeoText !== '' ? ' - ' . $ubigeoText : $ubigeoText));
                    return $cliente;
                });

            $grouped = $clientesRows->groupBy('grupoCliente_idgrupoCliente')->map(function ($group) {
                return $group->map(function ($c) {
                    return (array) $c;
                })->all();
            })->all();

            $newCollection = $grupos->getCollection()->map(function ($row) use ($grouped) {
                $id = data_get($row, 'idgrupoCliente');
                $clients = $grouped[$id] ?? [];

                $relationGroups = [
                    [
                        'key' => 'detallegrupocliente',
                        'label' => 'Clientes',
                        'columns' => [
                            ['key' => 'idcliente', 'label' => 'RUC/DNI', 'type' => 'text'],
                            ['key' => 'razonSocial', 'label' => 'Razón Social', 'type' => 'text'],
                            ['key' => 'telefono', 'label' => 'Número de Teléfono', 'type' => 'text'],
                            ['key' => 'email', 'label' => 'Correo Electrónico', 'type' => 'text'],
                            ['key' => 'direccion_completa', 'label' => 'Dirección', 'type' => 'text'],
                            ['key' => 'estadoCliente_idestadoCliente', 'label' => 'Estado', 'type' => 'status'],
                        ],
                        'records' => $clients,
                    ],
                ];

                $rowArr = (array) $row;
                $rowArr['relation_groups'] = $relationGroups;
                return (object) $rowArr;
            });

            $grupos->setCollection($newCollection);
        }

        return view('cliente.grupocliente', [
            'title' => 'Grupos de Cliente',
            'singularTitle' => 'Grupo',
            'items' => $grupos,
            'columns' => $columns,
            'showGroupClientsColumn' => true,
            'stats' => $stats,
            'filters' => [
                [
                    'name' => 'nombre',
                    'label' => 'Nombre',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por nombre de grupo',
                ],
            ],
            'createRoute' => route('modules.clientes.grupos.create'),
            'editRoute' => 'modules.clientes.grupos.edit',
            'showRoute' => 'modules.clientes.grupos.edit',
            'destroyRoute' => 'modules.clientes.grupos.destroy',
            'lockResource' => 'clientes.grupos',
            'relationPanelView' => 'cliente.relation-grupo',
            'exportRoutes' => [
                'pdf' => route('modules.clientes.grupos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.clientes.grupos.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'idgrupoCliente'
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('grupocliente');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query
                    ->where('idgrupoCliente', 'like', $term)
                    ->orWhere('nombreGrupo', 'like', $term);
            });
        }

        if ($request->filled('nombre')) {
            $nombre = trim((string) $request->input('nombre', ''));
            if ($nombre !== '') {
                $baseQuery->where('nombreGrupo', 'like', '%' . $nombre . '%');
            }
        }

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $baseQuery->whereIn('idgrupoCliente', array_values($selectedIds));
        }

        $rows = $baseQuery->orderBy('idgrupoCliente')->get();

        $ids = $rows->pluck('idgrupoCliente')->filter()->unique()->map(function($id) {
            return (int) $id;
        })->values()->all();

        $clientesAgrupados = [];

        if (!empty($ids)) {
            $clientesRows = DB::table('detallegrupocliente as dgc')
                ->join('cliente as c', 'dgc.cliente_idcliente', '=', 'c.idcliente')
                ->whereIn('dgc.grupoCliente_idgrupoCliente', $ids)
                ->select('dgc.grupoCliente_idgrupoCliente', 'c.idcliente', 'c.nombreComercial', 'c.razonSocial')
                ->get();

            $clientesAgrupados = $clientesRows->groupBy('grupoCliente_idgrupoCliente')->map(function ($group) {
                return $group->map(function ($c) {
                    $nombre = trim((string) ($c->nombreComercial ?: $c->razonSocial));
                    return $nombre !== '' ? $nombre : 'Cliente ID: ' . $c->idcliente;
                })->filter()->implode(', ');
            })->all();
        }

        $exportRows = $rows->map(function ($row) use ($clientesAgrupados) {
            $id = $row->idgrupoCliente;
            $rowArr = (array) $row;
            $rowArr['clientes_texto'] = isset($clientesAgrupados[$id]) && $clientesAgrupados[$id] !== '' 
                ? $clientesAgrupados[$id] 
                : 'Sin clientes asignados';
                
            return (object) $rowArr;
        });

        $columns = [
            ['key' => 'idgrupoCliente', 'label' => 'ID'],
            ['key' => 'nombreGrupo', 'label' => 'Nombre'],
            ['key' => 'clientes_texto', 'label' => 'Clientes en este grupo'],
        ];

        $filename = 'grupos_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($exportRows, $columns, $filename);
        }

        return $this->exportPdfResponse($exportRows, $columns, 'Listado de Grupos de Cliente', $filename);
    }

    public function create(): View
    {
        $fields = [
            [
                'name' => 'nombreGrupo',
                'label' => 'Nombre Grupo',
                'type' => 'text',
                'required' => true,
                'maxlength' => 50,
                'minlength' => 2,
                'pattern' => '[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,}',
                'helpText' => 'Mínimo 2 caracteres.',
            ]
        ];

        return view('cliente.grupocliente-form', [
            'title' => 'Crear Grupo de Cliente',
            'moduleTitle' => 'Grupo de Cliente',
            'mode' => 'create',
            'readOnly' => false,
            'formAction' => route('modules.clientes.grupos.store'),
            'backRoute' => route('modules.clientes.grupos.index'),
            'record' => null,
            'fields' => $fields
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'nombreGrupo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, 'unique:grupocliente,nombreGrupo'],
        ], [
            'nombreGrupo.unique' => 'El nombre del grupo ya existe.',
        ]);

        $newGroupId = DB::table('grupocliente')->insertGetId([
            'nombreGrupo' => $request->nombreGrupo
        ]);

        $this->publishResourceEvent('clientes.grupos', (string) $newGroupId, 'created');

        return redirect(route('modules.clientes.grupos.index'))->with('success', 'Grupo creado exitosamente');
    }

    public function edit($id): View|RedirectResponse
    {
        $grupo = DB::table('grupocliente')->where('idgrupoCliente', $id)->first();

        if (!$grupo) {
            return redirect()
                ->route('modules.clientes.grupos.index')
                ->with('error', 'No se encontró el grupo solicitado.');
        }

        // Obtener los clientes asociados a este grupo
        $clientes = DB::table('detallegrupocliente as dgc')
            ->join('cliente as c', 'dgc.cliente_idcliente', '=', 'c.idcliente')
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
            ->where('dgc.grupoCliente_idgrupoCliente', $id)
            ->select(
                'c.idcliente',
                'c.nombreComercial',
                'c.razonSocial',
                'c.rubro',
                'dc.direccion',
                'u.departamento',
                'u.provincia',
                'u.distrito',
                'c.estadoCliente_idestadoCliente'
            )
            ->get()
            ->map(function ($cliente) {
                $direccion = trim((string) ($cliente->direccion ?? ''));
                $ubigeoText = trim(("{$cliente->departamento} / {$cliente->provincia} / {$cliente->distrito}"), ' /');
                $cliente->direccion_completa = trim($direccion . ($direccion !== '' && $ubigeoText !== '' ? ' - ' . $ubigeoText : $ubigeoText));
                return $cliente;
            });

        $fields = [
            [
                'name' => 'nombreGrupo',
                'label' => 'Nombre Grupo',
                'type' => 'text',
                'required' => true,
                'maxlength' => 50,
                'minlength' => 2,
                'pattern' => '[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,}',
                'helpText' => 'Mínimo 2 caracteres.',
                'value' => $grupo->nombreGrupo
            ]
        ];

        return view('cliente.grupocliente-form', [
            'title' => 'Editar Grupo de Cliente',
            'moduleTitle' => 'Grupo de Cliente',
            'mode' => 'edit',
            'readOnly' => true,
            'formAction' => route('modules.clientes.grupos.update', $grupo->idgrupoCliente),
            'backRoute' => route('modules.clientes.grupos.index'),
            'record' => $grupo,
            'fields' => $fields,
            'clientes' => $clientes
        ] + $this->prepareLockViewData('clientes.grupos', (string) $grupo->idgrupoCliente));
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $exists = DB::table('grupocliente')->where('idgrupoCliente', $id)->exists();

        if ($redirect = $this->assertLockAvailable($request, 'clientes.grupos', (string) $id, 'grupo de cliente', 'modules.clientes.grupos.index')) {
            return $redirect;
        }

        if (!$exists) {
            return redirect()
                ->route('modules.clientes.grupos.index')
                ->with('error', 'No se encontró el grupo solicitado.');
        }

        $request->validate([
            'nombreGrupo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, 'unique:grupocliente,nombreGrupo,' . $id . ',idgrupoCliente'],
        ], [
            'nombreGrupo.unique' => 'El nombre del grupo ya existe.',
        ]);

        DB::table('grupocliente')
            ->where('idgrupoCliente', $id)
            ->update([
                'nombreGrupo' => $request->nombreGrupo
            ]);

        $this->publishResourceEvent('clientes.grupos', (string) $id, 'updated');
        $this->releaseLockIfOwned($request, 'clientes.grupos', (string) $id);

        return redirect(route('modules.clientes.grupos.index'))->with('success', 'Grupo actualizado exitosamente');
    }

    public function destroy(Request $request, $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'clientes.grupos', (string) $id, 'grupo de cliente', 'modules.clientes.grupos.index')) {
            return $redirect;
        }

        try {
            $exists = DB::table('grupocliente')->where('idgrupoCliente', $id)->exists();
            
            if (!$exists) {
                return redirect()
                    ->route('modules.clientes.grupos.index')
                    ->with('error', 'No se encontró el grupo solicitado.');
            }

            DB::table('grupocliente')->where('idgrupoCliente', $id)->delete();
            $this->publishResourceEvent('clientes.grupos', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'clientes.grupos', (string) $id);
            return redirect(route('modules.clientes.grupos.index'))->with('success', 'Grupo eliminado exitosamente');
        } catch (QueryException $e) {
            return redirect(route('modules.clientes.grupos.index'))->with('error', 'No se puede eliminar el grupo porque está siendo utilizado');
        }
    }
}
