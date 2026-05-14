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

        $rows = $baseQuery
            ->orderBy('idgrupoCliente')
            ->get();

        $columns = [
            ['key' => 'idgrupoCliente', 'label' => 'ID'],
            ['key' => 'nombreGrupo', 'label' => 'Nombre'],
        ];

        $filename = 'grupos_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Grupos de Cliente', $filename);
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
                'u.distrito'
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
