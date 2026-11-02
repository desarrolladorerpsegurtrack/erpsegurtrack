<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanTelefonicoController extends Controller
{
    use ExportableList;

    private const TABLE = 'plantelef';
    private const RESOURCE = 'lineas_chips.plan_telefonico';
    private const LOCK_RESOURCE = 'lineas_chips.plan_telefonico';
    private const INDEX_ROUTE = 'modules.lineas-chips.plan-telefonico.index';

    public function index(Request $request): View
    {
        $query = $this->buildListQuery($request);
        $items = (clone $query)
            ->orderBy('idplantelef')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->getCollection()->transform(function ($item) {
            $item->precio_display = $this->formatPrice($item->precio, $item->moneda);

            return $item;
        });

        return view('lineaschip.plantelef', [
            'title' => 'Plan Telefónico',
            'singularTitle' => 'Plan Telefónico',
            'items' => $items,
            'columns' => [
                ['key' => 'idplantelef', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'precio_display', 'label' => 'Precio', 'type' => 'text'],
                ['key' => 'megas', 'label' => 'Megas', 'type' => 'text'],
                ['key' => 'sms', 'label' => 'SMS', 'type' => 'text'],
            ],
            'createRoute' => route('modules.lineas-chips.plan-telefonico.create'),
            'editRoute' => 'modules.lineas-chips.plan-telefonico.edit',
            'destroyRoute' => 'modules.lineas-chips.plan-telefonico.destroy',
            'bulkDestroyRoute' => route('modules.lineas-chips.plan-telefonico.bulk-destroy'),
            'identifierKey' => 'idplantelef',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.lineas-chips.plan-telefonico.export', ['format' => 'pdf']),
                'xlsx' => route('modules.lineas-chips.plan-telefonico.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de planes', 'value' => (clone $query)->count()],
            ],
            'filters' => [
                [
                    'name' => 'nombre',
                    'label' => 'Nombre',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por nombre',
                ],
                [
                    'name' => 'precio',
                    'label' => 'Precio',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por precio',
                ],
                [
                    'name' => 'megas',
                    'label' => 'Megas',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por megas',
                ],
                [
                    'name' => 'sms',
                    'label' => 'SMS',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por SMS',
                ],
            ],
        ]);
    }

    public function create(): View
    {
        return $this->formView(null, 'create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);
        $id = DB::table(self::TABLE)->insertGetId($validated, 'idplantelef');

        $this->publishResourceEvent(self::RESOURCE, (string) $id, 'created');

        return redirect()
            ->route(self::INDEX_ROUTE)
            ->with('success', 'Plan telefónico creado correctamente.');
    }

    public function edit(int $id): View|RedirectResponse
    {
        $record = DB::table(self::TABLE)->where('idplantelef', $id)->first();
        if (!$record) {
            return redirect()
                ->route(self::INDEX_ROUTE)
                ->with('error', 'No se encontró el plan telefónico solicitado.');
        }

        return $this->formView(
            $record,
            'edit',
            $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $record->idplantelef)
        );
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table(self::TABLE)->where('idplantelef', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route(self::INDEX_ROUTE)
                ->with('error', 'No se encontró el plan telefónico solicitado.');
        }

        if ($redirect = $this->assertLockAvailable(
            $request,
            self::LOCK_RESOURCE,
            (string) $id,
            'plan telefónico',
            self::INDEX_ROUTE
        )) {
            return $redirect;
        }

        $validated = $this->validatePlan($request);
        DB::table(self::TABLE)->where('idplantelef', $id)->update($validated);
        $this->publishResourceEvent(self::RESOURCE, (string) $id, 'updated');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

        return redirect()
            ->route(self::INDEX_ROUTE)
            ->with('success', 'Plan telefónico actualizado correctamente.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable(
            $request,
            self::LOCK_RESOURCE,
            (string) $id,
            'plan telefónico',
            self::INDEX_ROUTE
        )) {
            return $redirect;
        }

        try {
            $deleted = DB::table(self::TABLE)->where('idplantelef', $id)->delete();
        } catch (QueryException) {
            return redirect()
                ->route(self::INDEX_ROUTE)
                ->with('error', 'No se puede eliminar el plan telefónico porque tiene registros relacionados.');
        }

        if ($deleted === 0) {
            return redirect()
                ->route(self::INDEX_ROUTE)
                ->with('error', 'No se encontró el plan telefónico solicitado.');
        }

        $this->publishResourceEvent(self::RESOURCE, (string) $id, 'deleted');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

        return redirect()
            ->route(self::INDEX_ROUTE)
            ->with('success', 'Plan telefónico eliminado correctamente.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'selectedIds' => ['required', 'array', 'min:1'],
            'selectedIds.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['selectedIds']);

        foreach ($ids as $id) {
            if ($redirect = $this->assertLockAvailable(
                $request,
                self::LOCK_RESOURCE,
                (string) $id,
                'plan telefónico',
                self::INDEX_ROUTE
            )) {
                return $redirect;
            }
        }

        try {
            DB::table(self::TABLE)->whereIn('idplantelef', $ids)->delete();
        } catch (QueryException) {
            return redirect()
                ->route(self::INDEX_ROUTE)
                ->with('error', 'No se pueden eliminar los planes seleccionados porque tienen registros relacionados.');
        }

        foreach ($ids as $id) {
            $this->publishResourceEvent(self::RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);
        }

        return redirect()
            ->route(self::INDEX_ROUTE)
            ->with('success', 'Planes telefónicos seleccionados eliminados correctamente.');
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $query = $this->buildListQuery($request);
        $selectedIds = $request->input('selectedIds', []);
        if (is_array($selectedIds) && $selectedIds !== []) {
            $query->whereIn('idplantelef', $selectedIds);
        }

        $columns = [
            ['key' => 'idplantelef', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            [
                'key' => 'precio_display',
                'label' => 'Precio',
                'value' => fn ($row) => $this->formatPrice($row->precio, $row->moneda),
            ],
            ['key' => 'megas', 'label' => 'Megas'],
            ['key' => 'sms', 'label' => 'SMS'],
        ];
        $rows = $query->orderBy('idplantelef')->get();
        $filename = 'planes_telefonicos_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Planes telefónicos', $filename);
    }

    private function formView(?object $record, string $mode, array $extraData = []): View
    {
        $isEdit = $mode === 'edit';
        $monedaOptions = $this->monedaOptions();

        return view('lineaschip.plantelef-form', array_merge([
            'title' => ($isEdit ? 'Editar' : 'Nuevo') . ' Plan Telefónico',
            'moduleTitle' => 'Plan Telefónico',
            'mode' => $mode,
            'formAction' => $isEdit
                ? route('modules.lineas-chips.plan-telefonico.update', $record->idplantelef)
                : route('modules.lineas-chips.plan-telefonico.store'),
            'backRoute' => route(self::INDEX_ROUTE),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 45,
                ],
                [
                    'name' => 'precio',
                    'type' => 'number',
                    'label' => 'Precio',
                    'required' => true,
                    'min' => 0,
                    'max' => 99999999.99,
                    'step' => '0.01',
                    'placeholder' => 'Ej: 29.90',
                ],
                [
                    'name' => 'moneda',
                    'type' => 'select',
                    'label' => 'Moneda',
                    'required' => true,
                    'value' => old('moneda', $record->moneda ?? $this->defaultMoneda($monedaOptions)),
                    'options' => $monedaOptions,
                    'hidePlaceholder' => true,
                ],
                [
                    'name' => 'megas',
                    'type' => 'text',
                    'label' => 'Megas',
                    'required' => true,
                    'maxlength' => 45,
                    'placeholder' => 'Ej: 20 GB',
                ],
                [
                    'name' => 'sms',
                    'type' => 'text',
                    'label' => 'SMS',
                    'required' => true,
                    'maxlength' => 45,
                    'placeholder' => 'Ej: 100 SMS',
                ],
            ],
            'readOnly' => $isEdit,
        ], $extraData));
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'precio' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'moneda' => ['required', 'string', 'max:10', Rule::in(array_keys($this->monedaOptions()))],
            'megas' => ['required', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'sms' => ['required', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);
    }

    private function buildListQuery(Request $request): Builder
    {
        $query = DB::table(self::TABLE)
            ->select('idplantelef', 'nombre', 'precio', 'moneda', 'megas', 'sms');

        $nameFilter = trim((string) $request->input('nombre', ''));
        if ($nameFilter !== '') {
            $query->where('nombre', 'like', '%' . $nameFilter . '%');
        }

        foreach (['precio', 'megas', 'sms'] as $filterColumn) {
            $filterValue = trim((string) $request->input($filterColumn, ''));
            if ($filterValue !== '') {
                $query->where($filterColumn, 'like', '%' . $filterValue . '%');
            }
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('idplantelef', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('precio', 'like', $term)
                    ->orWhere('moneda', 'like', $term)
                    ->orWhere('megas', 'like', $term)
                    ->orWhere('sms', 'like', $term);
            });
        }

        return $query;
    }

    private function monedaOptions(): array
    {
        $symbols = DB::table('moneda')
            ->whereNotNull('simbolo')
            ->whereIn('simbolo', ['S', 'S/', '$', '€'])
            ->orderBy('idmoneda')
            ->pluck('simbolo')
            ->map(fn ($symbol) => trim((string) $symbol))
            ->filter()
            ->unique()
            ->all();

        $options = [];
        foreach ($symbols as $symbol) {
            $label = $this->displayCurrencySymbol($symbol);
            if (!in_array($label, $options, true)) {
                $options[$symbol] = $label;
            }
        }

        return $options;
    }

    private function formatPrice(mixed $price, mixed $currency): string
    {
        $formattedPrice = number_format((float) $price, 2, '.', '');
        $symbol = $this->displayCurrencySymbol(trim((string) $currency));

        return $symbol !== '' ? $symbol . ' ' . $formattedPrice : $formattedPrice;
    }

    private function defaultMoneda(array $options): string
    {
        $solSymbol = array_search('S/', $options, true);
        if ($solSymbol !== false) {
            return (string) $solSymbol;
        }

        return (string) (array_key_first($options) ?? '');
    }

    private function displayCurrencySymbol(string $symbol): string
    {
        return $symbol === 'S' ? 'S/' : $symbol;
    }
}
