<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LineasChipsController extends Controller
{
    use ExportableList;

    private const NUMERO_DISPOSITIVO_LOCK_RESOURCE = 'lineas_chips.numero_dispositivo';

    public function index(): RedirectResponse
    {
        return redirect()->route('modules.lineas-chips.numeros-telefonico.index');
    }

    public function numerosTelefonicoIndex(Request $request): View
    {
        $baseQuery = DB::table('numerotelefonico as n')
            ->select('n.numeroTelefonico', 'n.plan', 'n.estado')
            ->addSelect([
                'precio_numero' => DB::table('detalle_precionumero as dp')
                    ->select('dp.precio')
                    ->whereColumn('dp.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->orderByDesc('dp.iddetalle_precionumero')
                    ->limit(1),
                'moneda_numero' => DB::table('detalle_precionumero as dp')
                    ->select('dp.moneda')
                    ->whereColumn('dp.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->orderByDesc('dp.iddetalle_precionumero')
                    ->limit(1),
                'simcard_actual' => DB::table('detallesimcard as d')
                    ->select('d.simCard_idsimCard')
                    ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('d.estado', '0')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
                'simcard_pasada' => DB::table('detallesimcard as d')
                    ->select('d.simCard_idsimCard')
                    ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('d.estado', '1')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
                'operador_actual' => DB::table('detallesimcard as d')
                    ->leftJoin('simcard as s', 's.idsimCard', '=', 'd.simCard_idsimCard')
                    ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador')
                    ->select('o.nombre')
                    ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('d.estado', '0')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
            ]);

        $estadoFilter = trim((string) $request->input('estado', ''));
        if ($estadoFilter !== '' && in_array($estadoFilter, ['0', '1', '2', '3'], true)) {
            $baseQuery->where('n.estado', $estadoFilter);
        }

        $numeroFilter = trim((string) $request->input('numero', ''));
        if ($numeroFilter !== '') {
            $baseQuery->where('n.numeroTelefonico', $numeroFilter);
        }

        $simCardFilter = trim((string) $request->input('simcard', ''));
        if ($simCardFilter !== '') {
            $baseQuery->whereExists(function ($query) use ($simCardFilter) {
                $query->select(DB::raw('1'))
                    ->from('detallesimcard as d')
                    ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('d.simCard_idsimCard', 'like', '%' . $simCardFilter . '%');
            });
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('n.numeroTelefonico', 'like', $term)
                    ->orWhere('n.estado', 'like', $term)
                    ->orWhereExists(function ($query) use ($term) {
                        $query->select(DB::raw('1'))
                            ->from('detallesimcard as d')
                            ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                            ->where('d.simCard_idsimCard', 'like', $term);
                    })
                    ->orWhereExists(function ($query) use ($term) {
                        $query->select(DB::raw('1'))
                            ->from('detallesimcard as d')
                            ->leftJoin('simcard as s', 's.idsimCard', '=', 'd.simCard_idsimCard')
                            ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador')
                            ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                            ->where('o.nombre', 'like', $term);
                    });
            });
        }

        $items = $baseQuery
            ->orderByRaw("CASE WHEN n.estado != '0' THEN 0 ELSE 1 END")
            ->orderBy('n.numeroTelefonico')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->getCollection()->transform(function ($item) {
            $simcardActual = trim((string) ($item->simcard_actual ?? ''));
            $simcardPasada = trim((string) ($item->simcard_pasada ?? ''));
            $precio = trim((string) ($item->precio_numero ?? ''));
            $moneda = trim((string) ($item->moneda_numero ?? ''));
            $item->precio_numero = $precio !== ''
                ? trim($moneda . ' ' . number_format((float) $precio, 2, '.', ''))
                : '-';
            $item->relacion_simcard = $simcardActual !== ''
                ? 'Asignación Actual: ' . $simcardActual
                : ($simcardPasada !== '' ? 'Ultima Asignación: ' . $simcardPasada : 'Sin asignación');
            $item->operador_actual = trim((string) ($item->operador_actual ?? '')) !== '' ? $item->operador_actual : '-';
            $item->estado = self::normalizeEstadoLabel($item->estado);
            return $item;
        });

        return view('lineaschip.numerotelefonico', [
            'title' => 'Número telefónico',
            'singularTitle' => 'Número telefónico',
            'items' => $items,
            'columns' => [
                ['key' => 'numeroTelefonico', 'label' => 'Número', 'type' => 'text'],
                ['key' => 'precio_numero', 'label' => 'Precio', 'type' => 'text'],
                ['key' => 'plan', 'label' => 'Plan', 'type' => 'text'],
                ['key' => 'relacion_simcard', 'label' => 'Relación SimCard', 'type' => 'text'],
                ['key' => 'operador_actual', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.lineas-chips.numeros-telefonico.export', ['format' => 'pdf']),
                'xlsx' => route('modules.lineas-chips.numeros-telefonico.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de números', 'value' => (clone $baseQuery)->count()],
                ['label' => 'En Uso', 'value' => (clone $baseQuery)->where('n.estado', '3')->count()],
                ['label' => 'Disponibles', 'value' => (clone $baseQuery)->where('n.estado', '2')->count()],
            ],
            'filters' => [
                [
                    'name' => 'numero',
                    'label' => 'Numero',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por Numero',
                ],
                [
                    'name' => 'simcard',
                    'label' => 'SimCard',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por SimCard',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => [
                        ['value' => '1', 'label' => 'Libre'],
                        ['value' => '2', 'label' => 'Disponible'],
                        ['value' => '3', 'label' => 'En Uso'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Todos',
                ],
            ],
            'createRoute' => route('modules.lineas-chips.numeros-telefonico.create'),
            'editRoute' => 'modules.lineas-chips.numeros-telefonico.edit',
            'showRoute' => 'modules.lineas-chips.numeros-telefonico.edit',
            'destroyRoute' => 'modules.lineas-chips.numeros-telefonico.destroy',
            'bulkDestroyRoute' => route('modules.lineas-chips.numeros-telefonico.bulk-destroy'),
            'importPreviewRoute' => route('modules.lineas-chips.numeros-telefonico.import.preview'),
            'importProcessRoute' => route('modules.lineas-chips.numeros-telefonico.import.process'),
            'importButtonLabel' => 'Cargar números',
            'importModalTitle' => 'Cargar números por archivo',
            'importModalHint' => 'Archivo (.xlsx) con las columnas: Numero, Plan, Precio',
            'importPreview' => null,
            'identifierKey' => 'numeroTelefonico',
            'lockResource' => 'lineas_chips.numero_telefonico',
            'tableWrapperClass' => 'numeros-telefonico-table',
        ]);
    }

    public function numerosTelefonicoCreate(): View
    {
        return view('lineaschip.numerotelefonico-form', [
            'title' => 'Nuevo Número telefónico',
            'moduleTitle' => 'Número telefónico',
            'mode' => 'create',
            'formAction' => route('modules.lineas-chips.numeros-telefonico.store'),
            'backRoute' => route('modules.lineas-chips.numeros-telefonico.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'numeroTelefonico',
                    'type' => 'text',
                    'label' => 'Número telefónico',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 9,
                    'helpText' => 'Ingrese mínimo 9 digitos, sin guiones ni espacios.',
                ],
                [
                    'name' => 'plan',
                    'type' => 'text',
                    'label' => 'Plan',
                    'required' => true,
                    'maxlength' => 45,
                    'helpText' => 'Ingrese el nombre o referencia del plan.',
                ],
                [
                    'name' => 'precio',
                    'type' => 'number',
                    'label' => 'Precio',
                    'required' => true,
                    'min' => 0,
                    'step' => '0.01',
                    'placeholder' => 'Ej: 29.90',
                    'helpText' => 'Ingrese solo el valor numérico.',
                ],
                [
                    'name' => 'moneda',
                    'type' => 'select',
                    'label' => 'Moneda',
                    'required' => true,
                    'value' => old('moneda', 'S/'),
                    'options' => [
                        'S/' => 'S/',
                        '$' => '$',
                    ],
                    'helpText' => 'Seleccione el símbolo de moneda.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => old('estado', '1'),
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                    'helpText' => 'Seleccione el estado del número telefónico.',
                ],
                [
                    'name' => 'desea_relacionar_simcard',
                    'type' => 'checkbox',
                    'label' => '¿Desea crear una SimCard junto con el Número?',
                    'value' => old('desea_relacionar_simcard', false),
                    'helpText' => 'Actívalo para mostrar los campos de la SimCard y crear la relación automáticamente.',
                ],
                [
                    'name' => 'idsimCard',
                    'type' => 'text',
                    'label' => 'ID SimCard',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 10,
                    'quickCreateSimcard' => true,
                    'helpText' => 'Ingrese mínimo 10 digitos, sin guiones ni espacios.',
                ],
                [
                    'name' => 'operador_idoperador_simcard',
                    'type' => 'select',
                    'label' => 'Operador',
                    'required' => true,
                    'quickCreateSimcard' => true,
                    'options' => $this->operadorOptions(),
                    'helpText' => 'Se muestra solo si desea crear la SimCard junto al número.',
                ],
                [
                    'name' => 'estado_simcard',
                    'type' => 'select',
                    'label' => 'Estado de la SimCard',
                    'required' => true,
                    'value' => old('estado_simcard', '1'),
                    'quickCreateSimcard' => true,
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                    'helpText' => 'Debe estar activo para crear la relación.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function numerosTelefonicoStore(Request $request): RedirectResponse
    {
        $wantsSimCard = $request->boolean('desea_relacionar_simcard');

        $validated = $request->validate([
            'numeroTelefonico' => ['required', 'string', 'min:9', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX, 'unique:numerotelefonico,numeroTelefonico'],
            'plan' => ['required', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'precio' => ['required', 'numeric', 'min:0'],
            'moneda' => ['required', Rule::in(['S/', '$'])],
            'estado' => ['required', 'string', $wantsSimCard ? Rule::in(['1']) : Rule::in(['0', '1'])],
            'idsimCard' => [
                Rule::requiredIf($wantsSimCard),
                'nullable',
                'string',
                'min:10',
                'max:50',
                'regex:' . self::SAFE_TEXT_REGEX,
                Rule::unique('simcard', 'idsimCard'),
            ],
            'operador_idoperador_simcard' => [Rule::requiredIf($wantsSimCard), 'nullable', 'integer', 'exists:operador,idoperador'],
            'estado_simcard' => [Rule::requiredIf($wantsSimCard), 'nullable', 'string', $wantsSimCard ? Rule::in(['1']) : Rule::in(['0', '1'])],
        ], [
            'numeroTelefonico.unique' => 'Este número ya está registrado.',
            'idsimCard.unique' => 'Este ID de SimCard ya está registrado.',
            'estado.in' => 'El número telefónico debe estar en estado activo para poder crear la SimCard.',
            'estado_simcard.in' => 'La SimCard debe estar en estado activo para poder crearla junto al número.',
        ]);

        $precio = number_format((float) $validated['precio'], 2, '.', '');
        $moneda = $validated['moneda'];

        if ($wantsSimCard) {
            DB::transaction(function () use ($validated, $precio, $moneda): void {
                DB::table('numerotelefonico')->insert([
                    'numeroTelefonico' => $validated['numeroTelefonico'],
                    'plan' => $validated['plan'],
                    'estado' => '2',
                ]);

                DB::table('detalle_precionumero')->insert([
                    'numeroTelefonico_numeroTelefonico' => $validated['numeroTelefonico'],
                    'precio' => $precio,
                    'moneda' => $moneda,
                    'fecha' => Carbon::now()->format('Y-m-d H:i:s'),
                ]);

                DB::table('simcard')->insert([
                    'idsimCard' => $validated['idsimCard'],
                    'operador_idoperador' => (int) $validated['operador_idoperador_simcard'],
                    'estado' => '2',
                ]);

                DB::table('detallesimcard')->insert([
                    'simCard_idsimCard' => $validated['idsimCard'],
                    'numeroTelefonico_numeroTelefonico' => $validated['numeroTelefonico'],
                    'fechaAsignacion' => Carbon::now()->format('Y-m-d H:i:s'),
                    'estado' => '0',
                ]);
            });

            $this->publishResourceEvent('lineas_chips.numero_telefonico', $validated['numeroTelefonico'] ?? '', 'created');
            $this->publishResourceEvent('lineas_chips.simcard', $validated['idsimCard'] ?? '', 'created');

            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('success', 'Número telefónico y SimCard creados y relacionados correctamente.');
        }

        DB::table('numerotelefonico')->insert([
            'numeroTelefonico' => $validated['numeroTelefonico'],
            'plan' => $validated['plan'],
            'estado' => $validated['estado'],
        ]);
        DB::table('detalle_precionumero')->insert([
            'numeroTelefonico_numeroTelefonico' => $validated['numeroTelefonico'],
            'precio' => $precio,
            'moneda' => $moneda,
            'fecha' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
        $this->publishResourceEvent('lineas_chips.numero_telefonico', $validated['numeroTelefonico'] ?? '', 'created');

        return redirect()
            ->route('modules.lineas-chips.numeros-telefonico.index')
            ->with('success', 'Número telefónico creado correctamente.');
    }

    public function numerosTelefonicoEdit(string $id): View|RedirectResponse
    {
        $record = DB::table('numerotelefonico')->where('numeroTelefonico', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se encontro el número telefónico solicitado.');
        }

        $priceDetail = DB::table('detalle_precionumero')
            ->where('numeroTelefonico_numeroTelefonico', $id)
            ->orderByDesc('iddetalle_precionumero')
            ->first();
        $record->precio = $priceDetail?->precio;
        $record->moneda = $priceDetail?->moneda ?? 'S/';

        $historialPrevio = $this->countNumeroHistorialSinRelacionActual($id);
        if ($historialPrevio > 0) {
            $relacion = $this->buildNumeroRelacionActualTexto($id);
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede editar este número telefónico porque tiene asignaciones. ' . $relacion);
        }

        $record->estado = self::normalizeEstado($record->estado);

        $relacionTexto = $this->buildNumeroRelacionTexto($id);

        return view('lineaschip.numerotelefonico-form', [
            'title' => 'Editar Número telefónico',
            'moduleTitle' => 'Número telefónico',
            'mode' => 'edit',
            'formAction' => route('modules.lineas-chips.numeros-telefonico.update', $id),
            'backRoute' => route('modules.lineas-chips.numeros-telefonico.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'numeroTelefonico',
                    'type' => 'text',
                    'label' => 'Número telefónico',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 9,
                    'readonly' => false,
                    'helpText' => 'Ingrese mínimo 9 digitos, sin guiones ni espacios.',
                ],
                [
                    'name' => 'plan',
                    'type' => 'text',
                    'label' => 'Plan',
                    'required' => true,
                    'maxlength' => 45,
                    'helpText' => 'Ingrese el nombre o referencia del plan.',
                ],
                [
                    'name' => 'precio',
                    'type' => 'number',
                    'label' => 'Precio',
                    'required' => true,
                    'min' => 0,
                    'step' => '0.01',
                    'placeholder' => 'Ej: 29.90',
                    'helpText' => 'Ingrese solo el valor numérico.',
                ],
                [
                    'name' => 'moneda',
                    'type' => 'select',
                    'label' => 'Moneda',
                    'required' => true,
                    'options' => [
                        'S/' => 'S/',
                        '$' => '$',
                    ],
                    'helpText' => 'Seleccione el símbolo de moneda.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                    'helpText' => 'Seleccione el estado del número telefónico.',
                ],
                [
                    'name' => 'relacion_simcard_texto',
                    'type' => 'text',
                    'label' => 'Asignación Actual o Última Asignación',
                    'value' => $relacionTexto,
                    'readonly' => true,
                    'disabled' => true,
                    'colSpan' => 2,
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('lineas_chips.numero_telefonico', $id));
    }

    public function numerosTelefonicoUpdate(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('numerotelefonico')->where('numeroTelefonico', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se encontro el número telefónico solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'lineas_chips.numero_telefonico', $id, 'número telefónico', 'modules.lineas-chips.numeros-telefonico.index')) {
            return $redirect;
        }

        $historialPrevio = $this->countNumeroHistorialSinRelacionActual($id);
        if ($historialPrevio > 0) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede editar este número telefónico porque tiene asignaciones. ' . $this->buildNumeroRelacionActualTexto($id));
        }

        $validated = $request->validate([
            'numeroTelefonico' => [
                'required',
                'string',
                'min:9',
                'max:30',
                'regex:' . self::SAFE_TEXT_REGEX,
                Rule::unique('numerotelefonico', 'numeroTelefonico')->ignore($id, 'numeroTelefonico'),
            ],
            'plan' => ['required', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'precio' => ['required', 'numeric', 'min:0'],
            'moneda' => ['required', Rule::in(['S/', '$'])],
            'estado' => ['required', 'string', 'in:0,1,2,3'],
        ]);

        $newNumero = $validated['numeroTelefonico'];
        $precio = number_format((float) $validated['precio'], 2, '.', '');
        $moneda = $validated['moneda'];

        DB::transaction(function () use ($id, $newNumero, $validated, $precio, $moneda): void {
            if ($newNumero !== $id) {
                DB::table('numerotelefonico')->insert([
                    'numeroTelefonico' => $newNumero,
                    'plan' => $validated['plan'],
                    'estado' => $validated['estado'],
                ]);

                DB::table('detalle_precionumero')
                    ->where('numeroTelefonico_numeroTelefonico', $id)
                    ->update(['numeroTelefonico_numeroTelefonico' => $newNumero]);

                DB::table('detallesimcard')
                    ->where('numeroTelefonico_numeroTelefonico', $id)
                    ->update(['numeroTelefonico_numeroTelefonico' => $newNumero]);

                DB::table('detnumerosdispositivo')
                    ->where('numeroTelefonico_numeroTelefonico', $id)
                    ->update(['numeroTelefonico_numeroTelefonico' => $newNumero]);

                $this->upsertNumeroPrecio($newNumero, $precio, $moneda);

                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', $id)
                    ->delete();

                if ($validated['estado'] === '0') {
                    $pairedSims = DB::table('detallesimcard')
                        ->where('numeroTelefonico_numeroTelefonico', $newNumero)
                        ->where('estado', '0')
                        ->pluck('simCard_idsimCard');

                    DB::table('detallesimcard')
                        ->where('numeroTelefonico_numeroTelefonico', $newNumero)
                        ->where('estado', '0')
                        ->update(['estado' => '1']);

                    if ($pairedSims->isNotEmpty()) {
                        DB::table('simcard')
                            ->whereIn('idsimCard', $pairedSims)
                            ->where('estado', '!=', '0')
                            ->update(['estado' => '1']);
                    }
                }

                return;
            }

            DB::table('numerotelefonico')
                ->where('numeroTelefonico', $id)
                ->update([
                    'plan' => $validated['plan'],
                    'estado' => $validated['estado'],
                ]);

            $this->upsertNumeroPrecio($id, $precio, $moneda);

            if ($validated['estado'] === '0') {
                $pairedSims = DB::table('detallesimcard')
                    ->where('numeroTelefonico_numeroTelefonico', $id)
                    ->where('estado', '0')
                    ->pluck('simCard_idsimCard');

                DB::table('detallesimcard')
                    ->where('numeroTelefonico_numeroTelefonico', $id)
                    ->where('estado', '0')
                    ->update(['estado' => '1']);

                if ($pairedSims->isNotEmpty()) {
                    DB::table('simcard')
                        ->whereIn('idsimCard', $pairedSims)
                        ->where('estado', '!=', '0')
                        ->update(['estado' => '1']);
                }
            }
        });

        $this->publishResourceEvent('lineas_chips.numero_telefonico', $newNumero, 'updated');

        $this->releaseLockIfOwned($request, 'lineas_chips.numero_telefonico', $id);

        return redirect()
            ->route('modules.lineas-chips.numeros-telefonico.index')
            ->with('success', 'Número telefónico actualizado correctamente.');
    }

    public function detallesimcardPreviewExport(Request $request, string $type)
    {
        $type = mb_strtolower(trim($type));
        if (!in_array($type, ['bulk', 'import'], true)) {
            abort(404);
        }

        $selectedIds = (array) $request->input('selectedIds', []);

        $payload = trim((string) $request->input('previewPayload', ''));
        if ($payload === '') {
            return response()->json(['success' => false, 'message' => 'No hay datos.'], 422);
        }

        $preview = json_decode(rawurldecode($payload), true);
        if (!is_array($preview)) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos.'], 422);
        }

        $rows = collect($preview['allRows'] ?? $preview['previewRows'] ?? []);

        if (!empty($selectedIds)) {
            $rows = $rows->whereIn('id', $selectedIds);
        }

        if ($type === 'bulk') {
            $columns = [
                ['key' => 'line', 'label' => 'Línea'],
                ['key' => 'numero', 'label' => 'Número'],
                ['key' => 'status', 'label' => 'Estado'],
            ];
            $filename = 'detallesimcard_baja_preview_' . now()->format('Ymd_His') . '.xlsx';

            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        // Para importaciones: columnas según el importType del payload
        $importType = trim((string) ($preview['importType'] ?? 'detallesimcard'));

        if ($importType === 'numero') {
            $columns = [
                ['key' => 'line', 'label' => 'Línea'],
                ['key' => 'numero', 'label' => 'Número'],
                ['key' => 'plan', 'label' => 'Plan'],
                [
                    'key' => 'precio_display',
                    'label' => 'Precio',
                    'value' => function ($row) {
                        $moneda = trim((string) ($row['moneda'] ?? 'S/'));
                        $precio = trim((string) ($row['precio'] ?? ''));

                        if ($precio === '') {
                            return $moneda === '' ? '' : $moneda;
                        }

                        $precioDisplay = $precio;
                        if (!preg_match('/^\s*[-+]?\d+(?:[.,]\d+)?\s*$/', $precioDisplay)) {
                            $precioDisplay = preg_replace('/[^0-9,.-]/', '', $precioDisplay) ?? $precioDisplay;
                        }

                        if ($precioDisplay === '') {
                            return $moneda;
                        }

                        return trim($moneda . ' ' . $precioDisplay);
                    },
                ],
                ['key' => 'status', 'label' => 'Estado'],
            ];
            $filename = 'numeros_carga_preview_' . now()->format('Ymd_His') . '.xlsx';
        } elseif ($importType === 'simcard') {
            $columns = [
                ['key' => 'line', 'label' => 'Línea'],
                ['key' => 'simcard', 'label' => 'SimCard'],
                ['key' => 'operador', 'label' => 'Operador'],
                ['key' => 'status', 'label' => 'Estado'],
            ];
            $filename = 'simcard_carga_preview_' . now()->format('Ymd_His') . '.xlsx';
        } else {
            $columns = [
                ['key' => 'line', 'label' => 'Línea'],
                ['key' => 'numero', 'label' => 'Número'],
                ['key' => 'simcard', 'label' => 'SimCard'],
                ['key' => 'operador', 'label' => 'Operador'],
                ['key' => 'status', 'label' => 'Estado'],
            ];
            $filename = 'detallesimcard_carga_preview_' . now()->format('Ymd_His') . '.xlsx';
        }

        return $this->exportXlsxResponse($rows, $columns, $filename);
    }

    public function numerosTelefonicoDestroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'lineas_chips.numero_telefonico', $id, 'número telefónico', 'modules.lineas-chips.numeros-telefonico.index')) {
            return $redirect;
        }

        $historialPrevio = $this->countNumeroHistorialSinRelacionActual($id);
        if ($historialPrevio > 0) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede eliminar este número telefónico porque tiene historial. ' . $this->buildNumeroRelacionActualTexto($id));
        }

        $deviceRelationExists = DB::table('detnumerosdispositivo')
            ->where('numeroTelefonico_numeroTelefonico', $id)
            ->exists();

        $deleteMode = trim((string) $request->input('deleteMode', ''));

        if ($deleteMode === 'delete_with_simcard' && !$this->canDeleteNumeroWithSimcard($id)) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede eliminar con la SimCard porque ya existe historial o relación con dispositivo.');
        }

        if ($deviceRelationExists) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede eliminar este número telefónico porque está asignado a un dispositivo. Primero debe darse de baja.');
        }

        try {
            DB::transaction(function () use ($id, $deleteMode): void {
                $detalleActual = DB::table('detallesimcard')
                    ->where('numeroTelefonico_numeroTelefonico', $id)
                    ->where('estado', '0')
                    ->orderByDesc('iddetalleSimCard')
                    ->first();

                if ($detalleActual) {
                    DB::table('detallesimcard')
                        ->where('iddetalleSimCard', (int) $detalleActual->iddetalleSimCard)
                        ->delete();

                    if ($deleteMode === 'delete_with_simcard') {
                        DB::table('simcard')
                            ->where('idsimCard', (string) $detalleActual->simCard_idsimCard)
                            ->delete();
                    }
                }

                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', $id)
                    ->delete();
            });

            $this->publishResourceEvent('lineas_chips.numero_telefonico', $id, 'deleted');
            $this->releaseLockIfOwned($request, 'lineas_chips.numero_telefonico', $id);

            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('success', 'Número telefónico eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede eliminar el número telefónico porque tiene registros relacionados.');
        }
    }

    public function numerosTelefonicoBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('trim', $selectedIds), fn($id) => $id !== '');
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $id) {
            if ($redirect = $this->assertLockAvailable($request, 'lineas_chips.numero_telefonico', $id, 'número telefónico', 'modules.lineas-chips.numeros-telefonico.index')) {
                return $redirect;
            }

            if ($this->countNumeroHistorialSinRelacionActual($id) > 0) {
                return redirect()
                    ->route('modules.lineas-chips.numeros-telefonico.index')
                    ->with('error', 'No se puede eliminar el número ' . $id . ' porque tiene más de 1 historial previo.');
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                foreach ($selectedIds as $id) {
                    DB::table('detnumerosdispositivo')
                        ->where('numeroTelefonico_numeroTelefonico', $id)
                        ->delete();

                    DB::table('detallesimcard')
                        ->where('numeroTelefonico_numeroTelefonico', $id)
                        ->delete();

                    DB::table('numerotelefonico')
                        ->where('numeroTelefonico', $id)
                        ->delete();

                    $this->publishResourceEvent('lineas_chips.numero_telefonico', $id, 'deleted');
                    $this->releaseLockIfOwned($request, 'lineas_chips.numero_telefonico', $id);
                }
            });

            $count = count($selectedIds);
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('success', "Se eliminaron {$count} registro(s) correctamente.");
        } catch (QueryException $e) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'No se puede eliminar los registros seleccionados porque tienen registros relacionados.');
        }
    }

    public function numerosTelefonicoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('numerotelefonico as n')
            ->select('n.numeroTelefonico', 'n.plan', 'n.estado')
            ->addSelect([
                'precio_numero' => DB::table('detalle_precionumero as dp')
                    ->select('dp.precio')
                    ->whereColumn('dp.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->orderByDesc('dp.iddetalle_precionumero')
                    ->limit(1),
                'moneda_numero' => DB::table('detalle_precionumero as dp')
                    ->select('dp.moneda')
                    ->whereColumn('dp.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->orderByDesc('dp.iddetalle_precionumero')
                    ->limit(1),
                'simcard_actual' => DB::table('detallesimcard as d')
                    ->select('d.simCard_idsimCard')
                    ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('d.estado', '0')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
                'simcard_pasada' => DB::table('detallesimcard as d')
                    ->select('d.simCard_idsimCard')
                    ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('d.estado', '1')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
            ]);

        $selectedIds = (array) $request->input('selectedIds', []);
        if (!empty($selectedIds)) {
            $baseQuery->whereIn('n.numeroTelefonico', $selectedIds);
        } else {
            $estadoFilter = trim((string) $request->input('estado', ''));
            if ($estadoFilter !== '' && in_array($estadoFilter, ['0', '1', '2', '3'], true)) {
                $baseQuery->where('n.estado', $estadoFilter);
            }

            $numeroFilter = trim((string) $request->input('numero', ''));
            if ($numeroFilter !== '') {
                $baseQuery->where('n.numeroTelefonico', 'like', '%' . $numeroFilter . '%');
            }

            $simCardFilter = trim((string) $request->input('simcard', ''));
            if ($simCardFilter !== '') {
                $baseQuery->whereExists(function ($query) use ($simCardFilter) {
                    $query->select(DB::raw('1'))
                        ->from('detallesimcard as d')
                        ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                        ->where('d.simCard_idsimCard', 'like', '%' . $simCardFilter . '%');
                });
            }

            $search = trim((string) $request->input('q', ''));
            if ($search !== '') {
                $term = '%' . $search . '%';
                $baseQuery->where(function ($query) use ($term) {
                    $query
                        ->where('n.numeroTelefonico', 'like', $term)
                        ->orWhere('n.estado', 'like', $term)
                        ->orWhereExists(function ($query) use ($term) {
                            $query->select(DB::raw('1'))
                                ->from('detallesimcard as d')
                                ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                                ->where('d.simCard_idsimCard', 'like', $term);
                        })
                        ->orWhereExists(function ($query) use ($term) {
                            $query->select(DB::raw('1'))
                                ->from('detallesimcard as d')
                                ->leftJoin('simcard as s', 's.idsimCard', '=', 'd.simCard_idsimCard')
                                ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador')
                                ->whereColumn('d.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                                ->where('o.nombre', 'like', $term);
                        });
                });
            }
        }

        $rows = $baseQuery
            ->orderByRaw("CASE WHEN n.estado = '1' THEN 0 ELSE 1 END")
            ->orderBy('n.numeroTelefonico')
            ->get();

        $rows->transform(function ($item) {
            $simcardActual = trim((string) ($item->simcard_actual ?? ''));
            $simcardPasada = trim((string) ($item->simcard_pasada ?? ''));
            $precio = trim((string) ($item->precio_numero ?? ''));
            $moneda = trim((string) ($item->moneda_numero ?? ''));
            $item->precio_numero = $precio !== ''
                ? trim($moneda . ' ' . number_format((float) $precio, 2, '.', ''))
                : '-';
            $item->relacion_simcard = $simcardActual !== ''
                ? 'Asignación Actual: ' . $simcardActual
                : ($simcardPasada !== '' ? 'Última Asignación: ' . $simcardPasada : 'Sin Asignación');
            $item->estado = self::normalizeEstadoLabel($item->estado);
            return $item;
        });

        $columns = [
            ['key' => 'numeroTelefonico', 'label' => 'Número'],
            ['key' => 'plan', 'label' => 'Plan'],
            ['key' => 'precio_numero', 'label' => 'Precio'],
            ['key' => 'relacion_simcard', 'label' => 'Asignación SimCard'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];

        $filename = 'numero_telefonico_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Números Telefónicos', $filename);
    }

    public function simcardIndex(Request $request): View
    {
        $baseQuery = DB::table('simcard as s')
            ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador');

        $estadoFilter = trim((string) $request->input('estado', ''));
        if ($estadoFilter !== '' && in_array($estadoFilter, ['0', '1', '2', '3'], true)) {
            $baseQuery->where('s.estado', $estadoFilter);
        }

        $operadorFilter = trim((string) $request->input('operador', ''));
        if ($operadorFilter !== '') {
            $baseQuery->where('s.operador_idoperador', $operadorFilter);
        }

        $idsimCardFilter = trim((string) $request->input('idsimCard', ''));
        if ($idsimCardFilter !== '') {
            $baseQuery->where('s.idsimCard', 'like', '%' . $idsimCardFilter . '%');
        }

        $numeroFilter = trim((string) $request->input('numero', ''));
        if ($numeroFilter !== '') {
            $baseQuery->whereExists(function ($query) use ($numeroFilter) {
                $query->select(DB::raw('1'))
                    ->from('detallesimcard as d')
                    ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                    ->where('d.numeroTelefonico_numeroTelefonico', 'like', '%' . $numeroFilter . '%');
            });
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('s.idsimCard', 'like', $term)
                    ->orWhere('o.nombre', 'like', $term)
                    ->orWhere('s.estado', 'like', $term)
                    ->orWhereExists(function ($query) use ($term) {
                        $query->select(DB::raw('1'))
                            ->from('detallesimcard as d')
                            ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                            ->where('d.numeroTelefonico_numeroTelefonico', 'like', $term);
                    });
            });
        }

        $items = $baseQuery
            ->select('s.idsimCard', 's.estado', 's.operador_idoperador', 'o.nombre as operador_nombre')
            ->addSelect([
                'numero_actual' => DB::table('detallesimcard as d')
                    ->select('d.numeroTelefonico_numeroTelefonico')
                    ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                    ->where('d.estado', '0')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
                'numero_pasada' => DB::table('detallesimcard as d')
                    ->select('d.numeroTelefonico_numeroTelefonico')
                    ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                    ->where('d.estado', '1')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
            ])
            ->orderByRaw("CASE WHEN s.estado != '0' THEN 0 ELSE 1 END")
            ->orderBy('s.idsimCard')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->getCollection()->transform(function ($item) {
            $numeroActual = trim((string) ($item->numero_actual ?? ''));
            $numeroPasada = trim((string) ($item->numero_pasada ?? ''));
            $item->relacion_numero = $numeroActual !== ''
                ? 'Asignación Actual: ' . $numeroActual
                : ($numeroPasada !== '' ? 'Última Asignación: ' . $numeroPasada : 'Sin Asignación');
            $item->estado = self::normalizeEstadoLabel($item->estado);
            return $item;
        });

        return view('lineaschip.simcard', [
            'title' => 'SimCard',
            'singularTitle' => 'SimCard',
            'items' => $items,
            'columns' => [
                ['key' => 'idsimCard', 'label' => 'ID SimCard', 'type' => 'text'],
                ['key' => 'relacion_numero', 'label' => 'Relación Número', 'type' => 'text'],
                ['key' => 'operador_nombre', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.lineas-chips.simcard.export', ['format' => 'pdf']),
                'xlsx' => route('modules.lineas-chips.simcard.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de simcards', 'value' => (clone $baseQuery)->count()],
                ['label' => 'En Uso', 'value' => (clone $baseQuery)->where('s.estado', '3')->count()],
                ['label' => 'Disponibles', 'value' => (clone $baseQuery)->where('s.estado', '2')->count()],
            ],
            'filters' => [
                [
                    'name' => 'idsimCard',
                    'label' => 'ID SimCard',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por ID SimCard',
                ],
                [
                    'name' => 'numero',
                    'label' => 'Número',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por número relacionado',
                ],
                [
                    'name' => 'operador',
                    'label' => 'Operador',
                    'options' => collect($this->operadorOptions())
                        ->map(fn($label, $value): array => ['value' => (string) $value, 'label' => (string) $label])
                        ->values()
                        ->all(),
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'options' => [
                        ['value' => '1', 'label' => 'Libre'],
                        ['value' => '2', 'label' => 'Disponible'],
                        ['value' => '3', 'label' => 'En Uso'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                ],
            ],
            'createRoute' => route('modules.lineas-chips.simcard.create'),
            'editRoute' => 'modules.lineas-chips.simcard.edit',
            'showRoute' => 'modules.lineas-chips.simcard.edit',
            'destroyRoute' => 'modules.lineas-chips.simcard.destroy',
            'importPreviewRoute' => route('modules.lineas-chips.simcard.import.preview'),
            'importProcessRoute' => route('modules.lineas-chips.simcard.import.process'),
            'importButtonLabel' => 'Cargar SimCards',
            'importModalTitle' => 'Cargar SimCards por archivo',
            'importModalHint' => 'Archivo (.xlsx) con las columnas: SimCard, Operador',
            'importPreview' => null,
            'identifierKey' => 'idsimCard',
            'lockResource' => 'lineas_chips.simcard',
        ]);
    }

    public function simcardCreate(): View
    {
        return view('lineaschip.simcard-form', [
            'title' => 'Nueva SimCard',
            'moduleTitle' => 'SimCard',
            'mode' => 'create',
            'formAction' => route('modules.lineas-chips.simcard.store'),
            'backRoute' => route('modules.lineas-chips.simcard.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'idsimCard',
                    'type' => 'text',
                    'label' => 'ID SimCard',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 10,
                    'helpText' => 'Ingrese un identificador para la SimCard.',
                ],
                [
                    'name' => 'operador_idoperador',
                    'type' => 'select',
                    'label' => 'Operador',
                    'required' => true,
                    'tomSelect' => true,
                    'options' => $this->operadorOptions(),
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => old('estado', '1'),
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                ],
                [
                    'name' => 'desea_relacionar_numero',
                    'type' => 'checkbox',
                    'label' => '¿Desea crear un Número telefónico junto con la SimCard?',
                    'value' => old('desea_relacionar_numero', false),
                    'helpText' => 'Actívalo para mostrar los campos del número y crear la relación automáticamente.',
                ],
                [
                    'name' => 'numeroTelefonico',
                    'type' => 'text',
                    'label' => 'Número telefónico',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 9,
                    'quickCreateNumero' => true,
                    'helpText' => 'Ingrese mínimo 9 digitos, sin guiones ni espacios.',
                ],
                [
                    'name' => 'estado_numero',
                    'type' => 'select',
                    'label' => 'Estado del número',
                    'required' => true,
                    'value' => old('estado_numero', '1'),
                    'quickCreateNumero' => true,
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                    'helpText' => 'Debe estar activo para crear la relación.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function simcardStore(Request $request): RedirectResponse
    {
        $wantsNumero = $request->boolean('desea_relacionar_numero');

        $validated = $request->validate([
            'idsimCard' => ['required', 'string', 'min:10', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, 'unique:simcard,idsimCard'],
            'operador_idoperador' => ['required', 'integer', 'exists:operador,idoperador'],
            'estado' => ['required', 'string', $wantsNumero ? Rule::in(['1']) : Rule::in(['0', '1'])],
            'numeroTelefonico' => [
                Rule::requiredIf($wantsNumero),
                'nullable',
                'string',
                'min:9',
                'max:30',
                'regex:' . self::SAFE_TEXT_REGEX,
                Rule::unique('numerotelefonico', 'numeroTelefonico'),
            ],
            'estado_numero' => [Rule::requiredIf($wantsNumero), 'nullable', 'string', $wantsNumero ? Rule::in(['1']) : Rule::in(['0', '1'])],
        ], [
            'idsimCard.unique' => 'Este ID de SimCard ya está registrado.',
            'numeroTelefonico.unique' => 'Este número ya está registrado.',
            'estado.in' => 'La SimCard debe estar en estado activo para poder crear el número telefónico.',
            'estado_numero.in' => 'El número telefónico debe estar en estado activo para poder crearlo junto a la SimCard.',
        ]);

        if ($wantsNumero) {
            DB::transaction(function () use ($validated): void {
                DB::table('simcard')->insert([
                    'idsimCard' => $validated['idsimCard'],
                    'operador_idoperador' => (int) $validated['operador_idoperador'],
                    'estado' => '2',
                ]);

                DB::table('numerotelefonico')->insert([
                    'numeroTelefonico' => $validated['numeroTelefonico'],
                    'estado' => '2',
                ]);

                DB::table('detallesimcard')->insert([
                    'simCard_idsimCard' => $validated['idsimCard'],
                    'numeroTelefonico_numeroTelefonico' => $validated['numeroTelefonico'],
                    'fechaAsignacion' => Carbon::now()->format('Y-m-d H:i:s'),
                    'estado' => '0',
                ]);
            });

            $this->publishResourceEvent('lineas_chips.simcard', $validated['idsimCard'] ?? '', 'created');
            $this->publishResourceEvent('lineas_chips.numero_telefonico', $validated['numeroTelefonico'] ?? '', 'created');

            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('success', 'SimCard y número telefónico creados y relacionados correctamente.');
        }

        DB::table('simcard')->insert([
            'idsimCard' => $validated['idsimCard'],
            'operador_idoperador' => (int) $validated['operador_idoperador'],
            'estado' => $validated['estado'],
        ]);
        $this->publishResourceEvent('lineas_chips.simcard', $validated['idsimCard'] ?? '', 'created');

        return redirect()
            ->route('modules.lineas-chips.simcard.index')
            ->with('success', 'SimCard creada correctamente.');
    }

    public function simcardEdit(string $id): View|RedirectResponse
    {
        $record = DB::table('simcard')->where('idsimCard', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se encontro la SimCard solicitada.');
        }

        $historialPrevio = $this->countSimCardHistorialSinRelacionActual($id);
        if ($historialPrevio > 0) {
            $relacion = $this->buildSimCardRelacionActualTexto($id);
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se puede editar esta SimCard porque tiene asignaciones. ' . $relacion);
        }

        $record->estado = self::normalizeEstado($record->estado);

        $relacionTexto = $this->buildSimCardRelacionTexto($id);

        return view('lineaschip.simcard-form', [
            'title' => 'Editar SimCard',
            'moduleTitle' => 'SimCard',
            'mode' => 'edit',
            'formAction' => route('modules.lineas-chips.simcard.update', $id),
            'backRoute' => route('modules.lineas-chips.simcard.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'idsimCard',
                    'type' => 'text',
                    'label' => 'ID SimCard',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 10,
                    'disabled' => true,
                ],
                [
                    'name' => 'operador_idoperador',
                    'type' => 'select',
                    'label' => 'Operador',
                    'required' => true,
                    'options' => $this->operadorOptions(),
                    'disabled' => true,
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                ],
                [
                    'name' => 'relacion_actual_texto',
                    'type' => 'text',
                    'label' => 'Asignación Actual o Última Asignación',
                    'value' => $relacionTexto,
                    'readonly' => true,
                    'disabled' => true,
                    'colSpan' => 2,
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('lineas_chips.simcard', $id));
    }

    public function simcardUpdate(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('simcard')->where('idsimCard', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se encontro la SimCard solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'lineas_chips.simcard', $id, 'simcard', 'modules.lineas-chips.simcard.index')) {
            return $redirect;
        }

        $historialPrevio = $this->countSimCardHistorialSinRelacionActual($id);
        if ($historialPrevio > 0) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se puede editar esta SimCard porque tiene asignaciones. ' . $this->buildSimCardRelacionActualTexto($id));
        }

        $validated = $request->validate([
            'idsimCard' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:' . self::SAFE_TEXT_REGEX,
                Rule::unique('simcard', 'idsimCard')->ignore($id, 'idsimCard'),
            ],
            'operador_idoperador' => ['required', 'integer', 'exists:operador,idoperador'],
            'estado' => ['required', 'string', 'in:0,1,2,3'],
        ]);

        $newId = $validated['idsimCard'];

        DB::transaction(function () use ($id, $newId, $validated): void {
            if ($newId !== $id) {
                DB::table('simcard')->insert([
                    'idsimCard' => $newId,
                    'operador_idoperador' => (int) $validated['operador_idoperador'],
                    'estado' => $validated['estado'],
                ]);

                DB::table('detallesimcard')
                    ->where('simCard_idsimCard', $id)
                    ->update(['simCard_idsimCard' => $newId]);

                if ($validated['estado'] === '0') {
                    $pairedNumeros = DB::table('detallesimcard')
                        ->where('simCard_idsimCard', $newId)
                        ->where('estado', '0')
                        ->pluck('numeroTelefonico_numeroTelefonico');

                    DB::table('detallesimcard')
                        ->where('simCard_idsimCard', $newId)
                        ->where('estado', '0')
                        ->update(['estado' => '1']);

                    if ($pairedNumeros->isNotEmpty()) {
                        DB::table('numerotelefonico')
                            ->whereIn('numeroTelefonico', $pairedNumeros)
                            ->where('estado', '!=', '0')
                            ->update(['estado' => '1']);
                    }
                }

                DB::table('simcard')
                    ->where('idsimCard', $id)
                    ->delete();

                return;
            }

            DB::table('simcard')->where('idsimCard', $id)->update([
                'operador_idoperador' => (int) $validated['operador_idoperador'],
                'estado' => $validated['estado'],
            ]);

            if ($validated['estado'] === '0') {
                $pairedNumeros = DB::table('detallesimcard')
                    ->where('simCard_idsimCard', $id)
                    ->where('estado', '0')
                    ->pluck('numeroTelefonico_numeroTelefonico');

                DB::table('detallesimcard')
                    ->where('simCard_idsimCard', $id)
                    ->where('estado', '0')
                    ->update(['estado' => '1']);

                if ($pairedNumeros->isNotEmpty()) {
                    DB::table('numerotelefonico')
                        ->whereIn('numeroTelefonico', $pairedNumeros)
                        ->where('estado', '!=', '0')
                        ->update(['estado' => '1']);
                }
            }
        });

        $this->publishResourceEvent('lineas_chips.simcard', $newId, 'updated');

        $this->releaseLockIfOwned($request, 'lineas_chips.simcard', $id);

        return redirect()
            ->route('modules.lineas-chips.simcard.index')
            ->with('success', 'SimCard actualizada correctamente.');
    }

    public function simcardDestroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'lineas_chips.simcard', $id, 'simcard', 'modules.lineas-chips.simcard.index')) {
            return $redirect;
        }

        $historialPrevio = $this->countSimCardHistorialSinRelacionActual($id);
        if ($historialPrevio > 0) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se puede eliminar esta SimCard porque tiene asignaciones. ' . $this->buildSimCardRelacionActualTexto($id));
        }

        $deleteMode = trim((string) $request->input('deleteMode', ''));

        if ($deleteMode === 'delete_with_number' && !$this->canDeleteSimcardWithNumero($id)) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se puede eliminar con el número porque ya existe historial o relación con dispositivo.');
        }

        try {
            DB::transaction(function () use ($id, $deleteMode): void {
                $detalleActual = DB::table('detallesimcard')
                    ->where('simCard_idsimCard', $id)
                    ->where('estado', '0')
                    ->orderByDesc('iddetalleSimCard')
                    ->first();

                if ($detalleActual) {
                    DB::table('detallesimcard')
                        ->where('iddetalleSimCard', (int) $detalleActual->iddetalleSimCard)
                        ->delete();

                    if ($deleteMode === 'delete_with_numero') {
                        DB::table('numerotelefonico')
                            ->where('numeroTelefonico', (string) $detalleActual->numeroTelefonico_numeroTelefonico)
                            ->delete();
                    }
                }

                DB::table('simcard')
                    ->where('idsimCard', $id)
                    ->delete();
            });

            $this->publishResourceEvent('lineas_chips.simcard', $id, 'deleted');
            $this->releaseLockIfOwned($request, 'lineas_chips.simcard', $id);

            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('success', 'SimCard eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'No se puede eliminar la SimCard porque tiene registros relacionados.');
        }
    }

    public function simcardExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('simcard as s')
            ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador');

        $selectedIds = (array) $request->input('selectedIds', []);

        if (!empty($selectedIds)) {
            $baseQuery->whereIn('s.idsimCard', $selectedIds);
        } else {
            $estadoFilter = trim((string) $request->input('estado', ''));
            if ($estadoFilter !== '' && in_array($estadoFilter, ['0', '1', '2', '3'], true)) {
                $baseQuery->where('s.estado', $estadoFilter);
            }

            $operadorFilter = trim((string) $request->input('operador', ''));
            if ($operadorFilter !== '') {
                $baseQuery->where('s.operador_idoperador', $operadorFilter);
            }

            $idsimCardFilter = trim((string) $request->input('idsimCard', ''));
            if ($idsimCardFilter !== '') {
                $baseQuery->where('s.idsimCard', 'like', '%' . $idsimCardFilter . '%');
            }

            $numeroFilter = trim((string) $request->input('numero', ''));
            if ($numeroFilter !== '') {
                $baseQuery->whereExists(function ($query) use ($numeroFilter) {
                    $query->select(DB::raw('1'))
                        ->from('detallesimcard as d')
                        ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                        ->where('d.numeroTelefonico_numeroTelefonico', 'like', '%' . $numeroFilter . '%');
                });
            }

            $search = trim((string) $request->input('q', ''));
            if ($search !== '') {
                $term = '%' . $search . '%';
                $baseQuery->where(function ($query) use ($term) {
                    $query
                        ->where('s.idsimCard', 'like', $term)
                        ->orWhere('o.nombre', 'like', $term)
                        ->orWhere('s.estado', 'like', $term)
                        ->orWhereExists(function ($query) use ($term) {
                            $query->select(DB::raw('1'))
                                ->from('detallesimcard as d')
                                ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                                ->where('d.numeroTelefonico_numeroTelefonico', 'like', $term);
                        });
                });
            }
        }

        $rows = $baseQuery
            ->select('s.idsimCard', 'o.nombre as operador_nombre', 's.estado')
            ->addSelect([
                'numero_actual' => DB::table('detallesimcard as d')
                    ->select('d.numeroTelefonico_numeroTelefonico')
                    ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                    ->where('d.estado', '0')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
                'numero_pasada' => DB::table('detallesimcard as d')
                    ->select('d.numeroTelefonico_numeroTelefonico')
                    ->whereColumn('d.simCard_idsimCard', 's.idsimCard')
                    ->where('d.estado', '1')
                    ->orderByDesc('d.iddetalleSimCard')
                    ->limit(1),
            ])
            ->orderByRaw("CASE WHEN s.estado = '1' THEN 0 ELSE 1 END")
            ->orderBy('s.idsimCard')
            ->get();

        $rows->transform(function ($item) {
            $numeroActual = trim((string) ($item->numero_actual ?? ''));
            $numeroPasada = trim((string) ($item->numero_pasada ?? ''));
            $item->relacion_numero = $numeroActual !== ''
                ? 'Asignación Actual: ' . $numeroActual
                : ($numeroPasada !== '' ? 'Última Asignación: ' . $numeroPasada : 'Sin Asignación');
            $item->estado = self::normalizeEstadoLabel($item->estado);
            return $item;
        });

        $columns = [
            ['key' => 'idsimCard', 'label' => 'ID SimCard'],
            ['key' => 'relacion_numero', 'label' => 'Asignación Número'],
            ['key' => 'operador_nombre', 'label' => 'Operador'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];

        $filename = 'simcard_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de SimCards', $filename);
    }

    public function detallesimcardIndex(Request $request): View
    {
        $baseQuery = DB::table('detallesimcard as d')
            ->leftJoin('simcard as s', 's.idsimCard', '=', 'd.simCard_idsimCard')
            ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico');

        $simCardFilter = trim((string) $request->input('simcard', ''));
        if ($simCardFilter !== '') {
            $baseQuery->where('d.simCard_idsimCard', 'like', '%' . $simCardFilter . '%');
        }

        $numeroTelefonicoFilter = trim((string) $request->input('numeroTelefonico', ''));
        if ($numeroTelefonicoFilter !== '') {
            $baseQuery->where('d.numeroTelefonico_numeroTelefonico', 'like', '%' . $numeroTelefonicoFilter . '%');
        }

        $fechaAsignacionFilter = self::normalizeFechaAsignacionForInput($request->input('fechaAsignacion'));
        if ($fechaAsignacionFilter !== null) {
            $baseQuery->whereDate('d.fechaAsignacion', $fechaAsignacionFilter);
        }

        $estadoFilter = trim((string) $request->input('estado', ''));
        if ($estadoFilter !== '' && in_array($estadoFilter, ['0', '1'], true)) {
            $baseQuery->where('d.estado', $estadoFilter);
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('d.iddetalleSimCard', 'like', $term)
                    ->orWhere('d.simCard_idsimCard', 'like', $term)
                    ->orWhere('d.numeroTelefonico_numeroTelefonico', 'like', $term);
            });
        }

        $items = $baseQuery
            ->select(
                'd.iddetalleSimCard',
                'd.simCard_idsimCard',
                'd.numeroTelefonico_numeroTelefonico',
                'd.fechaAsignacion',
                'd.estado',
                DB::raw('(SELECT o.nombre FROM operador as o JOIN simcard as s2 ON s2.operador_idoperador = o.idoperador WHERE s2.idsimCard = d.simCard_idsimCard LIMIT 1) as operador_nombre')
            )
            ->when($estadoFilter === '', fn($query) => $query->where('d.estado', '0'))
            ->orderByRaw("CASE WHEN d.estado = '0' THEN 0 ELSE 1 END")
            ->orderByDesc('d.fechaAsignacion')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $activeRows = $items->getCollection();

        // Build history per active row by expanding connected relations (transitive via simCard and numero)
        $buildHistoryFor = function (?string $startSim, ?string $startNumero) {
            $collected = collect();
            $seenIds = [];

            $sims = collect([$startSim])->filter()->unique()->values()->all();
            $numeros = collect([$startNumero])->filter()->unique()->values()->all();

            do {
                $query = DB::table('detallesimcard as d')
                    ->where('d.estado', '1')
                    ->where(function ($q) use ($sims, $numeros) {
                        if (!empty($sims)) {
                            $q->whereIn('d.simCard_idsimCard', $sims);
                        }
                        if (!empty($numeros)) {
                            if (!empty($sims)) {
                                $q->orWhereIn('d.numeroTelefonico_numeroTelefonico', $numeros);
                            } else {
                                $q->whereIn('d.numeroTelefonico_numeroTelefonico', $numeros);
                            }
                        }
                    })
                    ->select(
                        'd.iddetalleSimCard',
                        'd.simCard_idsimCard',
                        'd.numeroTelefonico_numeroTelefonico',
                        'd.fechaAsignacion',
                        'd.estado',
                        DB::raw('(SELECT o.nombre FROM operador as o JOIN simcard as s2 ON s2.operador_idoperador = o.idoperador WHERE s2.idsimCard = d.simCard_idsimCard LIMIT 1) as operador_nombre')
                    );

                if (!empty($seenIds)) {
                    $query->whereNotIn('d.iddetalleSimCard', $seenIds);
                }

                $found = $query->get();
                $newAdded = 0;
                foreach ($found as $f) {
                    if (in_array($f->iddetalleSimCard, $seenIds, true)) {
                        continue;
                    }
                    $seenIds[] = $f->iddetalleSimCard;
                    $collected->push($f);

                    if (!in_array($f->simCard_idsimCard, $sims, true)) {
                        $sims[] = $f->simCard_idsimCard;
                        $newAdded++;
                    }
                    if (!in_array($f->numeroTelefonico_numeroTelefonico, $numeros, true)) {
                        $numeros[] = $f->numeroTelefonico_numeroTelefonico;
                        $newAdded++;
                    }
                }
            } while ($found->isNotEmpty() && $newAdded > 0);

            // Order by fechaAsignacion ascending (earliest first) for clear history
            $collected = $collected->sortBy(function ($r) {
                return $r->fechaAsignacion ?? '';
            })->values();

            return $collected;
        };

        $items->setCollection($activeRows->map(function ($row) use ($buildHistoryFor) {
            if ($row->fechaAsignacion) {
                $row->fechaAsignacion = Carbon::parse($row->fechaAsignacion)->locale('es')->translatedFormat('d M Y');
            }

            $history = $buildHistoryFor(trim((string) ($row->simCard_idsimCard ?? '')), trim((string) ($row->numeroTelefonico_numeroTelefonico ?? '')));

            $row->history = $history->map(function ($history) {
                if ($history->fechaAsignacion) {
                    $history->fechaAsignacion = Carbon::parse($history->fechaAsignacion)->locale('es')->translatedFormat('d M Y');
                }
                $history->estado = self::normalizeDetalleSimCardEstadoLabel($history->estado);
                return $history;
            })->values();

            $row->estado = self::normalizeDetalleSimCardEstadoLabel($row->estado);
            return $row;
        }));

        return view('lineaschip.detallesimcard', [
            'title' => 'Asignacion SimCard',
            'singularTitle' => 'Asignacion SimCard',
            'items' => $items,
            'columns' => [
                ['key' => 'simCard_idsimCard', 'label' => 'SimCard', 'type' => 'text'],
                ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico', 'type' => 'text'],
                ['key' => 'operador_nombre', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'fechaAsignacion', 'label' => 'Fecha de asignación', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.lineas-chips.detallesimcard.export', ['format' => 'pdf']),
                'xlsx' => route('modules.lineas-chips.detallesimcard.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de Asignacion', 'value' => DB::table('detallesimcard')->count()],
                ['label' => 'Asignacion activos', 'value' => DB::table('detallesimcard')->where('estado', '0')->count()],
                ['label' => 'Asignacion inactivos', 'value' => DB::table('detallesimcard')->where('estado', '1')->count()],
            ],
            'historyColumns' => [
                ['key' => 'simCard_idsimCard', 'label' => 'SimCard', 'type' => 'text'],
                ['key' => 'operador_nombre', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico', 'type' => 'text'],
                ['key' => 'fechaAsignacion', 'label' => 'Fecha de asignación', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'filters' => [
                [
                    'name' => 'simcard',
                    'label' => 'SimCard',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por simcard',
                ],
                [
                    'name' => 'numeroTelefonico',
                    'label' => 'Número telefónico',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por número telefónico',
                ],
                [
                    'name' => 'fechaAsignacion',
                    'label' => 'Fecha de asignación',
                    'type' => 'date',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => [
                        ['value' => '0', 'label' => 'Activo'],
                        ['value' => '1', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Todos',
                ],
            ],
            'createRoute' => route('modules.lineas-chips.detallesimcard.create'),
            'editRoute' => '',
            'showRoute' => '',
            'destroyRoute' => 'modules.lineas-chips.detallesimcard.destroy',
            'bulkDeactivateRoute' => route('modules.lineas-chips.detallesimcard.bulk-deactivate'),
            'importPreviewRoute' => route('modules.lineas-chips.detallesimcard.import.preview'),
            'importProcessRoute' => route('modules.lineas-chips.detallesimcard.import.process'),
            'numeroTelefonicoStateList' => DB::table('numerotelefonico')
                ->where('estado', '1')
                ->select('numeroTelefonico', DB::raw('1 as isActive'))
                ->get()
                ->map(fn($row) => [
                    'numero' => $row->numeroTelefonico,
                    'isActive' => $row->isActive,
                ])
                ->values()
                ->toArray(),
            'detallesimcardImportPreview' => null,
            'identifierKey' => 'iddetalleSimCard',
            'lockResource' => 'lineas_chips.detallesimcard',
        ]);
    }

    public function detallesimcardCreate(): View
    {
        return view('lineaschip.detallesimcard-form', [
            'title' => 'Nueva Asignacion SimCard',
            'moduleTitle' => 'Asignacion SimCard',
            'mode' => 'create',
            'formAction' => route('modules.lineas-chips.detallesimcard.store'),
            'backRoute' => route('modules.lineas-chips.detallesimcard.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'simCard_idsimCard',
                    'type' => 'select',
                    'label' => 'SimCard',
                    'required' => true,
                    'options' => $this->simCardOptions(),
                    'placeholder' => 'Selecciona una SimCard',
                    'tomSelect' => true,
                    'helpText' => 'Puede seleccionar una SimCard libre o una disponible que tenga una asignación vigente para reasignarla.',
                ],
                [
                    'name' => 'numeroTelefonico_numeroTelefonico',
                    'type' => 'select',
                    'label' => 'Número telefónico',
                    'required' => true,
                    'options' => $this->numeroTelefonicoOptions(),
                    'placeholder' => 'Selecciona un número telefónico',
                    'tomSelect' => true,
                    'helpText' => 'Puede seleccionar un número libre o uno disponible para reasignar su SimCard. No se muestran números ligados a dispositivos activos.',
                ],
                [
                    'name' => 'fechaAsignacion',
                    'type' => 'date',
                    'label' => 'Fecha de asignación',
                    'required' => false,
                    'value' => old('fechaAsignacion', Carbon::now()->format('Y-m-d')),
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function detallesimcardStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'simCard_idsimCard' => ['required', 'string', 'exists:simcard,idsimCard'],
            'numeroTelefonico_numeroTelefonico' => ['required', 'string', Rule::exists('numerotelefonico', 'numeroTelefonico')],
            'fechaAsignacion' => ['nullable', 'date'],
        ]);

        $validated['fechaAsignacion'] = self::normalizeFechaAsignacionForStorage($validated['fechaAsignacion'] ?? null)
            ?? Carbon::now()->format('Y-m-d H:i:s');
        $validated['estado'] = '0';

        $newId = DB::transaction(function () use ($validated): string {
            DB::table('simcard')
                ->where('idsimCard', $validated['simCard_idsimCard'])
                ->lockForUpdate()
                ->first();
            DB::table('numerotelefonico')
                ->where('numeroTelefonico', $validated['numeroTelefonico_numeroTelefonico'])
                ->lockForUpdate()
                ->first();

            $this->assertDetalleSimCardPairIsUnique(
                $validated['simCard_idsimCard'],
                $validated['numeroTelefonico_numeroTelefonico']
            );

            $previousAssignmentForNumber = DB::table('detallesimcard')
                ->where('numeroTelefonico_numeroTelefonico', $validated['numeroTelefonico_numeroTelefonico'])
                ->where('estado', '0')
                ->orderByDesc('iddetalleSimCard')
                ->first();

            $previousAssignmentForSimCard = DB::table('detallesimcard')
                ->where('simCard_idsimCard', $validated['simCard_idsimCard'])
                ->where('estado', '0')
                ->orderByDesc('iddetalleSimCard')
                ->first();

            DB::table('detallesimcard')->insert($validated);
            $id = (string) DB::getPdo()->lastInsertId();

            if ($previousAssignmentForNumber) {
                DB::table('detallesimcard')
                    ->where('iddetalleSimCard', (int) $previousAssignmentForNumber->iddetalleSimCard)
                    ->update(['estado' => '1']);

                DB::table('simcard')
                    ->where('idsimCard', (string) $previousAssignmentForNumber->simCard_idsimCard)
                    ->where('estado', '!=', '0')
                    ->update(['estado' => '1']);
            }

            if ($previousAssignmentForSimCard) {
                DB::table('detallesimcard')
                    ->where('iddetalleSimCard', (int) $previousAssignmentForSimCard->iddetalleSimCard)
                    ->update(['estado' => '1']);

                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', (string) $previousAssignmentForSimCard->numeroTelefonico_numeroTelefonico)
                    ->where('estado', '!=', '0')
                    ->update(['estado' => '1']);
            }

            DB::table('simcard')
                ->where('idsimCard', $validated['simCard_idsimCard'])
                ->update(['estado' => '2']);

            DB::table('numerotelefonico')
                ->where('numeroTelefonico', $validated['numeroTelefonico_numeroTelefonico'])
                ->update(['estado' => '2']);

            return $id;
        });

        $this->publishResourceEvent('lineas_chips.detallesimcard', $newId, 'created');

        return redirect()
            ->route('modules.lineas-chips.detallesimcard.index')
            ->with('success', 'Detalle SimCard creado correctamente.');
    }

    public function detallesimcardDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'lineas_chips.detallesimcard', (string) $id, 'Asignacion simcard', 'modules.lineas-chips.detallesimcard.index')) {
            return $redirect;
        }

        $detalle = DB::table('detallesimcard')->where('iddetalleSimCard', $id)->first();
        if (!$detalle) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se encontró la asignación de SimCard solicitada.');
        }

        if (trim((string) ($detalle->estado ?? '')) === '1') {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se puede eliminar esta asignación porque forma parte del historial.');
        }

        $relatedHistoryCount = DB::table('detallesimcard')
            ->where(function ($query) use ($detalle) {
                $query
                    ->where('simCard_idsimCard', $detalle->simCard_idsimCard)
                    ->orWhere('numeroTelefonico_numeroTelefonico', $detalle->numeroTelefonico_numeroTelefonico);
            })
            ->where('iddetalleSimCard', '!=', $id)
            ->count();

        if ($relatedHistoryCount > 0) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se puede eliminar esta asignación porque ya tiene historial previo.');
        }

        $deleteMode = trim((string) $request->input('deleteMode', ''));

        if ($deleteMode === 'delete_with_number_and_simcard' && !$this->canDeleteDetalleWithNumberAndSimcard($detalle)) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se puede eliminar con el número y la SimCard porque ya existe historial o relación con dispositivo.');
        }

        try {
            DB::table('detallesimcard')->where('iddetalleSimCard', $id)->delete();

            if ($deleteMode === 'delete_with_number_and_simcard') {
                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', (string) $detalle->numeroTelefonico_numeroTelefonico)
                    ->delete();

                DB::table('simcard')
                    ->where('idsimCard', (string) $detalle->simCard_idsimCard)
                    ->delete();
            } else {
                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', (string) $detalle->numeroTelefonico_numeroTelefonico)
                    ->where('estado', '!=', '0')
                    ->update(['estado' => '1']);

                DB::table('simcard')
                    ->where('idsimCard', (string) $detalle->simCard_idsimCard)
                    ->where('estado', '!=', '0')
                    ->update(['estado' => '1']);
            }

            $this->publishResourceEvent('lineas_chips.detallesimcard', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'lineas_chips.detallesimcard', (string) $id);

            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('success', 'Asignacion SimCard eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se puede eliminar la asignacion porque tiene registros relacionados.');
        }
    }

    public function detallesimcardExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('detallesimcard as d');

        $selectedIds = (array) $request->input('selectedIds', []);

        if (!empty($selectedIds)) {
            $baseQuery->whereIn('d.iddetalleSimCard', $selectedIds);
        } else {
            $simCardFilter = trim((string) $request->input('simcard', ''));
            if ($simCardFilter !== '') {
                $baseQuery->where('d.simCard_idsimCard', 'like', '%' . $simCardFilter . '%');
            }

            $numeroTelefonicoFilter = trim((string) $request->input('numeroTelefonico', ''));
            if ($numeroTelefonicoFilter !== '') {
                $baseQuery->where('d.numeroTelefonico_numeroTelefonico', 'like', '%' . $numeroTelefonicoFilter . '%');
            }

            $fechaAsignacionFilter = self::normalizeFechaAsignacionForInput($request->input('fechaAsignacion'));
            if ($fechaAsignacionFilter !== null) {
                $baseQuery->whereDate('d.fechaAsignacion', $fechaAsignacionFilter);
            }

            $estadoFilter = trim((string) $request->input('estado', ''));
            if ($estadoFilter !== '' && in_array($estadoFilter, ['0', '1'], true)) {
                $baseQuery->where('d.estado', $estadoFilter);
            }

            $search = trim((string) $request->input('q', ''));
            if ($search !== '') {
                $term = '%' . $search . '%';
                $baseQuery->where(function ($query) use ($term) {
                    $query
                        ->where('d.iddetalleSimCard', 'like', $term)
                        ->orWhere('d.simCard_idsimCard', 'like', $term)
                        ->orWhere('d.numeroTelefonico_numeroTelefonico', 'like', $term);
                });
            }
        }

        $rows = $baseQuery
            ->select(
                'd.iddetalleSimCard',
                'd.simCard_idsimCard',
                'd.numeroTelefonico_numeroTelefonico',
                'd.fechaAsignacion',
                'd.estado',
                DB::raw('(SELECT o.nombre FROM operador as o JOIN simcard as s2 ON s2.operador_idoperador = o.idoperador WHERE s2.idsimCard = d.simCard_idsimCard LIMIT 1) as operador_nombre')
            )
            ->orderByRaw("CASE WHEN d.estado = '0' THEN 0 ELSE 1 END")
            ->orderBy('d.iddetalleSimCard')
            ->get();

        $rows->transform(function ($item) {
            $item->fechaAsignacion = self::normalizeFechaAsignacionForInput($item->fechaAsignacion) ?? '';
            $item->estado = self::normalizeDetalleSimCardEstadoLabel($item->estado);
            return $item;
        });

        $columns = [
            ['key' => 'iddetalleSimCard', 'label' => 'ID'],
            ['key' => 'simCard_idsimCard', 'label' => 'SimCard'],
            ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico'],
            ['key' => 'operador_nombre', 'label' => 'Operador'],
            ['key' => 'fechaAsignacion', 'label' => 'Fecha de asignación'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];

        $filename = 'detallesimcard_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Asignaciones SimCard', $filename);
    }

    public function numerosDispositivoIndex(Request $request): View
    {
        $baseQuery = DB::table('detnumerosdispositivo as d')
            ->leftJoin('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'dc.vehiculo_placa')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'v.cliente_idcliente')
            ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico');

        $dispositivoFilter = trim((string) $request->input('dispositivo', ''));
        if ($dispositivoFilter !== '') {
            $baseQuery->where(function ($query) use ($dispositivoFilter) {
                $term = '%' . $dispositivoFilter . '%';
                $query
                    ->where('d.dispositivoCliente_iddispositivoCliente', 'like', $term)
                    ->orWhere('dc.vehiculo_placa', 'like', $term)
                    ->orWhere('dc.marcaDispositivo', 'like', $term)
                    ->orWhere('dc.modeloDispositivo', 'like', $term);
            });
        }

        $vehiculoFilter = trim((string) $request->input('vehiculo', ''));
        if ($vehiculoFilter !== '') {
            $baseQuery->where('dc.vehiculo_placa', 'like', '%' . $vehiculoFilter . '%');
        }

        $clienteFilter = trim((string) $request->input('cliente', ''));
        if ($clienteFilter !== '') {
            $baseQuery->where('c.nombreComercial', 'like', '%' . $clienteFilter . '%');
        }

        $numeroTelefonicoFilter = trim((string) $request->input('numeroTelefonico', ''));
        if ($numeroTelefonicoFilter !== '') {
            $baseQuery->where('d.numeroTelefonico_numeroTelefonico', 'like', '%' . $numeroTelefonicoFilter . '%');
        }

        $fechaAsignacionFilter = self::normalizeFechaAsignacionForInput($request->input('fechaAsignacion'));
        if ($fechaAsignacionFilter !== null) {
            $baseQuery->whereDate('d.fechaAsignacion', $fechaAsignacionFilter);
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('d.iddetNumerosDispositivo', 'like', $term)
                    ->orWhere('d.dispositivoCliente_iddispositivoCliente', 'like', $term)
                    ->orWhere('dc.vehiculo_placa', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('d.numeroTelefonico_numeroTelefonico', 'like', $term)
                    ->orWhere('dc.marcaDispositivo', 'like', $term)
                    ->orWhere('dc.modeloDispositivo', 'like', $term);
            });
        }

        $itemsQuery = clone $baseQuery;

        $latestActivePerDevice = DB::table('detnumerosdispositivo as d2')
            ->leftJoin('numerotelefonico as n2', 'n2.numeroTelefonico', '=', 'd2.numeroTelefonico_numeroTelefonico')
            ->select('d2.dispositivoCliente_iddispositivoCliente', DB::raw('MAX(d2.fechaAsignacion) as max_fecha'))
            ->where('n2.estado', '!=', '0')
            ->groupBy('d2.dispositivoCliente_iddispositivoCliente');

        $items = $itemsQuery
            ->joinSub($latestActivePerDevice, 'latest', function ($join) {
                $join->on('latest.dispositivoCliente_iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente');
                $join->on('latest.max_fecha', '=', 'd.fechaAsignacion');
            })
            ->where('n.estado', '!=', '0')
            ->select(
                'd.iddetNumerosDispositivo',
                'd.dispositivoCliente_iddispositivoCliente',
                'dc.marcaDispositivo',
                'dc.modeloDispositivo',
                'dc.vehiculo_placa',
                DB::raw("(SELECT ds2.simCard_idsimCard FROM detallesimcard as ds2 WHERE ds2.numeroTelefonico_numeroTelefonico = d.numeroTelefonico_numeroTelefonico AND ds2.estado = '0' ORDER BY ds2.iddetalleSimCard DESC LIMIT 1) as simcard_id"),
                DB::raw("(SELECT o.nombre FROM detallesimcard as ds3 LEFT JOIN simcard as s3 ON s3.idsimCard = ds3.simCard_idsimCard LEFT JOIN operador as o ON o.idoperador = s3.operador_idoperador WHERE ds3.numeroTelefonico_numeroTelefonico = d.numeroTelefonico_numeroTelefonico AND ds3.estado = '0' ORDER BY ds3.iddetalleSimCard DESC LIMIT 1) as operador_nombre"),
                'd.numeroTelefonico_numeroTelefonico',
                'd.fechaAsignacion',
                DB::raw('COALESCE(c.nombreComercial, c.razonSocial, c.idcliente) as nombre_cliente'),
                'n.estado',
            )
            ->orderBy('d.iddetNumerosDispositivo', 'desc')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $activeRows = $items->getCollection();
        $historyRows = collect();

        $deviceIds = $activeRows
            ->pluck('dispositivoCliente_iddispositivoCliente')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!empty($deviceIds)) {
            $historyRows = DB::table('detnumerosdispositivo as d')
                ->leftJoin('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente')
                ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico')
                ->whereIn('d.dispositivoCliente_iddispositivoCliente', $deviceIds)
                ->select(
                    'd.iddetNumerosDispositivo',
                    'd.dispositivoCliente_iddispositivoCliente',
                    'dc.marcaDispositivo',
                    'dc.modeloDispositivo',
                    'dc.vehiculo_placa',
                    DB::raw("(SELECT ds2.simCard_idsimCard FROM detallesimcard as ds2 WHERE ds2.numeroTelefonico_numeroTelefonico = d.numeroTelefonico_numeroTelefonico AND ds2.estado = '0' ORDER BY ds2.iddetalleSimCard DESC LIMIT 1) as simcard_id"),
                    DB::raw("(SELECT o.nombre FROM detallesimcard as ds3 LEFT JOIN simcard as s3 ON s3.idsimCard = ds3.simCard_idsimCard LEFT JOIN operador as o ON o.idoperador = s3.operador_idoperador WHERE ds3.numeroTelefonico_numeroTelefonico = d.numeroTelefonico_numeroTelefonico AND ds3.estado = '0' ORDER BY ds3.iddetalleSimCard DESC LIMIT 1) as operador_nombre"),
                    'd.numeroTelefonico_numeroTelefonico',
                    'd.fechaAsignacion',
                )
                ->orderBy('d.fechaAsignacion')
                ->get();
        }

        $items->setCollection($activeRows->map(function ($row) use ($historyRows) {
            if ($row->fechaAsignacion) {
                $row->fechaAsignacion = Carbon::parse($row->fechaAsignacion)->locale('es')->translatedFormat('d M Y');
            }

            $row->history = $historyRows
                ->filter(function ($history) use ($row) {
                    return (string) ($history->dispositivoCliente_iddispositivoCliente ?? '') === (string) ($row->dispositivoCliente_iddispositivoCliente ?? '')
                        && (int) ($history->iddetNumerosDispositivo ?? 0) !== (int) ($row->iddetNumerosDispositivo ?? 0);
                })
                ->map(function ($history) {
                    if ($history->fechaAsignacion) {
                        $history->fechaAsignacion = Carbon::parse($history->fechaAsignacion)->locale('es')->translatedFormat('d M Y');
                    }
                    $history->estado = self::normalizeEstadoLabel($history->numero_estado ?? null);
                    return $history;
                })
                ->values();

            return $row;
        }));

        return view('lineaschip.numerosdispositivo', [
            'title' => 'Números de dispositivo',
            'singularTitle' => 'Número de dispositivo',
            'items' => $items,
            'columns' => [
                ['key' => 'dispositivoCliente_iddispositivoCliente', 'label' => 'Dispositivo', 'type' => 'text'],
                ['key' => 'marcaDispositivo', 'label' => 'Marca', 'type' => 'text'],
                ['key' => 'modeloDispositivo', 'label' => 'Modelo', 'type' => 'text'],
                ['key' => 'vehiculo_placa', 'label' => 'Vehículo', 'type' => 'text'],
                ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico', 'type' => 'text'],
                ['key' => 'simcard_id', 'label' => 'SimCard', 'type' => 'text'],
                ['key' => 'operador_nombre', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'nombre_cliente', 'label' => 'Cliente'],
                ['key' => 'fechaAsignacion', 'label' => 'Fecha de inicio', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.lineas-chips.numeros-dispositivo.export', ['format' => 'pdf']),
                'xlsx' => route('modules.lineas-chips.numeros-dispositivo.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de números de dispositivo', 'value' => (clone $baseQuery)->distinct('d.dispositivoCliente_iddispositivoCliente')->count('d.dispositivoCliente_iddispositivoCliente')],
                ['label' => 'Dispositivos activos', 'value' => (clone $baseQuery)->where('n.estado', '!=', '0')->distinct('d.dispositivoCliente_iddispositivoCliente')->count('d.dispositivoCliente_iddispositivoCliente')],
                ['label' => 'Números activos', 'value' => (clone $baseQuery)->where('n.estado', '!=', '0')->count()],
            ],
            'historyColumns' => [
                ['key' => 'dispositivoCliente_iddispositivoCliente', 'label' => 'Dispositivo', 'type' => 'text'],
                ['key' => 'marcaDispositivo', 'label' => 'Marca', 'type' => 'text'],
                ['key' => 'modeloDispositivo', 'label' => 'Modelo', 'type' => 'text'],
                ['key' => 'vehiculo_placa', 'label' => 'Placa', 'type' => 'text'],
                ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico', 'type' => 'text'],
                ['key' => 'simcard_id', 'label' => 'SimCard', 'type' => 'text'],
                ['key' => 'operador_nombre', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'fechaAsignacion', 'label' => 'Fecha de asignación', 'type' => 'text'],
            ],
            'tableWrapperClass' => 'erp-numeros-dispositivo-table',
            'filters' => [
                [
                    'name' => 'dispositivo',
                    'label' => 'Dispositivo',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por dispositivo o placa',
                ],
                [
                    'name' => 'vehiculo',
                    'label' => 'Vehículo',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por vehículo',
                ],
                [
                    'name' => 'cliente',
                    'label' => 'Cliente',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por cliente',
                ],
                [
                    'name' => 'numeroTelefonico',
                    'label' => 'Número telefónico',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por número telefónico',
                ],
                [
                    'name' => 'fechaAsignacion',
                    'label' => 'Fecha de asignación',
                    'type' => 'date',
                ],
            ],
            'destroyRoute' => 'modules.lineas-chips.numeros-dispositivo.destroy',
            'bulkDestroyRoute' => route('modules.lineas-chips.numeros-dispositivo.bulk-destroy'),
            'identifierKey' => 'iddetNumerosDispositivo',
            'lockResource' => self::NUMERO_DISPOSITIVO_LOCK_RESOURCE,
        ]);
    }

    public function numerosDispositivoCreate(): View
    {
        return view('lineaschip.numerosdispositivo-form', [
            'title' => 'Nuevo Número de dispositivo',
            'moduleTitle' => 'Números de dispositivo',
            'mode' => 'create',
            'formAction' => route('modules.lineas-chips.numeros-dispositivo.store'),
            'backRoute' => route('modules.lineas-chips.numeros-dispositivo.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'dispositivoCliente_iddispositivoCliente',
                    'type' => 'select',
                    'label' => 'Dispositivo',
                    'required' => true,
                    'options' => $this->dispositivoClienteOptionsForNumeroDispositivo(),
                    'placeholder' => 'Selecciona un dispositivo',
                    'tomSelect' => true,
                    'helpText' => 'Seleccione un dispositivo disponible.',
                ],
                [
                    'name' => 'numeroTelefonico_numeroTelefonico',
                    'type' => 'select',
                    'label' => 'Número telefónico',
                    'required' => true,
                    'options' => $this->numeroTelefonicoOptionsForNumeroDispositivo(),
                    'placeholder' => 'Selecciona un número telefónico',
                    'tomSelect' => true,
                    'helpText' => 'Seleccione un número telefónico asignado a un simcard activo.',
                ],
                [
                    'name' => 'fechaAsignacion',
                    'type' => 'date',
                    'label' => 'Fecha de asignación',
                    'required' => false,
                    'value' => old('fechaAsignacion', Carbon::now()->format('Y-m-d')),
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function numerosDispositivoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dispositivoCliente_iddispositivoCliente' => ['required', 'string', 'exists:dispositivocliente,iddispositivoCliente'],
            'numeroTelefonico_numeroTelefonico' => ['required', 'string', 'exists:numerotelefonico,numeroTelefonico'],
            'fechaAsignacion' => ['nullable', 'date'],
        ]);

        $this->assertNumeroDisponibleParaAsignacionDispositivo($validated['numeroTelefonico_numeroTelefonico']);

        $this->assertNumeroDispositivoPairIsUnique(
            $validated['dispositivoCliente_iddispositivoCliente'],
            $validated['numeroTelefonico_numeroTelefonico']
        );

        $validated['fechaAsignacion'] = self::normalizeFechaAsignacionForStorage($validated['fechaAsignacion'] ?? null)
            ?? Carbon::now()->format('Y-m-d H:i:s');

        $newId = DB::transaction(function () use ($validated): string {
            DB::table('detnumerosdispositivo')->insert($validated);
            return (string) DB::getPdo()->lastInsertId();
        });

        return redirect()
            ->route('modules.lineas-chips.numeros-dispositivo.index')
            ->with('success', 'Número de dispositivo creado correctamente.');
    }

    public function numerosDispositivoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::NUMERO_DISPOSITIVO_LOCK_RESOURCE, (string) $id, 'número de dispositivo', 'modules.lineas-chips.numeros-dispositivo.index')) {
            return $redirect;
        }

        $registro = DB::table('detnumerosdispositivo')->where('iddetNumerosDispositivo', $id)->first();
        if (!$registro) {
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('error', 'No se encontró el número de dispositivo solicitado.');
        }

        if ($this->numeroDispositivoTieneRelaciones($id)) {
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('error', 'No se puede eliminar este número de dispositivo porque tiene relaciones o historial.');
        }

        $relationCount = DB::table('detnumerosdispositivo')
            ->where('dispositivoCliente_iddispositivoCliente', $registro->dispositivoCliente_iddispositivoCliente)
            ->count();

        if ($relationCount > 1) {
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('error', 'No se puede eliminar este número de dispositivo porque el dispositivo ya tiene historial de asignaciones.');
        }

        try {
            DB::table('detnumerosdispositivo')->where('iddetNumerosDispositivo', $id)->delete();
            $this->publishResourceEvent(self::NUMERO_DISPOSITIVO_LOCK_RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::NUMERO_DISPOSITIVO_LOCK_RESOURCE, (string) $id);

            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('success', 'Número de dispositivo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('error', 'No se puede eliminar el número de dispositivo porque tiene registros relacionados.');
        }
    }

    public function numerosDispositivoBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('intval', $selectedIds), fn($id) => $id > 0);
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $id) {
            if ($redirect = $this->assertLockAvailable($request, self::NUMERO_DISPOSITIVO_LOCK_RESOURCE, (string) $id, 'número de dispositivo', 'modules.lineas-chips.numeros-dispositivo.index')) {
                return $redirect;
            }

            $registro = DB::table('detnumerosdispositivo')->where('iddetNumerosDispositivo', $id)->first();
            if (!$registro) {
                return redirect()
                    ->route('modules.lineas-chips.numeros-dispositivo.index')
                    ->with('error', 'No se encontró el número de dispositivo seleccionado.');
            }

            if ($this->numeroDispositivoTieneRelaciones($id)) {
                return redirect()
                    ->route('modules.lineas-chips.numeros-dispositivo.index')
                    ->with('error', 'No se puede eliminar el número de dispositivo ' . $id . ' porque tiene relaciones o historial.');
            }

            $relationCount = DB::table('detnumerosdispositivo')
                ->where('dispositivoCliente_iddispositivoCliente', $registro->dispositivoCliente_iddispositivoCliente)
                ->count();

            if ($relationCount > 1) {
                return redirect()
                    ->route('modules.lineas-chips.numeros-dispositivo.index')
                    ->with('error', 'No se puede eliminar el número de dispositivo ' . $id . ' porque el dispositivo ya tiene historial de asignaciones.');
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('detnumerosdispositivo')
                    ->whereIn('iddetNumerosDispositivo', $selectedIds)
                    ->delete();

                foreach ($selectedIds as $id) {
                    $this->publishResourceEvent(self::NUMERO_DISPOSITIVO_LOCK_RESOURCE, (string) $id, 'deleted');
                    $this->releaseLockIfOwned($request, self::NUMERO_DISPOSITIVO_LOCK_RESOURCE, (string) $id);
                }
            });

            $count = count($selectedIds);
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('success', "Se eliminaron {$count} registro(s) correctamente.");
        } catch (QueryException $e) {
            return redirect()
                ->route('modules.lineas-chips.numeros-dispositivo.index')
                ->with('error', 'No se puede eliminar los registros seleccionados porque tienen registros relacionados.');
        }
    }

    public function numerosDispositivoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = (array) $request->input('selectedIds', []);

        $baseQuery = DB::table('detnumerosdispositivo as d')
            ->leftJoin('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'dc.vehiculo_placa')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'v.cliente_idcliente')
            ->leftJoin('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico');

        $selectedIds = (array) $request->input('selectedIds', []);

        if (!empty($selectedIds)) {
            $baseQuery->whereIn('d.iddetNumerosDispositivo', $selectedIds);
        } else {
            $dispositivoFilter = trim((string) $request->input('dispositivo', ''));
            if ($dispositivoFilter !== '') {
                $baseQuery->where(function ($query) use ($dispositivoFilter) {
                    $term = '%' . $dispositivoFilter . '%';
                    $query
                        ->where('d.dispositivoCliente_iddispositivoCliente', 'like', $term)
                        ->orWhere('dc.vehiculo_placa', 'like', $term)
                        ->orWhere('dc.marcaDispositivo', 'like', $term)
                        ->orWhere('dc.modeloDispositivo', 'like', $term);
                });
            }

            $vehiculoFilter = trim((string) $request->input('vehiculo', ''));
            if ($vehiculoFilter !== '') {
                $baseQuery->where('dc.vehiculo_placa', 'like', '%' . $vehiculoFilter . '%');
            }

            $clienteFilter = trim((string) $request->input('cliente', ''));
            if ($clienteFilter !== '') {
                $baseQuery->where('c.nombreComercial', 'like', '%' . $clienteFilter . '%');
            }

            $numeroTelefonicoFilter = trim((string) $request->input('numeroTelefonico', ''));
            if ($numeroTelefonicoFilter !== '') {
                $baseQuery->where('d.numeroTelefonico_numeroTelefonico', 'like', '%' . $numeroTelefonicoFilter . '%');
            }

            $fechaAsignacionFilter = self::normalizeFechaAsignacionForInput($request->input('fechaAsignacion'));
            if ($fechaAsignacionFilter !== null) {
                $baseQuery->whereDate('d.fechaAsignacion', $fechaAsignacionFilter);
            }

            $search = trim((string) $request->input('q', ''));
            if ($search !== '') {
                $term = '%' . $search . '%';
                $baseQuery->where(function ($query) use ($term) {
                    $query
                        ->where('d.iddetNumerosDispositivo', 'like', $term)
                        ->orWhere('d.dispositivoCliente_iddispositivoCliente', 'like', $term)
                        ->orWhere('dc.vehiculo_placa', 'like', $term)
                        ->orWhere('c.nombreComercial', 'like', $term)
                        ->orWhere('d.numeroTelefonico_numeroTelefonico', 'like', $term)
                        ->orWhere('dc.marcaDispositivo', 'like', $term)
                        ->orWhere('dc.modeloDispositivo', 'like', $term);
                });
            }
        }

        $rows = $baseQuery
            ->select(
                'd.iddetNumerosDispositivo',
                'd.dispositivoCliente_iddispositivoCliente',
                'dc.vehiculo_placa',
                'd.numeroTelefonico_numeroTelefonico',
                'd.fechaAsignacion',
                DB::raw('COALESCE(c.nombreComercial, c.razonSocial, c.idcliente) as nombre_cliente'),
                'n.estado',
            )
            ->orderBy('d.iddetNumerosDispositivo')
            ->get();

        $rows->transform(function ($item) {
            $item->fechaAsignacion = self::normalizeFechaAsignacionForInput($item->fechaAsignacion) ?? '';
            return $item;
        });

        $columns = [
            ['key' => 'iddetNumerosDispositivo', 'label' => 'ID'],
            ['key' => 'dispositivoCliente_iddispositivoCliente', 'label' => 'Dispositivo'],
            ['key' => 'vehiculo_placa', 'label' => 'Placa'],
            ['key' => 'nombre_cliente', 'label' => 'Cliente'],
            ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico'],
            ['key' => 'fechaAsignacion', 'label' => 'Fecha de asignación'],
        ];

        $filename = 'numeros_dispositivo_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Números de dispositivo', $filename);
    }

    public function detallesimcardBulkDeactivate(Request $request): RedirectResponse
    {
        $selectedNumbers = $request->input('selectedNumbers', []);
        if (!is_array($selectedNumbers)) {
            $selectedNumbers = [];
        }

        $manualNumbers = trim((string) $request->input('manualNumbers', ''));
        if ($manualNumbers !== '') {
            $manual = array_filter(
                array_map('trim', explode(',', $manualNumbers)),
                fn($n) => $n !== ''
            );
            $selectedNumbers = array_unique(array_merge($selectedNumbers, $manual));
        }

        if (empty($selectedNumbers)) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se seleccionaron números para dar de baja.');
        }

        $deactivateSimCards = (bool) $request->input('deactivateSimCards', false);

        try {
            $messageParts = [];

            // 1. Desactivar asignaciones relacionadas
            $updatedAssignments = DB::table('detallesimcard')
                ->whereIn('numeroTelefonico_numeroTelefonico', $selectedNumbers)
                ->where('estado', '0')
                ->update(['estado' => '1']);

            if ($updatedAssignments > 0) {
                $messageParts[] = "Se desactivaron $updatedAssignments asignaciones";
            }

            // 2. Desactivar números telefónicos
            $updatedNumbers = DB::table('numerotelefonico')
                ->whereIn('numeroTelefonico', $selectedNumbers)
                ->where('estado', '1')
                ->update(['estado' => '0']);

            if ($updatedNumbers > 0) {
                $messageParts[] = "Se desactivaron $updatedNumbers números";
            }

            // 3. Si el usuario eligió desactivar SIM cards también
            if ($deactivateSimCards) {
                // Encontrar los SIM cards relacionados con estos números
                $simCardsToDeactivate = DB::table('detallesimcard')
                    ->whereIn('numeroTelefonico_numeroTelefonico', $selectedNumbers)
                    ->where('estado', '1')
                    ->distinct()
                    ->pluck('simCard_idsimCard');

                if ($simCardsToDeactivate->count() > 0) {
                    $updatedSimCards = DB::table('simcard')
                        ->whereIn('idsimCard', $simCardsToDeactivate)
                        ->where('estado', '1')
                        ->update(['estado' => '0']);

                    if ($updatedSimCards > 0) {
                        $messageParts[] = "Se desactivaron $updatedSimCards simcards";
                    }
                }
            }

            $totalUpdated = $updatedAssignments + $updatedNumbers + ($deactivateSimCards ? ($updatedSimCards ?? 0) : 0);

            if ($totalUpdated > 0) {
                return redirect()
                    ->route('modules.lineas-chips.detallesimcard.index')
                    ->with('success', implode(' y ', $messageParts) . ' correctamente.');
            }

            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'No se encontraron asignaciones ni números activos para desactivar.');
        } catch (\Exception $e) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'Error al desactivar: ' . $e->getMessage());
        }
    }

    public function detallesimcardBulkDeactivateParseFile(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx|max:5120',
        ], [
            'file.mimes' => 'Solo se aceptan archivos XLSX.',
            'file.max' => 'El archivo no puede exceder 10MB.',
        ]);

        try {
            $file = $request->file('file');
            $records = [];
            $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));

            if ($extension === 'xlsx') {
                if (!class_exists(IOFactory::class)) {
                    throw new \RuntimeException('La librería necesaria para procesar .xlsx no está instalada.');
                }
                $spreadsheet = IOFactory::load($file->getRealPath());
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
                foreach ($rows as $row) {
                    $columns = array_map('trim', array_values($row));
                    if (count(array_filter($columns, fn($value) => $value !== '')) === 0) {
                        $records[] = [''];
                        continue;
                    }
                    $records[] = $columns;
                }
            }

            if (empty($records)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no contiene números válidos.',
                ], 422);
            }

            // Detectar cabecera si el primer registro parece encabezado
            $headerRow = array_map('mb_strtolower', $records[0] ?? []);
            $hasHeader = in_array('numero', $headerRow, true) || in_array('número', $headerRow, true);
            if ($hasHeader) {
                array_shift($records);
            }

            $seenInFile = [];
            $lineNumber = 1;
            $totalRows = 0;
            $validRows = 0;
            $emptyCount = 0;
            $invalidCount = 0;
            $fileDuplicates = 0;
            $inactiveCount = 0; // números que existen pero ya inactivos
            $missingCount = 0; // números que no existen en la tabla numerotelefonico
            $previewRows = [];
            $eligibleNumbers = [];

            foreach ($records as $parts) {
                $numero = trim((string) ($parts[0] ?? ''));
                $rawNumero = trim(str_replace(' ', '', $numero));

                if ($rawNumero === '') {
                    $emptyCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => '',
                        'status' => 'Vacío / Inválido',
                    ];
                    $lineNumber++;
                    continue;
                }

                if (!preg_match('/^\d+$/', $rawNumero)) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $rawNumero,
                        'status' => 'Vacío / Inválido',
                    ];
                    $lineNumber++;
                    continue;
                }

                $totalRows++;
                if (isset($seenInFile[$rawNumero])) {
                    $fileDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $rawNumero,
                        'status' => 'Duplicado en archivo',
                    ];
                    $lineNumber++;
                    continue;
                }
                $seenInFile[$rawNumero] = true;

                $numeroInfo = DB::table('numerotelefonico as n')
                    ->leftJoin('detallesimcard as d', function ($join) {
                        $join->on('d.numeroTelefonico_numeroTelefonico', '=', 'n.numeroTelefonico')
                            ->where('d.estado', '0');
                    })
                    ->leftJoin('simcard as s', 's.idsimCard', '=', 'd.simCard_idsimCard')
                    ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador')
                    ->where('n.numeroTelefonico', $rawNumero)
                    ->select('n.estado as numero_estado', 'd.simCard_idsimCard as simcard', 'o.nombre as operador')
                    ->first();

                // Si no existe el número en la tabla
                if (!$numeroInfo) {
                    $missingCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $rawNumero,
                        'status' => 'No existe en la DB',
                    ];
                    $lineNumber++;
                    continue;
                }

                // Si el número existe pero está inactivo (estado '0') -> ya está bajado
                if ((string) ($numeroInfo->numero_estado ?? '') === '0') {
                    $inactiveCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $rawNumero,
                        'status' => 'Dado de baja',
                    ];
                    $lineNumber++;
                    continue;
                }

                // Caso válido para baja
                $validRows++;
                $eligibleNumbers[] = $rawNumero;
                $previewRows[] = [
                    'line' => $lineNumber,
                    'numero' => $rawNumero,
                    'status' => 'Listo a dar de baja',
                ];
                $lineNumber++;
            }

            $preview = [
                'candidateCount' => $totalRows,
                'newRows' => $validRows,
                // Un solo contador visible para vacíos e inválidos
                'emptyInvalidRows' => $emptyCount + $invalidCount,
                'inactiveRows' => $inactiveCount,
                'missingRows' => $missingCount,
                'fileDuplicateRows' => $fileDuplicates,
                'previewRows' => array_slice($previewRows, 0, 10),
                'allRows' => $previewRows,
                'eligibleNumbers' => $eligibleNumbers,
            ];

            if ($totalRows === 0 && $emptyCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no contiene números válidos.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'preview' => $preview,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
            ], 400);
        }
    }

    public function detallesimcardImportPreview(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'importFile' => 'required|file|mimes:xlsx|max:5120',
        ], [
            'importFile.mimes' => 'Solo se aceptan archivos tipo xlsx.',
        ]);

        try {
            $file = $request->file('importFile');
            $previewRows = [];
            $totalRows = 0;
            $newRows = 0;
            $emptyCount = 0;
            $invalidCount = 0;
            $fileDuplicates = 0;
            $dbDuplicates = 0;

            // Leer el archivo
            $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));
            $records = [];

            if ($extension === 'xlsx') {
                if (!class_exists(IOFactory::class)) {
                    throw new \RuntimeException('La librería necesaria para procesar .xlsx no está instalada.');
                }
                $spreadsheet = IOFactory::load($file->getRealPath());
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
                foreach ($rows as $row) {
                    $columns = array_map('trim', array_values($row));
                    if (count(array_filter($columns, fn($value) => $value !== '')) === 0) {
                        continue;
                    }
                    $records[] = $columns;
                }
            }

            // Detectar cabecera si el primer registro parece panel de columnas
            $columnIndexes = [
                'numero' => 0,
                'simcard' => 1,
                'operador' => 2,
            ];

            if (!empty($records)) {
                $firstRow = array_map(fn($value) => mb_strtolower(trim((string) $value)), $records[0]);
                $isHeaderRow = false;
                $headerKeywords = ['simcard', 'simcards', 'sim card', 'operador', 'operadores', 'operator', 'numero', 'número', 'numeros', 'números', 'telefono', 'teléfono', 'telefonos', 'teléfonos'];
                foreach ($firstRow as $header) {
                    if (in_array($header, $headerKeywords, true)) {
                        $isHeaderRow = true;
                        break;
                    }
                }

                if ($isHeaderRow) {
                    $headerMap = array_flip($firstRow);
                    $columnIndexes['simcard'] = $headerMap['simcard'] ?? $headerMap['simcards'] ?? $headerMap['sim card'] ?? $columnIndexes['simcard'];
                    $columnIndexes['numero'] = $headerMap['numero'] ?? $headerMap['número'] ?? $headerMap['numeros'] ?? $headerMap['números'] ?? $headerMap['telefono'] ?? $headerMap['teléfono'] ?? $columnIndexes['numero'];
                    $columnIndexes['operador'] = $headerMap['operador'] ?? $headerMap['operadores'] ?? $headerMap['operator'] ?? $columnIndexes['operador'];
                    array_shift($records);
                }
            }

            $operatorNames = [];
            $simcards = [];
            $numeros = [];
            foreach ($records as $parts) {
                $simcard = trim((string) ($parts[$columnIndexes['simcard']] ?? ''));
                $operatorName = trim((string) ($parts[$columnIndexes['operador']] ?? ''));
                $numero = trim((string) ($parts[$columnIndexes['numero']] ?? ''));

                if ($simcard !== '') {
                    $simcards[$simcard] = true;
                }
                if ($numero !== '') {
                    $numeros[$numero] = true;
                }
                if ($operatorName !== '') {
                    $operatorNames[mb_strtolower($operatorName)] = true;
                }
            }

            $operatorMap = [];
            if (!empty($operatorNames)) {
                $lowerOperatorNames = array_keys($operatorNames);
                $operators = DB::table('operador')
                    ->select('idoperador', 'nombre')
                    ->whereIn(DB::raw('LOWER(nombre)'), $lowerOperatorNames)
                    ->get();

                foreach ($operators as $operator) {
                    $operatorMap[mb_strtolower(trim((string) $operator->nombre))] = (int) $operator->idoperador;
                }
            }

            $simExistsSet = [];
            if (!empty($simcards)) {
                $simExists = DB::table('simcard')
                    ->whereIn('idsimCard', array_keys($simcards))
                    ->pluck('idsimCard')
                    ->all();
                foreach ($simExists as $simId) {
                    $simExistsSet[(string) $simId] = true;
                }
            }

            $numExistsSet = [];
            if (!empty($numeros)) {
                $numeroExists = DB::table('numerotelefonico')
                    ->whereIn('numeroTelefonico', array_keys($numeros))
                    ->pluck('numeroTelefonico')
                    ->all();
                foreach ($numeroExists as $num) {
                    $numExistsSet[(string) $num] = true;
                }
            }

            $pairExistsSet = [];
            if (!empty($simcards) && !empty($numeros)) {
                $pairs = DB::table('detallesimcard')
                    ->select('simCard_idsimCard', 'numeroTelefonico_numeroTelefonico')
                    ->whereIn('simCard_idsimCard', array_keys($simcards))
                    ->whereIn('numeroTelefonico_numeroTelefonico', array_keys($numeros))
                    ->get();
                foreach ($pairs as $pair) {
                    $pairExistsSet[trim((string) $pair->simCard_idsimCard) . '|' . trim((string) $pair->numeroTelefonico_numeroTelefonico)] = true;
                }
            }

            $defaultOperatorId = $this->getDefaultOperatorId();
            $seenInFile = [];
            $lineNumber = 1;

            foreach ($records as $parts) {
                $simcard = trim((string) ($parts[$columnIndexes['simcard']] ?? ''));
                $operatorName = trim((string) ($parts[$columnIndexes['operador']] ?? ''));
                $numero = trim((string) ($parts[$columnIndexes['numero']] ?? ''));
                $extraValues = array_slice($parts, max($columnIndexes['numero'], $columnIndexes['operador'], $columnIndexes['simcard']) + 1);
                $hasExtraData = count(array_filter($extraValues, fn($value) => $value !== '')) > 0;

                if ($simcard === '' || $numero === '') {
                    $emptyCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'numero' => $numero,
                        'status' => 'Fila vacía o incompleta',
                        'importable' => false,
                    ];
                    $lineNumber++;
                    continue;
                }

                if ($hasExtraData) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'numero' => $numero,
                        'status' => 'Inválido (columnas extras)',
                        'importable' => false,
                    ];
                    $lineNumber++;
                    continue;
                }

                $totalRows++;
                $pairKey = "$simcard|$numero";
                if (isset($seenInFile[$pairKey])) {
                    $fileDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'numero' => $numero,
                        'status' => 'Duplicado en archivo',
                        'importable' => false,
                    ];
                    $lineNumber++;
                    continue;
                }
                $seenInFile[$pairKey] = true;

                $operatorId = null;
                if ($operatorName !== '') {
                    $operatorId = $operatorMap[mb_strtolower($operatorName)] ?? null;
                }
                if ($operatorId === null) {
                    $operatorId = $defaultOperatorId;
                }

                if ($operatorId === null) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'numero' => $numero,
                        'status' => 'Inválido (sin operador en BD)',
                        'importable' => false,
                    ];
                    $lineNumber++;
                    continue;
                }

                $pairExists = isset($pairExistsSet[$pairKey]);
                $simExists = isset($simExistsSet[$simcard]);
                $numExists = isset($numExistsSet[$numero]);

                if ($pairExists || $simExists || $numExists) {
                    $dbDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'numero' => $numero,
                        'status' => 'Existe en BD',
                        'importable' => false,
                    ];
                    $lineNumber++;
                    continue;
                }

                $newRows++;
                $previewRows[] = [
                    'line' => $lineNumber,
                    'simcard' => $simcard,
                    'operador' => $operatorName,
                    'numero' => $numero,
                    'status' => 'Nuevo',
                    'importable' => true,
                    'operatorId' => $operatorId,
                ];
                $lineNumber++;
            }

            $token = hash('sha256', microtime() . random_bytes(16));

            $preview = [
                'candidateCount' => $totalRows,
                'newRows' => $newRows,
                'emptyRows' => $emptyCount,
                'invalidRows' => $invalidCount,
                'fileDuplicateRows' => $fileDuplicates,
                'duplicateExistingRows' => $dbDuplicates,
                'previewRows' => array_slice($previewRows, 0, 10),
                'allRows' => $previewRows,
                'token' => $token,
            ];

            $this->storeImportPreview('detallesimcard', $preview);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Archivo validado correctamente. Revise la previsualización y confirme la carga.',
                    'preview' => $preview,
                ]);
            }

            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with([
                    'detallesimcardImportPreview' => $preview,
                    'showImportModal' => true,
                ]);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es válido. Revisa el contenido y vuelve a intentarlo.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->withErrors($e->errors())
                ->with('error', 'El archivo no es válido. Revisa el contenido y vuelve a intentarlo.')
                ->with('showImportModal', true);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'Error al procesar el archivo: ' . $e->getMessage());
        }
    }

    private function getDefaultOperatorId(): ?int
    {
        return DB::table('operador')->orderBy('idoperador')->value('idoperador');
    }

    public function detallesimcardImportProcess(Request $request): RedirectResponse
    {
        $importToken = trim((string) $request->input('importToken', ''));
        $preview = $this->loadImportPreview('detallesimcard', $importToken);

        if (empty($preview) || ($preview['token'] ?? '') !== $importToken) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'Token de importación inválido o expirado.');
        }

        try {
            $processedCount = 0;
            DB::transaction(function () use ($preview, &$processedCount) {
                $defaultOperatorId = $this->getDefaultOperatorId();
                $simcardRows = [];
                $numeroRows = [];
                $detalleRows = [];

                foreach ($preview['allRows'] ?? [] as $row) {
                    if (empty($row['importable'])) {
                        continue;
                    }

                    $simcard = trim((string) ($row['simcard'] ?? ''));
                    $numero = trim((string) ($row['numero'] ?? ''));
                    $operatorId = isset($row['operatorId']) ? (int) $row['operatorId'] : $defaultOperatorId;
                    if ($simcard === '' || $numero === '') {
                        continue;
                    }

                    if ($operatorId === 0) {
                        continue;
                    }

                    $simcardRows[] = [
                        'idsimCard' => $simcard,
                        'estado' => '1',
                        'operador_idoperador' => $operatorId,
                    ];
                    $numeroRows[] = [
                        'numeroTelefonico' => $numero,
                        'estado' => '1',
                    ];
                    $detalleRows[] = [
                        'simCard_idsimCard' => $simcard,
                        'numeroTelefonico_numeroTelefonico' => $numero,
                        'fechaAsignacion' => Carbon::now()->format('Y-m-d H:i:s'),
                        'estado' => '0',
                    ];
                }

                foreach (array_chunk($simcardRows, 500) as $chunk) {
                    DB::table('simcard')->insertOrIgnore($chunk);
                }
                foreach (array_chunk($numeroRows, 500) as $chunk) {
                    DB::table('numerotelefonico')->insertOrIgnore($chunk);
                }
                foreach (array_chunk($detalleRows, 500) as $chunk) {
                    DB::table('detallesimcard')->insertOrIgnore($chunk);
                }

                $processedCount = count($detalleRows);
            });

            $this->forgetImportPreview('detallesimcard', $importToken);

            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('success', "Se importaron $processedCount asignaciones correctamente.");
        } catch (\Exception $e) {
            return redirect()
                ->route('modules.lineas-chips.detallesimcard.index')
                ->with('error', 'Error al importar: ' . $e->getMessage());
        }
    }

    public function numerosTelefonicoImportPreview(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'importFile' => 'required|file|mimes:xlsx|max:5120',
        ], [
            'importFile.mimes' => 'Solo se aceptan archivos tipo xlsx.',
        ]);

        try {
            $file = $request->file('importFile');
            $previewRows = [];
            $totalRows = 0;
            $newRows = 0;
            $emptyCount = 0;
            $invalidCount = 0;
            $fileDuplicates = 0;
            $dbDuplicates = 0;

            $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));
            $records = [];

            if ($extension === 'xlsx') {
                if (!class_exists(IOFactory::class)) {
                    throw new \RuntimeException('La librería necesaria para procesar .xlsx no está instalada.');
                }
                $spreadsheet = IOFactory::load($file->getRealPath());
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
                foreach ($rows as $row) {
                    $columns = array_map('trim', array_values($row));
                    if (count(array_filter($columns, fn($value) => $value !== '')) === 0) {
                        continue;
                    }
                    $records[] = $columns;
                }
            }

            $numeroColIndex = 0;
            $planColIndex = 1;
            $precioColIndex = 2;
            if (!empty($records)) {
                $firstRow = array_map(fn($value) => mb_strtolower(trim((string) $value)), $records[0]);
                $isHeaderRow = false;
                foreach ($firstRow as $idx => $header) {
                    if (
                        in_array($header, [
                            'numero',
                            'número',
                            'numeros',
                            'números',
                            'telefono',
                            'teléfono',
                            'telefonos',
                            'teléfonos',
                            'numerotelefonico',
                            'númerotelefonico',
                            'numero telefonico',
                            'número telefónico',
                            'celular',
                            'celulares',
                            'linea',
                            'línea',
                            'lineas',
                            'líneas',
                            'movil',
                            'móvil',
                            'num',
                            'n°',
                            'no'
                        ], true)
                    ) {
                        $numeroColIndex = $idx;
                        $isHeaderRow = true;
                    }
                    if (in_array($header, ['plan', 'planes', 'nombre plan'], true)) {
                        $planColIndex = $idx;
                        $isHeaderRow = true;
                    }
                    if (in_array($header, ['precio', 'precios', 'valor', 'importe'], true)) {
                        $precioColIndex = $idx;
                        $isHeaderRow = true;
                    }
                }

                $firstVal = $firstRow[$numeroColIndex] ?? $firstRow[0] ?? '';
                if (!$isHeaderRow && $firstVal !== '' && (!is_numeric($firstVal) || preg_match('/[a-zA-Z]/', $firstVal))) {
                    $isHeaderRow = true;
                }

                if ($isHeaderRow) {
                    array_shift($records);
                }
            }

            $numeros = [];
            foreach ($records as $parts) {
                $numero = trim((string) ($parts[$numeroColIndex] ?? ''));
                if ($numero !== '') {
                    $numeros[$numero] = true;
                }
            }

            $numExistsSet = [];
            if (!empty($numeros)) {
                $numeroExists = DB::table('numerotelefonico')
                    ->whereIn('numeroTelefonico', array_keys($numeros))
                    ->pluck('numeroTelefonico')
                    ->all();
                foreach ($numeroExists as $num) {
                    $numExistsSet[(string) $num] = true;
                }
            }

            $seenInFile = [];
            $lineNumber = 1;

            foreach ($records as $parts) {
                $numero = trim((string) ($parts[$numeroColIndex] ?? ''));
                $plan = trim((string) ($parts[$planColIndex] ?? ''));
                $precioRaw = trim((string) ($parts[$precioColIndex] ?? ''));

                if ($numero === '' || $plan === '' || $precioRaw === '') {
                    $emptyCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $numero,
                        'plan' => $plan,
                        'precio' => $precioRaw,
                        'moneda' => 'S/',
                        'status' => 'Fila vacía o incompleta (Numero, Plan y Precio son obligatorios)',
                        'importable' => false,
                        'importType' => 'numero',
                    ];
                    $lineNumber++;
                    continue;
                }

                if (mb_strlen($numero) < 9 || mb_strlen($numero) > 30 || !preg_match(self::SAFE_TEXT_REGEX, $numero)) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $numero,
                        'plan' => $plan,
                        'precio' => $precioRaw,
                        'moneda' => 'S/',
                        'status' => 'Inválido (formato o longitud de número incorrecto)',
                        'importable' => false,
                        'importType' => 'numero',
                    ];
                    $lineNumber++;
                    continue;
                }

                [$precio, $moneda] = $this->normalizeNumeroPrecio($precioRaw);
                if ($precio === null) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $numero,
                        'plan' => $plan,
                        'precio' => $precioRaw,
                        'moneda' => $moneda,
                        'status' => 'Inválido (precio no numérico)',
                        'importable' => false,
                        'importType' => 'numero',
                    ];
                    $lineNumber++;
                    continue;
                }

                $totalRows++;

                if (isset($seenInFile[$numero])) {
                    $fileDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $numero,
                        'plan' => $plan,
                        'precio' => $precio,
                        'moneda' => $moneda,
                        'status' => 'Duplicado en archivo',
                        'importable' => false,
                        'importType' => 'numero',
                    ];
                    $lineNumber++;
                    continue;
                }
                $seenInFile[$numero] = true;

                if (isset($numExistsSet[$numero])) {
                    $dbDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'numero' => $numero,
                        'plan' => $plan,
                        'precio' => $precio,
                        'moneda' => $moneda,
                        'status' => 'Existe en BD',
                        'importable' => false,
                        'importType' => 'numero',
                    ];
                    $lineNumber++;
                    continue;
                }

                $newRows++;
                $previewRows[] = [
                    'line' => $lineNumber,
                    'numero' => $numero,
                    'plan' => $plan,
                    'precio' => $precio,
                    'moneda' => $moneda,
                    'status' => 'Nuevo',
                    'importable' => true,
                    'importType' => 'numero',
                ];
                $lineNumber++;
            }

            $token = hash('sha256', microtime() . random_bytes(16));
            $processRoute = route('modules.lineas-chips.numeros-telefonico.import.process');

            $preview = [
                'importType' => 'numero',
                'buttonLabel' => 'Cargar números',
                'processRoute' => $processRoute,
                'csrfToken' => csrf_token(),
                'candidateCount' => $totalRows,
                'newRows' => $newRows,
                'emptyRows' => $emptyCount,
                'invalidRows' => $invalidCount,
                'fileDuplicateRows' => $fileDuplicates,
                'duplicateExistingRows' => $dbDuplicates,
                'previewRows' => array_slice($previewRows, 0, 10),
                'allRows' => $previewRows,
                'token' => $token,
            ];

            $this->storeImportPreview('numero', $preview);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Archivo validado correctamente. Revise la previsualización y confirme la carga.',
                    'preview' => $preview,
                ]);
            }

            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with([
                    'importPreview' => $preview,
                    'showImportModal' => true,
                ]);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es válido. Revisa el contenido y vuelve a intentarlo.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->withErrors($e->errors())
                ->with('error', 'El archivo no es válido. Revisa el contenido y vuelve a intentarlo.')
                ->with('showImportModal', true);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'Error al procesar el archivo: ' . $e->getMessage());
        }
    }

    public function numerosTelefonicoImportProcess(Request $request): RedirectResponse
    {
        $importToken = trim((string) $request->input('importToken', ''));
        $preview = $this->loadImportPreview('numero', $importToken);

        if (empty($preview) || ($preview['token'] ?? '') !== $importToken) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'Token de importación inválido o expirado.');
        }

        try {
            $processedCount = 0;
            DB::transaction(function () use ($preview, &$processedCount) {
                $numeroRows = [];
                $precioRows = [];

                foreach ($preview['allRows'] ?? [] as $row) {
                    if (empty($row['importable'])) {
                        continue;
                    }

                    $numero = trim((string) ($row['numero'] ?? ''));
                    $plan = trim((string) ($row['plan'] ?? ''));
                    $precioRaw = trim((string) ($row['precio'] ?? ''));
                    if ($numero === '') {
                        continue;
                    }

                    [$precio, $moneda] = $this->resolveNumeroImportPrecio(
                        $precioRaw,
                        (string) ($row['moneda'] ?? '')
                    );
                    if ($plan === '' || $precio === null) {
                        continue;
                    }

                    $numeroRows[] = [
                        'numeroTelefonico' => $numero,
                        'plan' => $plan,
                        'estado' => '1',
                    ];
                    $precioRows[] = [
                        'numeroTelefonico_numeroTelefonico' => $numero,
                        'precio' => $precio,
                        'moneda' => $moneda,
                        'fecha' => Carbon::now()->format('Y-m-d H:i:s'),
                    ];
                }

                foreach (array_chunk($numeroRows, 500) as $chunk) {
                    DB::table('numerotelefonico')->insertOrIgnore($chunk);
                }
                foreach (array_chunk($precioRows, 500) as $chunk) {
                    DB::table('detalle_precionumero')->insertOrIgnore($chunk);
                }

                $processedCount = count($numeroRows);
            });

            $this->publishResourceEvent('lineas_chips.numero_telefonico', '*', 'created');
            $this->forgetImportPreview('numero', $importToken);

            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('success', "Se importaron $processedCount números telefónicos correctamente.");
        } catch (\Exception $e) {
            return redirect()
                ->route('modules.lineas-chips.numeros-telefonico.index')
                ->with('error', 'Error al importar: ' . $e->getMessage());
        }
    }

    public function simcardImportPreview(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'importFile' => 'required|file|mimes:xlsx|max:5120',
        ], [
            'importFile.mimes' => 'Solo se aceptan archivos tipo xlsx.',
        ]);

        try {
            $file = $request->file('importFile');
            $previewRows = [];
            $totalRows = 0;
            $newRows = 0;
            $emptyCount = 0;
            $invalidCount = 0;
            $fileDuplicates = 0;
            $dbDuplicates = 0;

            $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));
            $records = [];

            if ($extension === 'xlsx') {
                if (!class_exists(IOFactory::class)) {
                    throw new \RuntimeException('La librería necesaria para procesar .xlsx no está instalada.');
                }
                $spreadsheet = IOFactory::load($file->getRealPath());
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
                foreach ($rows as $row) {
                    $columns = array_map('trim', array_values($row));
                    if (count(array_filter($columns, fn($value) => $value !== '')) === 0) {
                        continue;
                    }
                    $records[] = $columns;
                }
            }

            $columnIndexes = [
                'simcard' => 0,
                'operador' => 1,
            ];

            if (!empty($records)) {
                $firstRow = array_map(fn($value) => mb_strtolower(trim((string) $value)), $records[0]);
                $isHeaderRow = false;
                $headerKeywords = ['simcard', 'simcards', 'idsimcard', 'sim card', 'sim', 'iccid', 'operador', 'operadores', 'operator', 'chip', 'chips'];
                foreach ($firstRow as $header) {
                    if (in_array($header, $headerKeywords, true)) {
                        $isHeaderRow = true;
                        break;
                    }
                }

                $firstSim = $firstRow[$columnIndexes['simcard']] ?? $firstRow[0] ?? '';
                if (!$isHeaderRow && $firstSim !== '' && (in_array($firstSim, $headerKeywords, true) || !is_numeric($firstSim))) {
                    $isHeaderRow = true;
                }

                if ($isHeaderRow) {
                    $headerMap = array_flip($firstRow);
                    $columnIndexes['simcard'] = $headerMap['simcard'] ?? $headerMap['idsimcard'] ?? $headerMap['sim card'] ?? $headerMap['simcards'] ?? $columnIndexes['simcard'];
                    $columnIndexes['operador'] = $headerMap['operador'] ?? $headerMap['operadores'] ?? $headerMap['operator'] ?? $columnIndexes['operador'];
                    array_shift($records);
                }
            }

            $operatorNames = [];
            $simcards = [];
            foreach ($records as $parts) {
                $simcard = trim((string) ($parts[$columnIndexes['simcard']] ?? ''));
                $operatorName = trim((string) ($parts[$columnIndexes['operador']] ?? ''));

                if ($simcard !== '') {
                    $simcards[$simcard] = true;
                }
                if ($operatorName !== '') {
                    $operatorNames[mb_strtolower($operatorName)] = true;
                }
            }

            $operatorMap = [];
            if (!empty($operatorNames)) {
                $lowerOperatorNames = array_keys($operatorNames);
                $operators = DB::table('operador')
                    ->select('idoperador', 'nombre')
                    ->whereIn(DB::raw('LOWER(nombre)'), $lowerOperatorNames)
                    ->get();

                foreach ($operators as $operator) {
                    $operatorMap[mb_strtolower(trim((string) $operator->nombre))] = (int) $operator->idoperador;
                }
            }

            $simExistsSet = [];
            if (!empty($simcards)) {
                $simExists = DB::table('simcard')
                    ->whereIn('idsimCard', array_keys($simcards))
                    ->pluck('idsimCard')
                    ->all();
                foreach ($simExists as $simId) {
                    $simExistsSet[(string) $simId] = true;
                }
            }

            $defaultOperatorId = $this->getDefaultOperatorId();
            $seenInFile = [];
            $lineNumber = 1;

            foreach ($records as $parts) {
                $simcard = trim((string) ($parts[$columnIndexes['simcard']] ?? ''));
                $operatorName = trim((string) ($parts[$columnIndexes['operador']] ?? ''));

                if ($simcard === '') {
                    $emptyCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'status' => 'Fila vacía o incompleta',
                        'importable' => false,
                        'importType' => 'simcard',
                    ];
                    $lineNumber++;
                    continue;
                }

                if (mb_strlen($simcard) < 10 || mb_strlen($simcard) > 50 || !preg_match(self::SAFE_TEXT_REGEX, $simcard)) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'status' => 'Inválido (formato o longitud de SimCard incorrecto)',
                        'importable' => false,
                        'importType' => 'simcard',
                    ];
                    $lineNumber++;
                    continue;
                }

                $totalRows++;

                if (isset($seenInFile[$simcard])) {
                    $fileDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'status' => 'Duplicado en archivo',
                        'importable' => false,
                        'importType' => 'simcard',
                    ];
                    $lineNumber++;
                    continue;
                }
                $seenInFile[$simcard] = true;

                $operatorId = null;
                if ($operatorName !== '') {
                    $operatorId = $operatorMap[mb_strtolower($operatorName)] ?? null;
                }
                if ($operatorId === null) {
                    $operatorId = $defaultOperatorId;
                }

                if ($operatorId === null) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'status' => 'Inválido (sin operador en BD)',
                        'importable' => false,
                        'importType' => 'simcard',
                    ];
                    $lineNumber++;
                    continue;
                }

                if (isset($simExistsSet[$simcard])) {
                    $dbDuplicates++;
                    $previewRows[] = [
                        'line' => $lineNumber,
                        'simcard' => $simcard,
                        'operador' => $operatorName,
                        'status' => 'Existe en BD',
                        'importable' => false,
                        'importType' => 'simcard',
                    ];
                    $lineNumber++;
                    continue;
                }

                $newRows++;
                $previewRows[] = [
                    'line' => $lineNumber,
                    'simcard' => $simcard,
                    'operador' => $operatorName,
                    'status' => 'Nuevo',
                    'importable' => true,
                    'importType' => 'simcard',
                    'operatorId' => $operatorId,
                ];
                $lineNumber++;
            }

            $token = hash('sha256', microtime() . random_bytes(16));
            $processRoute = route('modules.lineas-chips.simcard.import.process');

            $preview = [
                'importType' => 'simcard',
                'buttonLabel' => 'Cargar SimCards',
                'processRoute' => $processRoute,
                'csrfToken' => csrf_token(),
                'candidateCount' => $totalRows,
                'newRows' => $newRows,
                'emptyRows' => $emptyCount,
                'invalidRows' => $invalidCount,
                'fileDuplicateRows' => $fileDuplicates,
                'duplicateExistingRows' => $dbDuplicates,
                'previewRows' => array_slice($previewRows, 0, 10),
                'allRows' => $previewRows,
                'token' => $token,
            ];

            $this->storeImportPreview('simcard', $preview);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Archivo validado correctamente. Revise la previsualización y confirme la carga.',
                    'preview' => $preview,
                ]);
            }

            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with([
                    'importPreview' => $preview,
                    'showImportModal' => true,
                ]);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es válido. Revisa el contenido y vuelve a intentarlo.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->withErrors($e->errors())
                ->with('error', 'El archivo no es válido. Revisa el contenido y vuelve a intentarlo.')
                ->with('showImportModal', true);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'Error al procesar el archivo: ' . $e->getMessage());
        }
    }

    public function simcardImportProcess(Request $request): RedirectResponse
    {
        $importToken = trim((string) $request->input('importToken', ''));
        $preview = $this->loadImportPreview('simcard', $importToken);

        if (empty($preview) || ($preview['token'] ?? '') !== $importToken) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'Token de importación inválido o expirado.');
        }

        try {
            $processedCount = 0;
            DB::transaction(function () use ($preview, &$processedCount) {
                $defaultOperatorId = $this->getDefaultOperatorId();
                $simcardRows = [];

                foreach ($preview['allRows'] ?? [] as $row) {
                    if (empty($row['importable'])) {
                        continue;
                    }

                    $simcard = trim((string) ($row['simcard'] ?? ''));
                    $operatorId = isset($row['operatorId']) ? (int) $row['operatorId'] : $defaultOperatorId;
                    if ($simcard === '' || $operatorId === 0) {
                        continue;
                    }

                    $simcardRows[] = [
                        'idsimCard' => $simcard,
                        'estado' => '1',
                        'operador_idoperador' => $operatorId,
                    ];
                }

                foreach (array_chunk($simcardRows, 500) as $chunk) {
                    DB::table('simcard')->insertOrIgnore($chunk);
                }
                $processedCount = count($simcardRows);
            });

            $this->publishResourceEvent('lineas_chips.simcard', '*', 'created');
            $this->forgetImportPreview('simcard', $importToken);

            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('success', "Se importaron $processedCount SimCards correctamente.");
        } catch (\Exception $e) {
            return redirect()
                ->route('modules.lineas-chips.simcard.index')
                ->with('error', 'Error al importar: ' . $e->getMessage());
        }
    }

    private function storeImportPreview(string $type, array $preview): void
    {
        $token = trim((string) ($preview['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new \RuntimeException('Token de importación inválido.');
        }

        Storage::disk('local')->put(
            "import-previews/{$type}/{$token}.json",
            json_encode($preview, JSON_THROW_ON_ERROR)
        );
    }

    private function loadImportPreview(string $type, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $path = "import-previews/{$type}/{$token}.json";
        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $preview = json_decode(Storage::disk('local')->get($path), true);

        return is_array($preview) ? $preview : null;
    }

    private function forgetImportPreview(string $type, string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            Storage::disk('local')->delete("import-previews/{$type}/{$token}.json");
        }
    }

    /**
     * @return array<string, string>
     */
    private function operadorOptions(): array
    {
        return DB::table('operador')
            ->select('idoperador', 'nombre')
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(function ($row): array {
                $label = trim((string) ($row->nombre ?? ''));
                $display = $label !== '' ? ($label) : (string) $row->idoperador;
                return [(string) $row->idoperador => $display];
            })
            ->all();
    }

    private function countNumeroHistorialSinRelacionActual(string $numeroTelefonico): int
    {
        $hasSimcardRelation = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->exists();
        $hasDeviceRelation = DB::table('detnumerosdispositivo')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->exists();

        return ($hasSimcardRelation || $hasDeviceRelation) ? 1 : 0;
    }

    private function countSimCardHistorialSinRelacionActual(string $simCardId): int
    {
        return DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->count();
    }

    private function buildNumeroRelacionTexto(string $numeroTelefonico): string
    {
        $detalleActual = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if ($detalleActual) {
            $texto = 'Asignación actual con SimCard: ' . (string) $detalleActual->simCard_idsimCard;

            $dispositivo = $this->resolveDispositivoActualPorNumero($numeroTelefonico);
            if ($dispositivo !== null) {
                $texto .= '; Dispositivo actual: ' . $dispositivo;
            }

            return $texto;
        }

        $detallePasado = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '1')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$detallePasado) {
            return 'Sin relación.';
        }

        return 'Última Asignación con SimCard: ' . (string) $detallePasado->simCard_idsimCard;
    }

    private function buildSimCardRelacionTexto(string $simCardId): string
    {
        $detalleActual = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if ($detalleActual) {
            $numero = (string) $detalleActual->numeroTelefonico_numeroTelefonico;
            $texto = 'Asignación actual con número: ' . $numero;

            $dispositivo = $this->resolveDispositivoActualPorNumero($numero);
            if ($dispositivo !== null) {
                $texto .= '; Dispositivo actual: ' . $dispositivo;
            }

            return $texto;
        }

        $detallePasado = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '1')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$detallePasado) {
            return 'Sin relación.';
        }

        return 'Última Asignación con número: ' . (string) $detallePasado->numeroTelefonico_numeroTelefonico;
    }

    private function buildNumeroRelacionActualTexto(string $numeroTelefonico): string
    {
        $detalle = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$detalle) {
            return 'Sin Asignación activa.';
        }

        $texto = 'Asignación actual con SimCard: ' . (string) $detalle->simCard_idsimCard;

        $dispositivo = $this->resolveDispositivoActualPorNumero($numeroTelefonico);
        if ($dispositivo !== null) {
            $texto .= '; Dispositivo actual: ' . $dispositivo;
        }

        return $texto;
    }

    private function buildSimCardRelacionActualTexto(string $simCardId): string
    {
        $detalle = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$detalle) {
            return 'Sin Asignación activa.';
        }

        $numero = (string) $detalle->numeroTelefonico_numeroTelefonico;
        $texto = 'Asignación actual con número: ' . $numero;

        $dispositivo = $this->resolveDispositivoActualPorNumero($numero);
        if ($dispositivo !== null) {
            $texto .= '; Dispositivo actual: ' . $dispositivo;
        }

        return $texto;
    }

    private function resolveDispositivoActualPorNumero(string $numeroTelefonico): ?string
    {
        $registro = DB::table('detnumerosdispositivo as d')
            ->leftJoin('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'd.dispositivoCliente_iddispositivoCliente')
            ->where('d.numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->orderByDesc('d.iddetNumerosDispositivo')
            ->select('d.dispositivoCliente_iddispositivoCliente', 'dc.vehiculo_placa', 'dc.marcaDispositivo', 'dc.modeloDispositivo')
            ->first();

        if (!$registro) {
            return null;
        }

        $id = trim((string) ($registro->dispositivoCliente_iddispositivoCliente ?? ''));
        $placa = trim((string) ($registro->vehiculo_placa ?? ''));
        $marca = trim((string) ($registro->marcaDispositivo ?? ''));
        $modelo = trim((string) ($registro->modeloDispositivo ?? ''));

        $partes = array_values(array_filter([$id, $placa, $marca, $modelo], fn($item) => $item !== ''));
        if ($partes === []) {
            return null;
        }

        return implode(' - ', $partes);
    }

    /**
     * @return array<string, string>
     */
    private function dispositivoClienteOptionsForNumeroDispositivo(?string $currentId = null, ?int $ignoreId = null): array
    {
        return DB::table('dispositivocliente')
            ->select('iddispositivoCliente', 'vehiculo_placa')
            ->orderBy('iddispositivoCliente')
            ->get()
            ->mapWithKeys(function ($row) use ($currentId): array {
                $id = (string) ($row->iddispositivoCliente ?? '');
                $placa = trim((string) ($row->vehiculo_placa ?? ''));

                $suffix = trim(implode(' ', array_filter([$placa])));

                if ($currentId !== null && $id === $currentId) {
                    $suffix = $suffix !== '' ? $suffix . ' (actual)' : 'actual';
                }

                return [$id => $suffix !== '' ? $id . ' - ' . $suffix : $id];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function numeroTelefonicoOptionsForNumeroDispositivo(?string $currentNumero = null, ?int $ignoreId = null): array
    {
        return DB::table('numerotelefonico')
            ->select('numeroTelefonico', 'estado')
            ->where('estado', '1')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('detnumerosdispositivo as dn')
                    ->whereColumn('dn.numeroTelefonico_numeroTelefonico', 'numerotelefonico.numeroTelefonico');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('detallesimcard as ds')
                    ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'numerotelefonico.numeroTelefonico');
            })
            ->orderBy('numeroTelefonico')
            ->get()
            ->mapWithKeys(function ($row) use ($currentNumero): array {
                $numero = (string) ($row->numeroTelefonico ?? '');
                $estado = self::normalizeEstado((string) ($row->estado ?? null));
                $label = $numero;

                if ($estado === '1') {
                    $label .= ' (activo)';
                } else {
                    $label .= ' (inactivo)';
                }

                if ($currentNumero !== null && $numero === $currentNumero) {
                    $label .= ' (actual)';
                }

                return [$numero => $label];
            })
            ->all();
    }

    private function assertNumeroDispositivoPairIsUnique(string $dispositivoCliente, string $numeroTelefonico, ?int $ignoreId = null): void
    {
        $errors = [];

        $dispositivoUsado = DB::table('detnumerosdispositivo as d')
            ->join('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico')
            ->where('d.dispositivoCliente_iddispositivoCliente', $dispositivoCliente)
            ->where('n.estado', '1')
            ->when($ignoreId !== null, function ($query) use ($ignoreId): void {
                $query->where('d.iddetNumerosDispositivo', '!=', $ignoreId);
            })
            ->exists();

        if ($dispositivoUsado) {
            $errors['dispositivoCliente_iddispositivoCliente'] = 'Este dispositivo ya está asignado a un número.';
        }

        $numeroUsado = DB::table('detnumerosdispositivo as d')
            ->join('numerotelefonico as n', 'n.numeroTelefonico', '=', 'd.numeroTelefonico_numeroTelefonico')
            ->where('d.numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('n.estado', '1')
            ->when($ignoreId !== null, function ($query) use ($ignoreId): void {
                $query->where('d.iddetNumerosDispositivo', '!=', $ignoreId);
            })
            ->exists();

        if ($numeroUsado) {
            $errors['numeroTelefonico_numeroTelefonico'] = 'Este número ya está asignado a un dispositivo.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertNumeroDisponibleParaAsignacionDispositivo(string $numeroTelefonico): void
    {
        $currentDetalle = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$currentDetalle) {
            throw ValidationException::withMessages([
                'numeroTelefonico_numeroTelefonico' => 'Este número telefónico no está asignado a ningún simcard.',
            ]);
        }

        $simCardEstado = DB::table('simcard')
            ->where('idsimCard', (string) $currentDetalle->simCard_idsimCard)
            ->value('estado');

        $numeroEstado = DB::table('numerotelefonico')
            ->where('numeroTelefonico', $numeroTelefonico)
            ->value('estado');

        if (self::normalizeEstado((string) $simCardEstado) !== '1' || self::normalizeEstado((string) $numeroEstado) !== '1') {
            throw ValidationException::withMessages([
                'numeroTelefonico_numeroTelefonico' => 'Este número telefónico no está asignado a un simcard activo.',
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return array<string, string>
     */
    private function simCardOptions(?string $currentId = null): array
    {
        return DB::table('simcard as s')
            ->select('s.idsimCard', 's.estado')
            ->where(function ($query) use ($currentId) {
                $query->where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('s.estado', '1')
                            ->whereNotExists(function ($query) {
                                $query->select(DB::raw(1))
                                    ->from('detallesimcard as ds')
                                    ->whereColumn('ds.simCard_idsimCard', 's.idsimCard')
                                    ->where('ds.estado', '0');
                            });
                    })->orWhere(function ($query) {
                        $query->where('s.estado', '2')
                            ->whereExists(function ($query) {
                                $query->select(DB::raw(1))
                                    ->from('detallesimcard as ds')
                                    ->whereColumn('ds.simCard_idsimCard', 's.idsimCard')
                                    ->where('ds.estado', '0')
                                    ->whereNotExists(function ($query) {
                                        $query->select(DB::raw(1))
                                            ->from('detnumerosdispositivo as dn')
                                            ->join('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'dn.dispositivoCliente_iddispositivoCliente')
                                            ->whereColumn('dn.numeroTelefonico_numeroTelefonico', 'ds.numeroTelefonico_numeroTelefonico')
                                            ->where('dc.estado', '1');
                                    });
                            });
                    });
                });
                if ($currentId !== null && $currentId !== '') {
                    $query->orWhere('s.idsimCard', $currentId);
                }
            })
            ->orderBy('idsimCard')
            ->get()
            ->mapWithKeys(function ($row) use ($currentId): array {
                $id = (string) ($row->idsimCard ?? '');
                $st = self::normalizeEstado((string) ($row->estado ?? null));
                $label = $id . ' (' . self::normalizeEstadoLabel($st) . ')';

                if ($currentId !== null && $id === $currentId) {
                    $label .= ' (actual)';
                }

                return [$id => $label];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function numeroTelefonicoOptions(?string $currentNumero = null): array
    {
        return DB::table('numerotelefonico as n')
            ->select('n.numeroTelefonico', 'n.estado')
            ->where(function ($query) use ($currentNumero) {
                $query->where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('n.estado', '1')
                            ->whereNotExists(function ($query) {
                                $query->select(DB::raw(1))
                                    ->from('detallesimcard as ds')
                                    ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                                    ->where('ds.estado', '0');
                            });
                    })->orWhere(function ($query) {
                        $query->where('n.estado', '2')
                            ->whereExists(function ($query) {
                                $query->select(DB::raw(1))
                                    ->from('detallesimcard as ds')
                                    ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                                    ->where('ds.estado', '0');
                            });
                    });
                });
                if ($currentNumero !== null && $currentNumero !== '') {
                    $query->orWhere('n.numeroTelefonico', $currentNumero);
                }
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('detnumerosdispositivo as dn')
                    ->join('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'dn.dispositivoCliente_iddispositivoCliente')
                    ->whereColumn('dn.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico')
                    ->where('dc.estado', '1');
            })
            ->orderBy('numeroTelefonico')
            ->get()
            ->mapWithKeys(function ($row) use ($currentNumero): array {
                $numero = (string) ($row->numeroTelefonico ?? '');
                $st = self::normalizeEstado((string) ($row->estado ?? null));
                $label = $numero . ' (' . self::normalizeEstadoLabel($st) . ')';

                if ($currentNumero !== null && $numero === $currentNumero) {
                    $label .= ' (actual)';
                }

                return [$numero => $label];
            })
            ->all();
    }

    private function assertDetalleSimCardPairIsUnique(string $simCardId, string $numeroTelefonico): void
    {
        $simEstado = DB::table('simcard')
            ->where('idsimCard', $simCardId)
            ->value('estado');
        $numeroEstado = DB::table('numerotelefonico')
            ->where('numeroTelefonico', $numeroTelefonico)
            ->value('estado');

        $currentSimDetalle = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        $currentNumeroDetalle = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        $simEstado = self::normalizeEstado((string) $simEstado);
        $numeroEstado = self::normalizeEstado((string) $numeroEstado);
        $simActive = $currentSimDetalle && $this->isDetalleSimCardVigente($currentSimDetalle);
        $numeroActive = $currentNumeroDetalle && $this->isDetalleSimCardVigente($currentNumeroDetalle);
        $errors = [];

        if (
            $currentSimDetalle
            && $currentNumeroDetalle
            && (int) $currentSimDetalle->iddetalleSimCard === (int) $currentNumeroDetalle->iddetalleSimCard
        ) {
            throw ValidationException::withMessages([
                'numeroTelefonico_numeroTelefonico' => 'Esta SimCard ya está asignada a este número.',
            ]);
        }

        if (!(($simEstado === '1' && !$currentSimDetalle) || ($simEstado === '2' && $simActive))) {
            $errors['simCard_idsimCard'] = 'La SimCard debe estar Libre o Disponible con una asignación vigente para poder asignarla.';
        }

        if (!(($numeroEstado === '1' && !$currentNumeroDetalle) || ($numeroEstado === '2' && $numeroActive))) {
            $errors['numeroTelefonico_numeroTelefonico'] = 'El número debe estar Libre o Disponible con una asignación vigente para poder asignarlo.';
        }

        $numeroEnDispositivoActivo = DB::table('detnumerosdispositivo as dn')
            ->join('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'dn.dispositivoCliente_iddispositivoCliente')
            ->where('dn.numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('dc.estado', '1')
            ->exists();
        $numeroAnteriorEnDispositivoActivo = $currentSimDetalle && DB::table('detnumerosdispositivo as dn')
            ->join('dispositivocliente as dc', 'dc.iddispositivoCliente', '=', 'dn.dispositivoCliente_iddispositivoCliente')
            ->where('dn.numeroTelefonico_numeroTelefonico', (string) $currentSimDetalle->numeroTelefonico_numeroTelefonico)
            ->where('dc.estado', '1')
            ->exists();

        if ($numeroEnDispositivoActivo) {
            $errors['numeroTelefonico_numeroTelefonico'] = 'No se puede reasignar un número ligado a un dispositivo activo.';
        }
        if ($numeroAnteriorEnDispositivoActivo) {
            $errors['simCard_idsimCard'] = 'No se puede mover una SimCard cuyo número actual está ligado a un dispositivo activo.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertSimCardDisponibleParaAsignacion(string $simCardId): void
    {
        $estado = DB::table('simcard')
            ->where('idsimCard', $simCardId)
            ->value('estado');

        $isActive = self::normalizeEstado((string) $estado) === '1';
        if ($isActive && $this->hasSimCardDetalleHistory($simCardId)) {
            throw ValidationException::withMessages([
                'simCard_idsimCard' => 'La simcard seleccionada ya está activa. Debe estar libre para asignarla.',
            ]);
        }
    }

    private function assertNumeroDisponibleParaAsignacion(string $numeroTelefonico): void
    {
        $estado = DB::table('numerotelefonico')
            ->where('numeroTelefonico', $numeroTelefonico)
            ->value('estado');

        $isActive = self::normalizeEstado((string) $estado) === '1';
        if ($isActive && $this->hasNumeroDetalleHistory($numeroTelefonico)) {
            throw ValidationException::withMessages([
                'numeroTelefonico_numeroTelefonico' => 'El número seleccionado ya está activo. Debe estar libre para asignarlo.',
            ]);
        }
    }

    private function isDetalleSimCardVigente(object $record): bool
    {
        $simCardId = (string) ($record->simCard_idsimCard ?? '');
        $numeroTelefonico = (string) ($record->numeroTelefonico_numeroTelefonico ?? '');
        $detalleId = (int) ($record->iddetalleSimCard ?? 0);

        $estadoSimCard = DB::table('simcard')
            ->where('idsimCard', $simCardId)
            ->value('estado');
        $estadoNumero = DB::table('numerotelefonico')
            ->where('numeroTelefonico', $numeroTelefonico)
            ->value('estado');

        if (self::normalizeEstado((string) $estadoSimCard) === '0' || self::normalizeEstado((string) $estadoNumero) === '0') {
            return false;
        }

        $ultimaAsignacionSimCard = (int) DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->max('iddetalleSimCard');

        $ultimaAsignacionNumero = (int) DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->max('iddetalleSimCard');

        return $detalleId === $ultimaAsignacionSimCard
            && $detalleId === $ultimaAsignacionNumero;
    }

    private function hasSimCardDetalleHistory(string $simCardId): bool
    {
        return DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '1')
            ->exists();
    }

    private function hasNumeroDetalleHistory(string $numeroTelefonico): bool
    {
        return DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '1')
            ->exists();
    }

    private function numeroDispositivoTieneRelaciones(int $id): bool
    {
        $registro = DB::table('detnumerosdispositivo')
            ->where('iddetNumerosDispositivo', $id)
            ->first(['dispositivoCliente_iddispositivoCliente']);

        if (!$registro) {
            return false;
        }

        return DB::table('historial_dispositivocliente')
            ->where('detNumerosDispositivo_iddetNumerosDispositivo', $id)
            ->exists()
            || DB::table('detalleoperacion')
                ->where('detNumerosDispositivo_iddetNumerosDispositivo', $id)
                ->exists()
            || DB::table('detalle_serviciodispositivo')
                ->where('dispositivoCliente_iddispositivoCliente', $registro->dispositivoCliente_iddispositivoCliente)
                ->exists();
    }

    private function canDeleteNumeroWithSimcard(string $numeroTelefonico): bool
    {
        $currentDetail = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$currentDetail) {
            return false;
        }

        $hasHistory = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->where('estado', '1')
            ->exists();

        if ($hasHistory) {
            return false;
        }

        if (DB::table('detnumerosdispositivo')->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)->exists()) {
            return false;
        }

        $simCardId = trim((string) ($currentDetail->simCard_idsimCard ?? ''));
        return $simCardId !== '' && !$this->hasSimCardDetalleHistory($simCardId);
    }

    private function canDeleteSimcardWithNumero(string $simCardId): bool
    {
        $currentDetail = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '0')
            ->orderByDesc('iddetalleSimCard')
            ->first();

        if (!$currentDetail) {
            return false;
        }

        $hasHistory = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->where('estado', '1')
            ->exists();

        if ($hasHistory) {
            return false;
        }

        $numeroTelefonico = trim((string) ($currentDetail->numeroTelefonico_numeroTelefonico ?? ''));
        if ($numeroTelefonico === '' || $this->hasNumeroDetalleHistory($numeroTelefonico)) {
            return false;
        }

        return DB::table('detnumerosdispositivo')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->doesntExist();
    }

    private function canDeleteDetalleWithNumberAndSimcard(object $detalle): bool
    {
        if (trim((string) ($detalle->estado ?? '')) !== '0') {
            return false;
        }

        $simCardId = trim((string) ($detalle->simCard_idsimCard ?? ''));
        $numeroTelefonico = trim((string) ($detalle->numeroTelefonico_numeroTelefonico ?? ''));

        if ($simCardId === '' || $numeroTelefonico === '') {
            return false;
        }

        $simCardCount = DB::table('detallesimcard')
            ->where('simCard_idsimCard', $simCardId)
            ->count();
        $numeroCount = DB::table('detallesimcard')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->count();

        if ($simCardCount > 1 || $numeroCount > 1) {
            return false;
        }

        if ($this->hasSimCardDetalleHistory($simCardId) || $this->hasNumeroDetalleHistory($numeroTelefonico)) {
            return false;
        }

        return DB::table('detnumerosdispositivo')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->doesntExist();
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveNumeroImportPrecio(string $value, ?string $monedaPreferida = null): array
    {
        [$precio, $moneda] = $this->normalizeNumeroPrecio($value);

        $desired = trim((string) ($monedaPreferida ?? ''));
        if ($precio !== null && in_array($desired, ['S/', '$'], true)) {
            return [$precio, $desired];
        }

        return [$precio, $moneda];
    }

    private function normalizeNumeroPrecio(string $value): array
    {
        $raw = trim($value);
        $moneda = str_contains($raw, '$') ? '$' : 'S/';
        $numeric = preg_replace('/[^0-9,.-]/', '', $raw) ?? '';

        if ($numeric === '' || !preg_match('/\d/', $numeric)) {
            return [null, $moneda];
        }

        $lastComma = strrpos($numeric, ',');
        $lastDot = strrpos($numeric, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $numeric = str_replace('.', '', $numeric);
                $numeric = str_replace(',', '.', $numeric);
            } else {
                $numeric = str_replace(',', '', $numeric);
            }
        } elseif ($lastComma !== false) {
            $numeric = str_replace(',', '.', $numeric);
        }

        if (!is_numeric($numeric) || (float) $numeric < 0) {
            return [null, $moneda];
        }

        return [number_format((float) $numeric, 2, '.', ''), $moneda];
    }

    private function upsertNumeroPrecio(string $numeroTelefonico, string $precio, string $moneda): void
    {
        $detail = DB::table('detalle_precionumero')
            ->where('numeroTelefonico_numeroTelefonico', $numeroTelefonico)
            ->orderByDesc('iddetalle_precionumero')
            ->first();

        $payload = [
            'precio' => $precio,
            'moneda' => $moneda,
            'fecha' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        if ($detail) {
            DB::table('detalle_precionumero')
                ->where('iddetalle_precionumero', $detail->iddetalle_precionumero)
                ->update($payload);
            return;
        }

        DB::table('detalle_precionumero')->insert([
            'numeroTelefonico_numeroTelefonico' => $numeroTelefonico,
            ...$payload,
        ]);
    }

    private static function normalizeEstado(string|null $estado): string
    {
        $normalized = trim((string) $estado);
        if ($normalized === '') {
            return '0';
        }

        $lowerValue = mb_strtolower($normalized);

        if (in_array($lowerValue, ['3', 'en uso', 'uso'], true)) {
            return '3';
        }

        if (in_array($lowerValue, ['2', 'disponible', 'disp'], true)) {
            return '2';
        }

        if (in_array($lowerValue, ['1', 'libre', 'activo', 'a'], true)) {
            return '1';
        }

        if (in_array($lowerValue, ['0', 'inactivo', 'i'], true)) {
            return '0';
        }

        return '0';
    }

    private static function normalizeEstadoLabel(string|null $estado): string
    {
        return match (self::normalizeEstado($estado)) {
            '3' => 'En Uso',
            '2' => 'Disponible',
            '1' => 'Libre',
            default => 'Inactivo',
        };
    }

    private static function normalizeDetalleSimCardEstadoLabel(string|null $estado): string
    {
        // En asignaciones de SimCard, el estado '0' es el activo actual y '1' es el histórico/pasado.
        return self::normalizeEstado($estado) === '0' ? 'Activo' : 'Inactivo';
    }

    private static function normalizeFechaAsignacionForInput(string|null $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeFechaAsignacionForStorage(string|null $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
