<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Support\ResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PersonalController extends Controller
{
    use ExportableList;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const CARGO_LOCK_RESOURCE = 'personal.cargo';

    public function index(Request $request): View
    {
        $baseQuery = DB::table('personal as p')
            ->leftJoin('cargopersonal as c', 'p.cargoPersonal_idcargoPersonal', '=', 'c.idcargoPersonal')
            ->select('p.*', 'c.descripcion as cargoDescripcion', DB::raw("CONCAT(p.nombre, ' ', p.apellido) as nombre_completo"));

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query
                    ->where('p.dniPersonal', 'like', $term)
                    ->orWhere('p.nombre', 'like', $term)
                    ->orWhere('p.apellido', 'like', $term)
                    ->orWhere('p.correo', 'like', $term)
                    ->orWhere('c.descripcion', 'like', $term);
            });
        }

        if ($request->has('estado') && $request->input('estado') !== '') {
            $estado = (string) $request->input('estado');
            if (in_array($estado, ['0', '1'], true)) {
                $baseQuery->where('p.estado', $estado);
            }
        }

        if ($request->filled('cargo')) {
            $cargo = trim((string) $request->input('cargo', ''));
            if ($cargo !== '') {
                $baseQuery->where('c.descripcion', 'like', '%' . $cargo . '%');
            }
        }

        if ($request->filled('nombre')) {
            $nombre = trim((string) $request->input('nombre', ''));
            if ($nombre !== '') {
                $baseQuery->where(function ($query) use ($nombre) {
                    $term = '%' . $nombre . '%';
                    $query
                        ->where('p.nombre', 'like', $term)
                        ->orWhere('p.apellido', 'like', $term)
                        ->orWhereRaw("CONCAT(p.nombre, ' ', p.apellido) like ?", [$term]);
                });
            }
        }

        $statsQuery = clone $baseQuery;
        $totalPersonal = (clone $statsQuery)->count();
        $activosPersonal = (clone $statsQuery)->where('p.estado', '1')->count();
        $inactivosPersonal = max($totalPersonal - $activosPersonal, 0);

        $personales = $baseQuery
            ->orderByRaw("CASE WHEN p.estado = '1' THEN 0 ELSE 1 END") 
            ->orderBy('p.apellido')
            ->orderBy('p.nombre')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $cargos = DB::table('cargopersonal')
            ->select('idcargoPersonal', 'descripcion')
            ->orderBy('descripcion')
            ->get();

        return view('personal.personal', [
            'title' => 'Módulo Personal',
            'singularTitle' => 'Personal',
            'items' => $personales,
            'columns' => [
                ['key' => 'nombre_completo', 'label' => 'Nombre', 'type' => 'user_profile'],
                ['key' => 'dniPersonal', 'label' => 'DNI', 'type' => 'text'],
                ['key' => 'cargoDescripcion', 'label' => 'Cargo', 'type' => 'text'],
                ['key' => 'correo', 'label' => 'Correo', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status', 'value' => fn ($row) => $row->estado === '1' ? 'Activo' : 'Inactivo'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.personal.export', ['format' => 'pdf']),
                'xlsx' => route('modules.personal.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de Personal', 'value' => $totalPersonal],
                ['label' => 'Personal Activo', 'value' => $activosPersonal],
                ['label' => 'Personal Inactivo', 'value' => $inactivosPersonal],
            ],
            'filters' => [
                [
                    'name' => 'nombre',
                    'label' => 'Nombre',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por nombre o apellido',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                ],
                [
                    'name' => 'cargo',
                    'label' => 'Cargo',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por cargo',
                ],
            ],
            'createRoute' => route('modules.personal.create'),
            'editRoute' => 'modules.personal.edit',
            'showRoute' => 'modules.personal.edit',
            'destroyRoute' => 'modules.personal.destroy',
            'identifierKey' => 'dniPersonal',
            'lockResource' => 'personal',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('personal as p')
            ->leftJoin('cargopersonal as c', 'p.cargoPersonal_idcargoPersonal', '=', 'c.idcargoPersonal')
            ->select('p.*', 'c.descripcion as cargoDescripcion', DB::raw("CONCAT(p.nombre, ' ', p.apellido) as nombre_completo"));

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query
                    ->where('p.dniPersonal', 'like', $term)
                    ->orWhere('p.nombre', 'like', $term)
                    ->orWhere('p.apellido', 'like', $term)
                    ->orWhere('p.correo', 'like', $term)
                    ->orWhere('c.descripcion', 'like', $term);
            });
        }

        if ($request->has('estado') && $request->input('estado') !== '') {
            $estado = (string) $request->input('estado');
            if (in_array($estado, ['0', '1'], true)) {
                $baseQuery->where('p.estado', $estado);
            }
        }

        if ($request->filled('cargo')) {
            $cargo = trim((string) $request->input('cargo', ''));
            if ($cargo !== '') {
                $baseQuery->where('c.descripcion', 'like', '%' . $cargo . '%');
            }
        }

        if ($request->filled('nombre')) {
            $nombre = trim((string) $request->input('nombre', ''));
            if ($nombre !== '') {
                $baseQuery->where(function ($query) use ($nombre) {
                    $term = '%' . $nombre . '%';
                    $query
                        ->where('p.nombre', 'like', $term)
                        ->orWhere('p.apellido', 'like', $term)
                        ->orWhereRaw("CONCAT(p.nombre, ' ', p.apellido) like ?", [$term]);
                });
            }
        }

        
        $columns = [
            ['key' => 'nombre_completo', 'label' => 'Nombre'],
            ['key' => 'dniPersonal', 'label' => 'DNI'],
            ['key' => 'cargoDescripcion', 'label' => 'Cargo'],
            ['key' => 'correo', 'label' => 'Correo'],
            ['key' => 'estado', 'label' => 'Estado', 'value' => fn ($row) => $row->estado === '1' ? 'Activo' : 'Inactivo'],
        ];

        $filename = 'personal_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('p.dniPersonal', array_values($selectedIds))->orderBy('p.apellido')->orderBy('p.nombre')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Personal', $filename);
        }

        $rows = $baseQuery->orderBy('p.apellido')->orderBy('p.nombre')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Personal', $filename);
    }

    public function create(): View
    {
        $cargos = DB::table('cargopersonal')
            ->orderBy('descripcion')
            ->orderBy('idcargoPersonal')
            ->get();

        return view('personal.personal-form', [
            'title' => 'Nuevo Personal',
            'moduleTitle' => 'Módulo Personal',
            'mode' => 'create',
            'formAction' => route('modules.personal.store'),
            'backRoute' => route('modules.personal'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'dniPersonal',
                    'type' => 'text',
                    'label' => 'DNI',
                    'required' => true,
                    'maxlength' => 8,
                    'minlength' => 8,
                    'pattern' => '[0-9]{8}',
                    'inputmode' => 'numeric',
                    'helpText' => 'Solo números, exactamente 8 dígitos.',
                    'placeholder' => 'Ej: 12345678',
                ],
                [
                    'name' => 'cargoPersonal_idcargoPersonal',
                    'type' => 'select',
                    'label' => 'Cargo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $cargos,
                    'optionKey' => 'idcargoPersonal',
                    'optionLabel' => 'descripcion',
                    'placeholder' => 'Selecciona un cargo',
                ],
                [
                    'name' => 'apellido',
                    'type' => 'text',
                    'label' => 'Apellidos',
                    'required' => false,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'pattern' => '[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{2,}',
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombres',
                    'required' => false,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'pattern' => '[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{2,}',
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'correo',
                    'type' => 'email',
                    'label' => 'Correo',
                    'required' => false,
                    'maxlength' => 100,
                    'pattern' => '[^@\s]+@[^@\s]+\.com',
                    'inputmode' => 'email',
                    'helpText' => 'Correo válido. Debe incluir @ y terminar en .com.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => false,
                    'options' => ['1' => 'Activo', '0' => 'Inactivo'],
                ],
                [
                    'name' => 'foto',
                    'type' => 'file',
                    'label' => 'Foto',
                    'required' => false,
                ],
                [
                    'name' => 'firma',
                    'type' => 'file',
                    'label' => 'Firma',
                    'required' => false,
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dniPersonal' => ['required', 'digits:8', 'unique:personal,dniPersonal'],
            'apellido' => ['nullable', 'string', 'max:50', 'regex:/^$|^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{2,}$/u', 'unique:personal,apellido'],
            'nombre' => ['nullable', 'string', 'max:50', 'regex:/^$|^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{2,}$/u', 'unique:personal,nombre'],
            'cargoPersonal_idcargoPersonal' => ['required', 'integer', 'exists:cargopersonal,idcargoPersonal'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'firma' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'correo' => ['nullable', 'email', 'max:100', 'regex:/^[^@\s]+@[^@\s]+\.com$/i'],
            'estado' => ['nullable', Rule::in(['0', '1'])],
        ], [
            'dniPersonal.unique' => 'El DNI ya está registrado en personal.',
            'dniPersonal.digits' => 'El DNI debe tener exactamente 8 dígitos.',
            'nombre.unique' => 'El nombre ya está registrado en personal.',
            'apellido.unique' => 'El apellido ya está registrado en personal.',
            'foto' => 'La foto no debe ser mayor a 2MB.',
            'firma' => 'La firma no debe ser mayor a 2MB.',
        ]);

        // Manejo de archivos: foto y firma
        if ($request->hasFile('foto')) {
            $file = $request->file('foto');
            $filename = 'foto_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('personal', $filename, 'public');
            $validated['foto'] = $path;
        }

        if ($request->hasFile('firma')) {
            $file = $request->file('firma');
            $filename = 'firma_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('personal', $filename, 'public');
            $validated['firma'] = $path;
        }

        DB::table('personal')->insert($validated);
        $this->publishResourceEvent('personal', $validated['dniPersonal'], 'created');

        return redirect()
            ->route('modules.personal')
            ->with('success', 'Personal creado correctamente.');
    }

    public function edit(string $dni): View|RedirectResponse
    {
        $personal = DB::table('personal')->where('dniPersonal', $dni)->first();

        if (!$personal) {
            return redirect()
                ->route('modules.personal')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        $cargos = DB::table('cargopersonal')
            ->orderBy('descripcion')
            ->orderBy('idcargoPersonal')
            ->get();

        return view('personal.personal-form', [
            'title' => 'Editar Personal',
            'moduleTitle' => 'Módulo Personal',
            'mode' => 'edit',
            'formAction' => route('modules.personal.update', $dni),
            'backRoute' => route('modules.personal'),
            'record' => $personal,
            'fields' => [
                [
                    'name' => 'dniPersonal',
                    'type' => 'text',
                    'label' => 'DNI',
                    'required' => true,
                    'maxlength' => 8,
                    'minlength' => 8,
                    'pattern' => '[0-9]{8}',
                    'inputmode' => 'numeric',
                    'helpText' => 'Solo números, exactamente 8 dígitos.',
                ],
                [
                    'name' => 'cargoPersonal_idcargoPersonal',
                    'type' => 'select',
                    'label' => 'Cargo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $cargos,
                    'optionKey' => 'idcargoPersonal',
                    'optionLabel' => 'descripcion',
                    'placeholder' => 'Selecciona un cargo',
                ],
                [
                    'name' => 'apellido',
                    'type' => 'text',
                    'label' => 'Apellidos',
                    'required' => false,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'pattern' => '[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{2,}',
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombres',
                    'required' => false,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'pattern' => '[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{2,}',
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'correo',
                    'type' => 'email',
                    'label' => 'Correo',
                    'required' => false,
                    'maxlength' => 100,
                    'pattern' => '[^@\s]+@[^@\s]+\.com',
                    'inputmode' => 'email',
                    'helpText' => 'Correo válido. Debe incluir @ y terminar en .com.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => false,
                    'options' => ['1' => 'Activo', '0' => 'Inactivo'],
                ],
                [
                    'name' => 'foto',
                    'type' => 'file',
                    'label' => 'Foto',
                    'required' => false,
                ],
                [
                    'name' => 'firma',
                    'type' => 'file',
                    'label' => 'Firma',
                    'required' => false,
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('personal', $dni));
    }

    public function update(Request $request, string $dni): RedirectResponse
    {
        $exists = DB::table('personal')->where('dniPersonal', $dni)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.personal')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'personal', $dni, 'personal', 'modules.personal')) {
            return $redirect;
        }

        $validated = $request->validate([
            'dniPersonal' => ['required', 'digits:8', Rule::unique('personal', 'dniPersonal')->ignore($dni, 'dniPersonal')],
            'apellido' => ['nullable', 'string', 'max:50', 'regex:/^$|^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{5,}$/u', Rule::unique('personal', 'apellido')->ignore($dni, 'dniPersonal')],
            'nombre' => ['nullable', 'string', 'max:50', 'regex:/^$|^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]{5,}$/u', Rule::unique('personal', 'nombre')->ignore($dni, 'dniPersonal')],
            'cargoPersonal_idcargoPersonal' => ['required', 'integer', 'exists:cargopersonal,idcargoPersonal'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'firma' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'correo' => ['nullable', 'email', 'max:100', 'regex:/^[^@\s]+@[^@\s]+\.com$/i'],
            'estado' => ['nullable', Rule::in(['0', '1'])],
        ], [
            'dniPersonal.unique' => 'El DNI ya está registrado en personal.',
            'dniPersonal.digits' => 'El DNI debe tener exactamente 8 dígitos.',
            'nombre.unique' => 'El nombre ya está registrado en personal.',
            'apellido.unique' => 'El apellido ya está registrado en personal.',
            'foto' => 'La foto no debe ser mayor a 2MB.',
            'firma' => 'La firma no debe ser mayor a 2MB.',
        ]);

        // Obtener paths anteriores para borrado si se reemplazan
        $previous = DB::table('personal')->where('dniPersonal', $dni)->first();

        if ($request->hasFile('foto')) {
            if (!empty($previous->foto)) {
                Storage::disk('public')->delete($previous->foto);
            }
            $file = $request->file('foto');
            $filename = 'foto_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('personal', $filename, 'public');
            $validated['foto'] = $path;
        }

        if ($request->hasFile('firma')) {
            if (!empty($previous->firma)) {
                Storage::disk('public')->delete($previous->firma);
            }
            $file = $request->file('firma');
            $filename = 'firma_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('personal', $filename, 'public');
            $validated['firma'] = $path;
        }

        DB::table('personal')->where('dniPersonal', $dni)->update($validated);
        $this->publishResourceEvent('personal', $validated['dniPersonal'], 'updated');

        $this->releaseLockIfOwned($request, 'personal', $dni);

        if ($dni !== $validated['dniPersonal']) {
            DB::table('usuario')
                ->where('personal_dniPersonal', $dni)
                ->update(['personal_dniPersonal' => $validated['dniPersonal']]);
        }

        return redirect()
            ->route('modules.personal')
            ->with('success', 'Personal actualizado correctamente.');
    }
    
    private function assertCargoLockAvailableJson(Request $request, int $cargo): ?JsonResponse
    {
        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $lockInfo = ResourceLock::status(self::CARGO_LOCK_RESOURCE, (string) $cargo);

        if ($lockInfo && ($lockInfo['usuario'] ?? '') !== $currentUser) {
            $owner = $lockInfo['usuario'] ?? 'otro usuario';

            return response()->json([
                'ok' => false,
                'message' => "El cargo está siendo editado por {$owner} y no puede modificarse hasta que se libere.",
            ], 409);
        }

        return null;
    }

    public function destroy(Request $request, string $dni): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'personal', $dni, 'personal', 'modules.personal')) {
            return $redirect;
        }

        try {
            DB::table('personal')->where('dniPersonal', $dni)->delete();
            $this->publishResourceEvent('personal', $dni, 'deleted');

            $this->releaseLockIfOwned($request, 'personal', $dni);

            return redirect()
                ->route('modules.personal')
                ->with('success', 'Personal eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.personal')
                ->with('error', 'No se puede eliminar este personal porque tiene registros relacionados.');
        }
    }

}
