<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlmacenPlanesServiciosController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'almacen.planes_servicios';

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request);
        $statsQuery = clone $baseQuery;

        $items = $baseQuery
            ->orderByDesc('a.idalmacen')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->precio_label = $this->formatMoney($row->precio ?? null);
            $row->renovacion_label = $this->formatYesNo($row->renovacion ?? null);
            $row->cantidad_disponible_label = $this->formatDecimal($row->cantidadDisponible ?? null);
            return $row;
        });

        $stats = [
            'total' => (clone $statsQuery)->count('a.idalmacen'),
            'empresaPropietaria_RUC' => (clone $statsQuery)->distinct('a.empresaPropietaria_RUC')->count('a.empresaPropietaria_RUC'),
        ];

        return view('almacen.planes-servicios.index', [
            'title' => 'Planes y servicios',
            'singularTitle' => 'Plan o servicio',
            'items' => $items,
            'createRoute' => route('modules.almacen.planes-servicios.create'),
            'editRoute' => 'modules.almacen.planes-servicios.edit',
            'destroyRoute' => 'modules.almacen.planes-servicios.destroy',
            'bulkDestroyRoute' => route('modules.almacen.planes-servicios.bulk-destroy'),
            'identifierKey' => 'idalmacen',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => true,
            'columns' => [
                ['key' => 'idalmacen', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'empresa_label', 'label' => 'Empresa', 'type' => 'text', 'wrap' => true],
                ['key' => 'tipo_elemento_label', 'label' => 'Tipo elemento', 'type' => 'text', 'wrap' => true],
                ['key' => 'precio_label', 'label' => 'Precio', 'type' => 'text'],
                ['key' => 'renovacion_label', 'label' => 'Renovación', 'type' => 'text', 'wrap' => true],
            ],
            'stats' => [
                ['label' => 'Total registros', 'value' => $stats['total']],
                ['label' => 'Empresas', 'value' => $stats['empresaPropietaria_RUC']],
            ],
            'filters' => [
                [
                    'name' => 'empresaPropietaria_RUC',
                    'label' => 'Empresa',
                    'options' => $this->empresaOptions(),
                    'placeholder' => 'Todas las empresas',
                ],
                [
                    'name' => 'tipoElemento_idtipoElemento',
                    'label' => 'Tipo elemento',
                    'options' => $this->tipoElementoOptions(),
                    'placeholder' => 'Todos los planes/servicios',
                ],
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $rows = $this->baseQuery($request)
            ->orderByDesc('a.idalmacen')
            ->get();

        $columns = [
            ['key' => 'idalmacen', 'label' => 'ID'],
            ['key' => 'empresa_label', 'label' => 'Empresa'],
            ['key' => 'tipo_elemento_label', 'label' => 'Tipo elemento'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'precio', 'label' => 'Precio'],
            ['key' => 'renovacion', 'label' => 'Renovación'],
        ];

        $filename = 'planes_servicios_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Planes y servicios', $filename);
    }

    public function create(): View
    {
        return view('almacen.planes-servicios.form', [
            'title' => 'Nuevo plan o servicio',
            'moduleTitle' => 'Planes y servicios',
            'mode' => 'create',
            'formAction' => route('modules.almacen.planes-servicios.store'),
            'backRoute' => route('modules.almacen.planes-servicios.index'),
            'record' => null,
            'fields' => $this->buildFields(null),
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlanesServicios($request);
        $payload = $this->preparePayload($validated);

        $newId = null;
        DB::transaction(function () use ($payload, &$newId): void {
            $newId = DB::table('almacen')->insertGetId($payload);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $newId, 'created');
        });

        return redirect()
            ->route('modules.almacen.planes-servicios.index')
            ->with('success', 'Registro de plan o servicio creado correctamente.');
    }

    public function edit(int $id): View|RedirectResponse
    {
        $record = DB::table('almacen')->where('idalmacen', $id)->first();

        if (!$record) {
            return redirect()
                ->route('modules.almacen.planes-servicios.index')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        return view('almacen.planes-servicios.form', [
            'title' => 'Editar plan o servicio',
            'moduleTitle' => 'Planes y servicios',
            'mode' => 'edit',
            'formAction' => route('modules.almacen.planes-servicios.update', $id),
            'backRoute' => route('modules.almacen.planes-servicios.index'),
            'record' => $record,
            'fields' => $this->buildFields($id),
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('almacen')->where('idalmacen', $id)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.almacen.planes-servicios.index')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de plan o servicio', 'modules.almacen.planes-servicios.index')) {
            return $redirect;
        }

        $validated = $this->validatePlanesServicios($request);
        $payload = $this->preparePayload($validated);

        DB::transaction(function () use ($payload, $request, $id): void {
            DB::table('almacen')->where('idalmacen', $id)->update($payload);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);
        });

        return redirect()
            ->route('modules.almacen.planes-servicios.index')
            ->with('success', 'Registro de plan o servicio actualizado correctamente.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de plan o servicio', 'modules.almacen.planes-servicios.index')) {
            return $redirect;
        }

        try {
            DB::table('almacen')->where('idalmacen', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

            return redirect()
                ->route('modules.almacen.planes-servicios.index')
                ->with('success', 'Registro eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.almacen.planes-servicios.index')
                ->with('error', 'No se puede eliminar el registro porque tiene relaciones asociadas.');
        }
    }

    private function baseQuery(Request $request)
    {
        $query = DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('modelo as m', 'a.modelo_idmodelo', '=', 'm.idmodelo')
            ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('tecnologia as tg', 'a.tecnologia_idtecnologia', '=', 'tg.idtecnologia')
            ->leftJoin('unidadmedida as um', 'a.unidadMedida_idunidadMedida', '=', 'um.idunidadMedida')
            ->where(function ($builder) {
                $builder
                    ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%plan%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%servicio%'");
            })
            ->select(
                'a.idalmacen',
                'a.empresaPropietaria_RUC',
                'a.modelo_idmodelo',
                'a.tipoElemento_idtipoElemento',
                'a.tecnologia_idtecnologia',
                'a.unidadMedida_idunidadMedida',
                'a.usaRedMovil',
                'a.cantidadDisponible',
                'a.detalle',
                'a.precio',
                'a.renovacion',
                DB::raw("COALESCE(ep.razonSocial, 'Sin razón social') as empresa_label"),
                DB::raw("COALESCE(m.nombreModelo, 'Sin modelo') as modelo_label"),
                DB::raw("COALESCE(ma.nombreMarca, 'Sin marca') as marca_label"),
                DB::raw("COALESCE(te.nombre, 'Sin tipo') as tipo_elemento_label"),
                DB::raw("COALESCE(tg.nombreTecnologia, 'Sin tecnología') as tecnologia_label"),
                DB::raw("COALESCE(um.nomenclatura, 'Sin unidad') as unidad_medida_label")
            );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('a.idalmacen', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('m.nombreModelo', 'like', $term)
                    ->orWhere('ma.nombreMarca', 'like', $term)
                    ->orWhere('te.nombre', 'like', $term)
                    ->orWhere('te.detalle', 'like', $term)
                    ->orWhere('tg.nombreTecnologia', 'like', $term)
                    ->orWhere('um.detalle', 'like', $term)
                    ->orWhere('um.nomenclatura', 'like', $term);
            });
        }

        foreach (['empresaPropietaria_RUC' => 'a.empresaPropietaria_RUC', 'modelo_idmodelo' => 'a.modelo_idmodelo', 'marca_idmarca' => 'm.marca_idmarca', 'tecnologia_idtecnologia' => 'a.tecnologia_idtecnologia', 'unidadMedida_idunidadMedida' => 'a.unidadMedida_idunidadMedida'] as $input => $column) {
            $value = trim((string) $request->input($input, ''));
            if ($value !== '') {
                $query->where($column, (int) $value);
            }
        }

        return $query;
    }

    private function buildFields(?int $almacenId = null): array
    {
        return [
            [
                'name' => 'empresaPropietaria_RUC',
                'type' => 'select',
                'label' => 'Empresa propietaria',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->empresaOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona empresa',
            ],
            [
                'name' => 'tipoElemento_idtipoElemento',
                'type' => 'select',
                'label' => 'Tipo elemento',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->tipoElementoOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona un plan o servicio',
            ],
            [
                'name' => 'precio',
                'type' => 'number',
                'label' => 'Precio',
                'required' => true,
                'step' => '0.01',
                'min' => '0',
            ],
            [
                'name' => 'renovacion',
                'type' => 'select',
                'label' => 'Renovación',
                'required' => true,
                'options' => [
                    ['value' => '0', 'label' => 'No'],
                    ['value' => '1', 'label' => 'Sí'],
                ],
                'placeholder' => 'Selecciona estado',
            ],
            [
                'name' => 'detalle',
                'type' => 'textarea',
                'label' => 'Detalle',
                'required' => true,
                'maxlength' => 200,
                'placeholder' => 'Información adicional sobre el plan o servicio',
            ],
        ];
    }

    private function validatePlanesServicios(Request $request): array
    {
        $allowedTipoElementoIds = $this->tipoElementoOptions()
            ->pluck('value')
            ->map(fn ($value) => (int) $value)
            ->all();

        $validated = $request->validate([
            'empresaPropietaria_RUC' => ['required', 'integer', Rule::exists('empresapropietaria', 'RUC')],
            'tipoElemento_idtipoElemento' => ['required', 'integer', Rule::in($allowedTipoElementoIds)],
            'detalle' => ['nullable', 'string', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'renovacion' => ['nullable', 'integer', Rule::in([0, 1])],
        ]);

        return $validated;
    }

    private function preparePayload(array $validated): array
    {
        return [
            'empresaPropietaria_RUC' => (int) $validated['empresaPropietaria_RUC'],
            'modelo_idmodelo' => null,
            'tipoElemento_idtipoElemento' => (int) $validated['tipoElemento_idtipoElemento'],
            'tecnologia_idtecnologia' => null,
            'unidadMedida_idunidadMedida' => null,
            'usaRedMovil' => null,
            'cantidadDisponible' => null,
            'detalle' => $this->nullableString($validated['detalle'] ?? null),
            'precio' => $this->nullableNumber($validated['precio'] ?? null),
            'renovacion' => array_key_exists('renovacion', $validated) && $validated['renovacion'] !== null
                ? (int) $validated['renovacion']
                : 0,
        ];
    }

    private function empresaOptions(): Collection
    {
        return DB::table('empresapropietaria as ep')
            ->orderBy('ep.razonSocial')
            ->orderBy('ep.RUC')
            ->get()
            ->map(fn ($row): array => [
                'value' => (string) $row->RUC,
                'label' => trim((string) $row->RUC . ' - ' . trim((string) ($row->razonSocial ?? 'Sin razón social'))),
            ]);
    }

    private function tipoElementoOptions(): Collection
    {
        return DB::table('tipoelemento as te')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->where(function ($query) {
                $query
                    ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%plan%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%servicio%'");
            })
            ->orderBy('te.nombre')
            ->orderBy('te.idtipoElemento')
            ->orderBy('te.detalle')
            ->orderBy('p.nombrePlataforma')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombre ?? ''));
                $detalle = trim((string) ($row->detalle ?? ''));
                $plataforma = trim((string) ($row->nombrePlataforma ?? ''));

                $labelParts = array_filter([
                    $nombre,
                    $detalle,
                    $plataforma,
                ], fn ($part) => $part !== '');

                return [
                    'value' => (string) $row->idtipoElemento,
                    'label' => $labelParts !== [] ? implode('-', $labelParts) : (string) $row->idtipoElemento,
                ];
            });
    }

    private function formatYesNo(mixed $value): string
    {
        return match ((int) ($value ?? 0)) {
            1 => 'Sí',
            0 => 'No',
            default => '-',
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }

    private function nullableNumber(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }

    private function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, ',', '.');
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return 'S/ ' . number_format((float) $value, 2, ',', '.');
    }
}
