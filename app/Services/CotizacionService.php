<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CotizacionService
{
    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    public const STATE_GENERADO = '0';
    public const STATE_APROBADO_SP = '1';
    public const STATE_APROBADO = '2';
    public const STATE_EJECUTADO_SP = '3';
    public const STATE_FINALIZADO = '4';
    public const STATE_ANULADO = '5';

    public function prepareCreateViewData(Request $request): array
    {
        [$currentDni, $canListPersonal, $isAdmin] = $this->resolvePersonalPermissions();

        $tipoDefault = $this->loadDefaultTipoDocumento();
        $fields = $this->buildFields();
        $fields = $this->applyDefaultTipoDocumento($fields, $tipoDefault);
        $fields = $this->applyCreateFechaHoraEmisionField($fields);

        if (!$canListPersonal && !empty($currentDni)) {
            $fields = $this->applyReadonlyPersonalField($fields, $currentDni);
        }

        $personales = $canListPersonal ? $this->loadPersonales($currentDni, $isAdmin) : collect();
        $clientes = $this->loadClientes();
        $vigencias = $this->loadVigencias(true);
        $formasPago = $this->loadFormasPago();
        $monedas = $this->loadMonedas();
        $almacenes = $this->loadAlmacenes();

        $fields = $this->applyCreateFieldOptions(
            $fields,
            $request,
            $canListPersonal,
            $currentDni,
            $personales,
            $clientes,
            $vigencias,
            $formasPago,
            $monedas,
        );

        [$record, $copyDetalles, $fields] = $this->loadCopyFromData($request, $fields);
        $paquetes = $this->loadPaquetes($this->loadPaquetesDetalles());

        return [
            'record' => $record,
            'fields' => $fields,
            'readOnly' => false,
            'almacenes' => $almacenes,
            'paquetes' => $paquetes,
            'detalles' => $copyDetalles,
        ];
    }

    public function prepareEditViewData(Request $request, string $id): array
    {
        $record = $this->loadEditRecord($id);
        $fields = $this->buildFields($id);

        [$currentDni, $canListPersonal, $isAdmin] = $this->resolvePersonalPermissions();

        $personales = $canListPersonal ? $this->loadPersonales($currentDni, $isAdmin) : collect();
        $clientes = $this->loadClientes();
        $vigencias = $this->loadVigencias(false);
        $formasPago = $this->loadFormasPago();
        $monedas = $this->loadMonedas();
        $almacenes = $this->loadAlmacenes();

        $fields = $this->applyEditFieldOptions(
            $fields,
            $record,
            $canListPersonal,
            $currentDni,
            $personales,
            $clientes,
            $vigencias,
            $formasPago,
            $monedas,
        );

        $fields = $this->applyReadonlyEditFields($fields, $record);
        $detalles = $this->loadEditDetalles($id);
        $paquetes = $this->loadPaquetes($this->loadPaquetesDetalles());

        return [
            'record' => $record,
            'fields' => $fields,
            'readOnly' => true,
            'almacenes' => $almacenes,
            'detalles' => $detalles,
            'paquetes' => $paquetes,
        ];
    }

    public function baseQuery(Request $request)
    {
        $query = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'c.cliente_idcliente', '=', 'cli.idcliente')
            ->leftJoin('moneda as m', 'c.moneda_idmoneda', '=', 'm.idmoneda')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->leftJoin('formapago as fp', 'c.formaPago_idformaPago', '=', 'fp.idformaPago')
            ->leftJoin('personal as p', 'c.personal_dniPersonal', '=', 'p.dniPersonal')
            ->select(
                'c.nroCotizacion',
                'c.fechaHoraEmision',
                'c.cliente_idcliente',
                'c.vigenciaOferta_idvigenciaOferta',
                'c.formaPago_idformaPago',
                'c.personal_dniPersonal',
                'c.subtotal',
                'c.descuento',
                'c.igv',
                'c.total',
                'c.batch_id',
                'c.estado',
                'cli.razonSocial',
                'cli.nombreComercial',
                'm.detalle as moneda_detalle',
                'v.detalle as vigencia_detalle',
                'v.dias as vigencia_dias',
                'fp.detalle as formaPago_detalle',
                'fp.tiempo as formaPago_tiempo',
                'p.nombre as personal_nombre',
                'p.apellido as personal_apellido',
            );

        $userRoles = collect(session('erp_auth.roles') ?? [])->map(fn($r) => mb_strtolower(trim((string) $r)))->filter();
        $isAdmin = $userRoles->contains('admin');
        $authPermissions = collect(session('erp_auth.permissions') ?? []);
        $personalActions = collect($authPermissions->get('ventas.personal', []))->map(fn($v) => \App\Support\ErpPermission::normalizeAction((string) $v))->filter();
        $canListPersonal = $isAdmin || $personalActions->contains('ver');
        $currentDni = session('erp_auth.personal_dni') ?? null;
        if (!$canListPersonal && !empty($currentDni)) {
            $query->where('c.personal_dniPersonal', $currentDni);
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('c.nroCotizacion', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term)
                    ->orWhere('v.detalle', 'like', $term)
                    ->orWhere('fp.detalle', 'like', $term)
                    ->orWhere('p.nombre', 'like', $term)
                    ->orWhere('p.apellido', 'like', $term);
            });
        }

        $vigenciaSearch = trim((string) $request->input('vigencia_search', ''));
        if ($vigenciaSearch !== '') {
            if (preg_match('/^\d+$/', $vigenciaSearch)) {
                $query->where('c.vigenciaOferta_idvigenciaOferta', $vigenciaSearch);
            } else {
                $query->where('v.detalle', 'like', '%' . $vigenciaSearch . '%');
            }
        }

        $formaPagoSearch = trim((string) $request->input('forma_pago_search', ''));
        if ($formaPagoSearch !== '') {
            $term = '%' . $formaPagoSearch . '%';
            $query->where('fp.detalle', 'like', $term);
        }

        $vendedorSearch = trim((string) $request->input('vendedor_search', ''));
        if ($vendedorSearch !== '') {
            $term = '%' . $vendedorSearch . '%';
            $query->where(function ($b) use ($term) {
                $b->where('p.dniPersonal', 'like', $term)
                    ->orWhere('p.nombre', 'like', $term)
                    ->orWhere('p.apellido', 'like', $term);
            });
        }

        $subtotalSearch = trim((string) $request->input('subtotal_search', ''));
        if ($subtotalSearch !== '') {
            $query->where('c.subtotal', 'like', '%' . $subtotalSearch . '%');
        }

        $fechaSearch = trim((string) $request->input('fecha_search', ''));
        if ($fechaSearch !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaSearch)) {
                $query->whereDate('c.fechaHoraEmision', $fechaSearch);
            } else {
                $query->where('c.fechaHoraEmision', 'like', '%' . $fechaSearch . '%');
            }
        }

        $totalSearch = trim((string) $request->input('total_search', ''));
        if ($totalSearch !== '') {
            $query->where('c.total', 'like', '%' . $totalSearch . '%');
        }

        $estadoSearch = trim((string) $request->input('estado_search', ''));
        if ($estadoSearch !== '') {
            $query->where('c.estado', $estadoSearch);
        }

        return $query;
    }

    public function buildFields(?string $id = null): array
    {
        return [
            [
                'name' => 'nroCotizacion',
                'type' => 'text',
                'label' => 'Nro. Cotización',
                'required' => false,
                'readonly' => true,
            ],
            [
                'name' => 'fechaHoraEmision',
                'type' => 'text',
                'label' => 'Fecha y Hora Emisión',
                'required' => false,
            ],
            [
                'name' => 'tipoDocumento_idtipoDocumento',
                'type' => 'text',
                'label' => 'Tipo de Documento',
                'required' => false,
            ],
            [
                'name' => 'personal_dniPersonal',
                'type' => 'text',
                'label' => 'Personal Cotizadora',
                'helpText' => 'DNI del personal que emite la cotización.',
            ],
            [
                'name' => 'cliente_idcliente',
                'type' => 'text',
                'label' => 'Cliente',
                'required' => true,
                'helpText' => 'Identificador del cliente.',
            ],
            [
                'name' => 'cliente_idcliente_visual',
                'type' => 'text',
                'label' => 'RUC / DNI',
                'helpText' => 'ID del cliente seleccionado.',
                'readonly' => true,
            ],
            [
                'name' => 'direccion',
                'type' => 'text',
                'label' => 'Dirección',
                'required' => true,
                'maxlength' => 150,
                'quickAddressModal' => true,
            ],
            [
                'name' => 'telefono',
                'type' => 'text',
                'label' => 'Teléfono',
                'helpText' => 'Número de teléfono',
            ],
            [
                'name' => 'correo',
                'type' => 'text',
                'label' => 'Correo',
                'helpText' => 'Debe incluir @ y terminar en .com.',
            ],
            [
                'name' => 'vigenciaOferta_idvigenciaOferta',
                'type' => 'text',
                'label' => 'Vigencia de Oferta',
                'required' => true,
            ],
            [
                'name' => 'formaPago_idformaPago',
                'type' => 'text',
                'label' => 'Forma de Pago',
                'required' => true,
            ],
            [
                'name' => 'moneda_idmoneda',
                'type' => 'text',
                'label' => 'Moneda',
                'required' => true,
            ],
            [
                'name' => 'descuento',
                'type' => 'number',
                'label' => 'Descuento',
                'step' => '0.01',
            ],
            [
                'name' => 'subtotal',
                'type' => 'number',
                'label' => 'Subtotal',
                'step' => '0.01',
            ],
            [
                'name' => 'igv',
                'type' => 'number',
                'label' => 'IGV',
                'step' => '0.01',
            ],
            [
                'name' => 'total',
                'type' => 'number',
                'label' => 'Total',
                'step' => '0.01',
            ],
            [
                'name' => 'comentario',
                'type' => 'textarea',
                'label' => 'Comentario',
                'colSpan' => 1,
            ],
        ];
    }

    private function loadDefaultTipoDocumento(): ?object
    {
        return DB::table('tipodocumento')
            ->whereRaw("LOWER(TRIM(COALESCE(detalle, ''))) LIKE '%cotiz%'")
            ->orderBy('idtipoDocumento')
            ->first();
    }

    private function applyDefaultTipoDocumento(array $fields, ?object $tipoDefault): array
    {
        if (!$tipoDefault) {
            return $fields;
        }

        $next = ((int) ($tipoDefault->correlativo ?? 0)) + 1;
        $serie = trim((string) ($tipoDefault->serie ?? 'CT'));
        $previewId = $serie . sprintf('%05d', $next);

        array_unshift($fields, [
            'name' => 'nroCotizacion_preview',
            'type' => 'text',
            'label' => 'Nro. Cotización',
            'readonly' => true,
            'value' => $previewId,
        ]);

        foreach ($fields as $idx => $f) {
            if (($f['name'] ?? '') === 'nroCotizacion') {
                $fields[$idx] = [
                    'name' => 'nroCotizacion',
                    'type' => 'hidden',
                    'value' => null,
                ];
                continue;
            }

            if (($f['name'] ?? '') === 'tipoDocumento_idtipoDocumento') {
                $fields[$idx] = [
                    'name' => 'tipoDocumento_idtipoDocumento',
                    'type' => 'hidden',
                    'value' => (int) $tipoDefault->idtipoDocumento,
                ];
                break;
            }
        }

        return $fields;
    }

    private function applyCreateFechaHoraEmisionField(array $fields): array
    {
        foreach ($fields as $idx => $f) {
            if (($f['name'] ?? '') === 'fechaHoraEmision') {
                $fields[$idx] = [
                    'name' => 'fechaHoraEmision',
                    'type' => 'hidden',
                    'value' => now()->format('Y-m-d H:i:s'),
                ];

                array_splice($fields, $idx + 1, 0, [
                    [
                        'name' => 'fechaHoraEmision_label',
                        'type' => 'text',
                        'label' => 'Fecha y Hora Emisión',
                        'readonly' => true,
                        'value' => now()->format('d/m/Y H:i'),
                    ],
                ]);

                break;
            }
        }

        return $fields;
    }

    private function applyReadonlyPersonalField(array $fields, string $currentDni): array
    {
        $personalRow = DB::table('personal as p')
            ->leftJoin('cargopersonal as cp', 'p.cargoPersonal_idcargoPersonal', '=', 'cp.idcargoPersonal')
            ->select('p.dniPersonal', 'p.nombre', 'p.apellido', 'cp.descripcion as cargo')
            ->where('p.dniPersonal', $currentDni)
            ->first();

        $labelValue = $currentDni;
        if ($personalRow) {
            $labelValue = trim((string) ($personalRow->dniPersonal ?? '')) . ' - ' . ($personalRow->nombre ?? '') . ' ' . trim((string) ($personalRow->apellido ?? ''));
        }

        foreach ($fields as $idx => $f) {
            if (($f['name'] ?? '') === 'personal_dniPersonal') {
                $fields[$idx] = [
                    'name' => 'personal_dniPersonal',
                    'type' => 'hidden',
                    'value' => $currentDni,
                ];

                array_splice($fields, $idx + 1, 0, [
                    [
                        'name' => 'personal_dniPersonal_label',
                        'type' => 'text',
                        'label' => 'Personal Cotizadora',
                        'readonly' => true,
                        'value' => $labelValue,
                    ],
                ]);
                break;
            }
        }

        return $fields;
    }

    private function loadPersonales(?string $currentDni, bool $isAdmin): Collection
    {
        $personales = DB::table('personal as p')
            ->leftJoin('cargopersonal as cp', 'p.cargoPersonal_idcargoPersonal', '=', 'cp.idcargoPersonal')
            ->whereRaw("LOWER(COALESCE(cp.descripcion, '')) LIKE '%vent%'")
            ->select('p.dniPersonal', 'p.nombre', 'p.apellido', 'cp.descripcion as cargo')
            ->orderBy('p.apellido')
            ->get()
            ->map(function ($r) {
                $label = trim((string) ($r->dniPersonal ?? '')) . ' - ' . ($r->nombre ?? '') . ' ' . trim((string) ($r->apellido ?? ''));
                return (object) [
                    'dniPersonal' => $r->dniPersonal,
                    'label' => $label,
                ];
            });

        if ($isAdmin && !empty($currentDni)) {
            $exists = $personales->first(fn($p) => (($p->dniPersonal ?? '') === $currentDni));
            if (!$exists) {
                $personalRow = DB::table('personal as p')
                    ->leftJoin('cargopersonal as cp', 'p.cargoPersonal_idcargoPersonal', '=', 'cp.idcargoPersonal')
                    ->select('p.dniPersonal', 'p.nombre', 'p.apellido', 'cp.descripcion as cargo')
                    ->where('p.dniPersonal', $currentDni)
                    ->first();

                $label = $currentDni . ' - admin';
                if ($personalRow) {
                    $label = trim((string) ($personalRow->dniPersonal ?? '')) . ' - ' . ($personalRow->nombre ?? '') . ' ' . trim((string) ($personalRow->apellido ?? ''));
                }

                $personales->push((object) ['dniPersonal' => $currentDni, 'label' => $label]);
            }
        }

        return $personales;
    }

    private function loadClientes(): Collection
    {
        return DB::table('cliente')
            ->select('idcliente', 'razonSocial', 'nombreComercial')
            ->orderBy('razonSocial')
            ->get()
            ->map(function ($c) {
                $label = trim((string) ($c->razonSocial ?? '')) ?: trim((string) ($c->nombreComercial ?? '')) ?: trim((string) ($c->idcliente ?? ''));
                return (object) ['idcliente' => $c->idcliente, 'label' => $label];
            });
    }

    private function loadVigencias(bool $includeDays = false): Collection
    {
        return DB::table('vigenciaoferta')
            ->select('idvigenciaOferta', 'detalle', 'dias')
            ->orderBy('idvigenciaOferta')
            ->get()
            ->map(function ($r) use ($includeDays) {
                $label = trim((string) ($r->detalle ?? ''));
                if ($includeDays) {
                    $dias = (int) ($r->dias ?? 0);
                    if ($dias > 0 && !str_contains(mb_strtolower($label), 'contado')) {
                        $label .= ' (' . $dias . ' días)';
                    }
                }
                return (object) ['idvigenciaOferta' => $r->idvigenciaOferta, 'label' => $label];
            });
    }

    private function loadFormasPago(): Collection
    {
        return DB::table('formapago')
            ->select('idformaPago', 'detalle', 'tiempo')
            ->orderBy('detalle')
            ->get()
            ->map(function ($r) {
                $label = trim((string) ($r->detalle ?? ''));
                $tiempo = (int) ($r->tiempo ?? 0);
                if ($tiempo > 0 && !str_contains(mb_strtolower($label), 'contado')) {
                    $label .= ' (' . $tiempo . ' días)';
                }
                return (object) ['idformaPago' => $r->idformaPago, 'label' => $label];
            });
    }

    private function loadMonedas(): Collection
    {
        return DB::table('moneda')
            ->select('idmoneda', 'detalle')
            ->orderBy('detalle')
            ->get()
            ->map(fn($r) => (object) ['idmoneda' => $r->idmoneda, 'label' => $r->detalle]);
    }

    private function formatPeriodoLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $periodo = trim((string) $value);
        if ($periodo === '' || $periodo === 'No') {
            return '';
        }

        if (is_numeric($periodo)) {
            $mapping = [
                30 => 'Mensual',
                90 => '3 Meses',
                180 => '6 Meses',
                365 => '12 Meses',
                730 => '24 Meses',
                1095 => '36 Meses',
                1460 => '48 Meses',
            ];

            $days = (int) $periodo;
            return $mapping[$days] ?? $periodo;
        }

        return $periodo;
    }

    private function loadAlmacenes(): Collection
    {
        return DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->select([
                'a.idalmacen',
                'a.precio',
                'a.precio as precioUnitario',
                'a.detalle',
                'a.periodo',
                'ep.razonSocial',
                'te.nombre as tipo_nombre',
                'te.detalle as tipo_detalle',
                'p.nombrePlataforma',
            ])
            ->orderBy('ep.razonSocial')
            ->orderBy('a.detalle')
            ->get()
            ->map(function ($row) {
                $almacenDetalle = trim((string) ($row->detalle ?? ''));
                $label = trim((($almacenDetalle !== '' ? $almacenDetalle : 'Sin dispositivo')));
                
                $tipoNombre = mb_strtolower(trim((string) ($row->tipo_nombre ?? '')));
                $isPlanServicio = str_contains($tipoNombre, 'plan') || str_contains($tipoNombre, 'servicio');
                
                if ($isPlanServicio && !empty($row->periodo)) {
                    $formattedPeriodo = $this->formatPeriodoLabel($row->periodo);
                    if ($formattedPeriodo !== '') {
                        $label .= ' - ' . $formattedPeriodo;
                    }
                }

                return (object) [
                    'idalmacen' => $row->idalmacen,
                    'label' => $label,
                    'tipo_nombre' => $row->tipo_nombre,
                    'precio' => $row->precio,
                    'precioUnitario' => $row->precioUnitario,
                ];
            });
    }

    private function resolvePersonalPermissions(): array
    {
        $currentDni = session('erp_auth.personal_dni') ?? null;
        $authPermissions = collect(session('erp_auth.permissions') ?? []);
        $personalActions = collect($authPermissions->get('ventas.personal', []))
            ->map(fn($v) => \App\Support\ErpPermission::normalizeAction((string) $v))
            ->filter();
        $userRoles = collect(session('erp_auth.roles') ?? [])->map(fn($r) => mb_strtolower(trim((string) $r)))->filter();
        $isAdmin = $userRoles->contains('admin');
        $canListPersonal = $isAdmin || $personalActions->contains('ver');

        return [$currentDni, $canListPersonal, $isAdmin];
    }

    private function applyCreateFieldOptions(
        array $fields,
        Request $request,
        bool $canListPersonal,
        ?string $currentDni,
        Collection $personales,
        Collection $clientes,
        Collection $vigencias,
        Collection $formasPago,
        Collection $monedas,
    ): array
    {
        foreach ($fields as $idx => $f) {
            if (($f['name'] ?? '') === 'personal_dniPersonal' && (($f['type'] ?? '') !== 'hidden')) {
                if ($canListPersonal) {
                    $fields[$idx]['type'] = 'select';
                    $fields[$idx]['tomSelect'] = true;
                    $fields[$idx]['optionsData'] = $personales;
                    $fields[$idx]['optionKey'] = 'dniPersonal';
                    $fields[$idx]['optionLabel'] = 'label';
                    if (!empty($currentDni)) {
                        $fields[$idx]['value'] = $currentDni;
                    }
                }
            }

            if (($f['name'] ?? '') === 'cliente_idcliente') {
                $fields[$idx]['type'] = 'select';
                $fields[$idx]['tomSelect'] = true;
                $fields[$idx]['optionsData'] = $clientes;
                $fields[$idx]['optionKey'] = 'idcliente';
                $fields[$idx]['optionLabel'] = 'label';
                if ($request->filled('cliente_idcliente')) {
                    $fields[$idx]['value'] = $request->input('cliente_idcliente');
                }
            }

            if (($f['name'] ?? '') === 'cliente_idcliente_visual') {
                if ($request->filled('cliente_idcliente')) {
                    $fields[$idx]['value'] = $request->input('cliente_idcliente');
                }
                $fields[$idx]['readonly'] = true;
            }

            if (($f['name'] ?? '') === 'vigenciaOferta_idvigenciaOferta') {
                $fields[$idx]['type'] = 'select';
                $fields[$idx]['tomSelect'] = true;
                $fields[$idx]['optionsData'] = $vigencias;
                $fields[$idx]['optionKey'] = 'idvigenciaOferta';
                $fields[$idx]['optionLabel'] = 'label';
                $fields[$idx]['required'] = true;
                // Default: "Vigencia de 10 dias" (idvigenciaOferta = 1) si no hay valor previo
                if (empty($fields[$idx]['value'])) {
                    $fields[$idx]['value'] = '1';
                }
            }

            if (($f['name'] ?? '') === 'formaPago_idformaPago') {
                $fields[$idx]['type'] = 'select';
                $fields[$idx]['tomSelect'] = true;
                $fields[$idx]['optionsData'] = $formasPago;
                $fields[$idx]['optionKey'] = 'idformaPago';
                $fields[$idx]['optionLabel'] = 'label';
                $fields[$idx]['required'] = true;
            }

            if (($f['name'] ?? '') === 'moneda_idmoneda') {
                $fields[$idx]['type'] = 'select';
                $fields[$idx]['tomSelect'] = true;
                $fields[$idx]['optionsData'] = $monedas;
                $fields[$idx]['optionKey'] = 'idmoneda';
                $fields[$idx]['optionLabel'] = 'label';
                $fields[$idx]['required'] = true;
            }
        }

        return $fields;
    }

    private function loadCopyFromData(Request $request, array $fields): array
    {
        $record = null;
        $copyDetalles = [];
        $copyFrom = $request->input('copy_from');

        if (!$copyFrom) {
            return [$record, $copyDetalles, $fields];
        }

        $record = DB::table('cotizacion as c')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->select('c.*', 'v.dias as vigencia_dias')
            ->where('c.nroCotizacion', $copyFrom)
            ->first();

        if (!$record) {
            return [$record, $copyDetalles, $fields];
        }

        $record->nroCotizacion = null;
        $record->fechaHoraEmision = null;
        $record->archivoPago = null;
        $record->estado = '0';

        $copyDetalles = DB::table('detallecotizacion')
            ->where('cotizacion_nroCotizacion', $copyFrom)
            ->get()
            ->map(function ($r) {
                $a = DB::table('almacen')->where('idalmacen', $r->almacen_idalmacen)->first();
                $tipo_nombre = 'EQUIPAMIENTO';
                if ($a && $a->tipoElemento_idtipoElemento) {
                    $te = DB::table('tipoelemento')->where('idtipoElemento', $a->tipoElemento_idtipoElemento)->first();
                    if ($te) {
                        $tipo_nombre = $te->nombre;
                    }
                }
                return (object) [
                    'almacen_idalmacen' => $r->almacen_idalmacen,
                    'precioUnitario' => $r->precioUnitario,
                    'cantidad' => $r->cantidad,
                    'descuento' => $r->descuento,
                    'total' => $r->total,
                    'tipo_nombre' => $tipo_nombre,
                ];
            })->toArray();

        foreach ($fields as $idx => $field) {
            $fieldName = $field['name'] ?? null;
            if (!$fieldName || array_key_exists('value', $field)) {
                continue;
            }
            if (property_exists($record, $fieldName)) {
                $fields[$idx]['value'] = $record->{$fieldName};
            }
        }

        return [$record, $copyDetalles, $fields];
    }

    private function loadPaquetesDetalles(): Collection
    {
        return DB::table('detallepaquete as dp')
            ->join('almacen as a', 'dp.almacen_idalmacen', '=', 'a.idalmacen')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->select(
                'dp.paquetes_idpaquetes',
                'dp.almacen_idalmacen',
                'dp.precio as precioPaquete',
                'a.precio as precioAlmacen',
                'a.detalle',
                'ep.razonSocial',
                'te.nombre as tipo_nombre',
                'te.detalle as tipo_detalle',
                'p.nombrePlataforma'
            )
            ->get();
    }

    private function loadPaquetes(Collection $detallesPaquetes): Collection
    {
        $paquetesRaw = DB::table('paquetes')->orderBy('idpaquetes')->get();

        return $paquetesRaw->map(function ($row) use ($detallesPaquetes) {
            $detalles = $detallesPaquetes->where('paquetes_idpaquetes', $row->idpaquetes)->map(function ($d) {
                $tipo = trim((string) ($d->tipo_nombre ?? '')) . ' - ' . trim((string) ($d->tipo_detalle ?? '')) . '-' . trim((string) ($d->nombrePlataforma ?? ''));
                $empresa = trim((string) ($d->razonSocial ?? ''));
                $label = trim(($tipo !== '' ? $tipo : 'Sin tipo') . ' - ' . ($empresa !== '' ? $empresa : (trim((string) ($d->detalle ?? '')) ?: 'Sin empresa')));
                $precio = (floatval($d->precioPaquete) > 0) ? $d->precioPaquete : $d->precioAlmacen;

                return (object) [
                    'idalmacen' => $d->almacen_idalmacen,
                    'label' => $label,
                    'tipo_nombre' => $d->tipo_nombre,
                    'precio' => $precio,
                ];
            })->values()->all();

            return (object) [
                'id' => $row->idpaquetes,
                'nombre' => $row->nombre,
                'descripcion' => $row->descripcion,
                'detalles' => $detalles,
            ];
        });
    }

    private function loadEditRecord(string $id): ?object
    {
        return DB::table('cotizacion as c')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->select('c.*', 'v.dias as vigencia_dias')
            ->where('c.nroCotizacion', $id)
            ->first();
    }

    private function applyEditFieldOptions(
        array $fields,
        ?object $record,
        bool $canListPersonal,
        ?string $currentDni,
        Collection $personales,
        Collection $clientes,
        Collection $vigencias,
        Collection $formasPago,
        Collection $monedas,
    ): array
    {
        $newFields = [];
        foreach ($fields as $f) {
            if (($f['name'] ?? '') === 'personal_dniPersonal') {
                if ($canListPersonal) {
                    $f['type'] = 'select';
                    $f['tomSelect'] = true;
                    $f['optionsData'] = $personales;
                    $f['optionKey'] = 'dniPersonal';
                    $f['optionLabel'] = 'label';
                    if (!empty($currentDni)) {
                        $f['value'] = $currentDni;
                    }
                    $newFields[] = $f;
                } else {
                    $newFields[] = $f;
                }
                continue;
            }

            if (($f['name'] ?? '') === 'cliente_idcliente') {
                $f['type'] = 'select';
                $f['tomSelect'] = true;
                $f['optionsData'] = $clientes;
                $f['optionKey'] = 'idcliente';
                $f['optionLabel'] = 'label';
            }

            if (($f['name'] ?? '') === 'cliente_idcliente_visual') {
                $f['readonly'] = true;
                if ($record && isset($record->cliente_idcliente)) {
                    $f['value'] = $record->cliente_idcliente;
                }
            }

            if (($f['name'] ?? '') === 'vigenciaOferta_idvigenciaOferta') {
                $f['type'] = 'select';
                $f['tomSelect'] = true;
                $f['optionsData'] = $vigencias;
                $f['optionKey'] = 'idvigenciaOferta';
                $f['optionLabel'] = 'label';
                $f['required'] = true;
            }

            if (($f['name'] ?? '') === 'formaPago_idformaPago') {
                $f['type'] = 'select';
                $f['tomSelect'] = true;
                $f['optionsData'] = $formasPago;
                $f['optionKey'] = 'idformaPago';
                $f['optionLabel'] = 'label';
                $f['required'] = true;
            }

            if (($f['name'] ?? '') === 'moneda_idmoneda') {
                $f['type'] = 'select';
                $f['tomSelect'] = true;
                $f['optionsData'] = $monedas;
                $f['optionKey'] = 'idmoneda';
                $f['optionLabel'] = 'label';
                $f['required'] = true;
            }

            $newFields[] = $f;
        }

        foreach ($newFields as $idx => $field) {
            $fieldName = $field['name'] ?? null;
            if (!$fieldName || array_key_exists('value', $field)) {
                continue;
            }
            if ($record && property_exists($record, $fieldName)) {
                $newFields[$idx]['value'] = $record->{$fieldName};
            }
        }

        return $newFields;
    }

    private function applyReadonlyEditFields(array $fields, ?object $record): array
    {
        if ($record && isset($record->tipoDocumento_idtipoDocumento)) {
            $td = DB::table('tipodocumento')->where('idtipoDocumento', $record->tipoDocumento_idtipoDocumento)->first();
            if ($td && str_contains(mb_strtolower(trim((string) ($td->detalle ?? ''))), 'cotiz')) {
                foreach ($fields as $idx => $f) {
                    if (($f['name'] ?? '') === 'tipoDocumento_idtipoDocumento') {
                        $fields[$idx]['type'] = 'hidden';
                        break;
                    }
                }
            }
        }

        foreach ($fields as $idx => $f) {
            if (($f['name'] ?? '') === 'fechaHoraEmision') {
                $fields[$idx] = [
                    'name' => 'fechaHoraEmision',
                    'type' => 'hidden',
                    'value' => $record->fechaHoraEmision ?? '',
                ];

                array_splice($fields, $idx + 1, 0, [
                    [
                        'name' => 'fechaHoraEmision_label',
                        'type' => 'text',
                        'label' => 'Fecha y Hora Emisión',
                        'readonly' => true,
                        'value' => $record->fechaHoraEmision ? date('d/m/Y H:i', strtotime($record->fechaHoraEmision)) : '-',
                    ],
                ]);

                break;
            }
        }

        return $fields;
    }

    private function loadEditDetalles(string $id): Collection
    {
        return DB::table('detallecotizacion')
            ->where('cotizacion_nroCotizacion', $id)
            ->get()
            ->map(function ($r) {
                return (object) [
                    'almacen_idalmacen' => $r->almacen_idalmacen,
                    'precioUnitario' => $r->precioUnitario,
                    'cantidad' => $r->cantidad,
                    'descuento' => $r->descuento,
                    'total' => $r->total,
                ];
            });
    }

    public function validateCotizacion(Request $request, string $mode, ?string $id = null): array
    {
        $rules = [
            'fechaHoraEmision' => ['nullable', 'date'],
            'cliente_idcliente' => ['required', 'string', 'max:20'],
            'tipoDocumento_idtipoDocumento' => ['nullable', 'integer'],
            'tipoDocumentoIDCliente' => ['nullable', 'string', 'max:3'],
            'personal_dniPersonal' => ['nullable', 'string', 'max:20'],
            'direccion' => ['required', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:15'],
            'correo' => ['nullable', 'email', 'max:100'],
            'vigenciaOferta_idvigenciaOferta' => ['nullable', 'required_without:cotizaciones', 'integer', 'exists:vigenciaoferta,idvigenciaOferta'],
            'formaPago_idformaPago' => ['nullable', 'required_without:cotizaciones', 'integer', 'exists:formapago,idformaPago'],
            'moneda_idmoneda' => ['nullable', 'required_without:cotizaciones', 'integer', 'exists:moneda,idmoneda'],
            'comentario' => ['nullable', 'string', 'max:100'],
            'nroCotizacion' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'cotizaciones' => ['nullable', 'array'],
            'cotizaciones.*.tipo_nombre' => ['required_with:cotizaciones', 'string'],
            'cotizaciones.*.subtotal' => ['required_with:cotizaciones', 'numeric'],
            'cotizaciones.*.descuento' => ['nullable', 'numeric'],
            'cotizaciones.*.igv' => ['required_with:cotizaciones', 'numeric'],
            'cotizaciones.*.total' => ['required_with:cotizaciones', 'numeric'],
            'cotizaciones.*.detalle' => ['required_with:cotizaciones', 'array'],
            'cotizaciones.*.vigenciaOferta_idvigenciaOferta' => ['nullable', 'integer', 'exists:vigenciaoferta,idvigenciaOferta'],
            'cotizaciones.*.formaPago_idformaPago' => ['required', 'integer', 'exists:formapago,idformaPago'],
            'cotizaciones.*.moneda_idmoneda' => ['required', 'integer', 'exists:moneda,idmoneda'],
            'cotizaciones.*.comentario' => ['nullable', 'string', 'max:100'],
            'cotizaciones.*.detalle.*.almacen_idalmacen' => ['required_with:cotizaciones', 'integer'],
            'cotizaciones.*.detalle.*.precioUnitario' => ['required_with:cotizaciones', 'numeric'],
            'cotizaciones.*.detalle.*.cantidad' => ['required_with:cotizaciones', 'numeric'],
            'cotizaciones.*.detalle.*.descuento' => ['nullable', 'numeric'],
            'cotizaciones.*.detalle.*.total' => ['nullable', 'numeric'],
        ];

        if ($request->has('cotizaciones')) {
            $rules['subtotal'] = ['nullable', 'numeric'];
            $rules['descuento'] = ['nullable', 'numeric'];
            $rules['igv'] = ['nullable', 'numeric'];
            $rules['total'] = ['nullable', 'numeric'];
            $rules['detalle'] = ['nullable', 'array'];
        } else {
            $rules['subtotal'] = ['nullable', 'numeric'];
            $rules['descuento'] = ['nullable', 'numeric'];
            $rules['igv'] = ['nullable', 'numeric'];
            $rules['total'] = ['nullable', 'numeric'];
            $rules['detalle'] = ['nullable', 'array'];
            $rules['detalle.*.almacen_idalmacen'] = ['required_with:detalle', 'integer'];
            $rules['detalle.*.precioUnitario'] = ['required_with:detalle', 'numeric'];
            $rules['detalle.*.cantidad'] = ['required_with:detalle', 'numeric'];
            $rules['detalle.*.descuento'] = ['nullable', 'numeric'];
            $rules['detalle.*.total'] = ['nullable', 'numeric'];
        }

        $messages = [
            'required' => 'El campo :attribute es obligatorio.',
            'required_with' => 'El campo :attribute es obligatorio para completar la cotización.',
            'required_without' => 'El campo :attribute es obligatorio para continuar con la cotización.',
            'string' => 'El campo :attribute debe ser texto válido.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'email' => 'El campo :attribute debe tener un formato de correo válido.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'max' => 'El campo :attribute no debe exceder :max caracteres.',
            'exists' => 'El valor seleccionado para :attribute no es válido.',
            'array' => 'El campo :attribute debe ser una lista válida.',
            'regex' => 'El campo :attribute contiene caracteres inválidos.',
        ];

        $attributes = [
            'fechaHoraEmision' => 'fecha de emisión',
            'cliente_idcliente' => 'cliente',
            'tipoDocumento_idtipoDocumento' => 'tipo de documento',
            'tipoDocumentoIDCliente' => 'tipo de documento del cliente',
            'personal_dniPersonal' => 'personal',
            'direccion' => 'dirección',
            'telefono' => 'teléfono',
            'correo' => 'correo',
            'vigenciaOferta_idvigenciaOferta' => 'vigencia de oferta',
            'formaPago_idformaPago' => 'forma de pago',
            'moneda_idmoneda' => 'moneda',
            'comentario' => 'comentario',
            'nroCotizacion' => 'número de cotización',
            'subtotal' => 'subtotal',
            'descuento' => 'descuento',
            'igv' => 'IGV',
            'total' => 'total',
            'detalle' => 'detalle de productos',
            'cotizaciones' => 'cotizaciones',
            'cotizaciones.*.tipo_nombre' => 'tipo de cotización',
            'cotizaciones.*.subtotal' => 'subtotal',
            'cotizaciones.*.descuento' => 'descuento',
            'cotizaciones.*.igv' => 'IGV',
            'cotizaciones.*.total' => 'total',
            'cotizaciones.*.detalle' => 'detalle de productos',
            'cotizaciones.*.detalle.*.almacen_idalmacen' => 'almacén',
            'cotizaciones.*.detalle.*.precioUnitario' => 'precio unitario',
            'cotizaciones.*.detalle.*.cantidad' => 'cantidad',
            'cotizaciones.*.detalle.*.descuento' => 'descuento',
            'cotizaciones.*.detalle.*.total' => 'total',
            'detalle.*.almacen_idalmacen' => 'almacén',
            'detalle.*.precioUnitario' => 'precio unitario',
            'detalle.*.cantidad' => 'cantidad',
            'detalle.*.descuento' => 'descuento',
            'detalle.*.total' => 'total',
        ];

        return $request->validate($rules, $messages, $attributes);
    }

    public function preparePayload(array $validated): array
    {
        $payload = [
            'fechaHoraEmision' => $this->nullableString($validated['fechaHoraEmision'] ?? null),
            'cliente_idcliente' => $this->nullableString($validated['cliente_idcliente'] ?? null),
            'tipoDocumento_idtipoDocumento' => $this->nullableNumber($validated['tipoDocumento_idtipoDocumento'] ?? null),
            'tipoDocumentoIDCliente' => $this->nullableString($validated['tipoDocumentoIDCliente'] ?? null),
            'personal_dniPersonal' => $this->nullableString($validated['personal_dniPersonal'] ?? null),
            'direccion' => $this->nullableString($validated['direccion'] ?? null),
            'telefono' => $this->nullableString($validated['telefono'] ?? null),
            'correo' => $this->nullableString($validated['correo'] ?? null),
            'vigenciaOferta_idvigenciaOferta' => $this->nullableNumber($validated['vigenciaOferta_idvigenciaOferta'] ?? null),
            'formaPago_idformaPago' => $this->nullableNumber($validated['formaPago_idformaPago'] ?? null),
            'moneda_idmoneda' => $this->nullableNumber($validated['moneda_idmoneda'] ?? null),
            'descuento' => $this->nullableNumber($validated['descuento'] ?? null),
            'subtotal' => $this->nullableNumber($validated['subtotal'] ?? null),
            'igv' => $this->nullableNumber($validated['igv'] ?? null),
            'total' => $this->nullableNumber($validated['total'] ?? null),
            'comentario' => $this->nullableString($validated['comentario'] ?? null),
        ];

        if (array_key_exists('nroCotizacion', $validated)) {
            $payload['nroCotizacion'] = $this->nullableString($validated['nroCotizacion']);
        }

        return $payload;
    }

    public function nullableString(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }

    public function nullableNumber(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }

    public function extractCorrelativoFromFormattedNro(string $formatted, int $tipoId): int
    {
        $td = DB::table('tipodocumento')->where('idtipoDocumento', $tipoId)->first();
        $serie = trim((string) ($td->serie ?? ''));

        if ($serie !== '' && str_starts_with($formatted, $serie)) {
            $suffix = substr($formatted, strlen($serie));
        } else {
            $suffix = preg_replace('/^.*?(\d+)$/', '$1', $formatted);
        }

        return (int) ($suffix === '' ? '0' : ltrim($suffix, '0'));
    }

    public function formatCotizacionEstadoName(?string $estado): string
    {
        return match ((string) ($estado ?? '')) {
            self::STATE_GENERADO => 'Generado',
            self::STATE_APROBADO_SP => 'Aprobado(SP)',
            self::STATE_APROBADO => 'Aprobado',
            self::STATE_EJECUTADO_SP => 'Ejecutado(SP)',
            self::STATE_FINALIZADO => 'Finalizado',
            self::STATE_ANULADO => 'Anulado',
            default => 'Desconocido',
        };
    }

    public function formatCotizacionEstadoHtmlLabel(?string $estado): string
    {
        $name = $this->formatCotizacionEstadoName($estado);
        $state = (string) ($estado ?? '');
        $style = match ($state) {
            self::STATE_GENERADO => 'color: #1d4ed8;',
            self::STATE_APROBADO_SP => 'color: #d97706;',
            self::STATE_APROBADO => 'color: #d97706;',
            self::STATE_EJECUTADO_SP => 'color: #16a34a;',
            self::STATE_FINALIZADO => 'color: #16a34a;',
            self::STATE_ANULADO => 'color: #dc2626;',
            default => 'color: #374151;',
        };

        return '<span style="' . $style . ' font-weight: 700;">' . e($name) . '</span>';
    }

    public function isCotizacionExpired(object $row): bool
    {
        $fechaHoraEmision = trim((string) ($row->fechaHoraEmision ?? ''));
        $dias = (int) ($row->vigencia_dias ?? 0);

        if ($fechaHoraEmision === '' || $dias <= 0) {
            return false;
        }

        try {
            $expiration = Carbon::parse($fechaHoraEmision)->addDays($dias)->endOfDay();
            return $expiration->isPast();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function formatMoney(mixed $value, string $currencySymbol = 'S/'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return trim($currencySymbol) . ' ' . number_format((float) $value, 2, '.', ',');
    }

    public function currencySymbol(?string $moneda): string
    {
        $moneda = trim((string) ($moneda ?? ''));
        if ($moneda === '') {
            return 'S/';
        }

        $lower = mb_strtolower($moneda, 'UTF-8');
        if (str_contains($lower, 'dolar') || str_contains($lower, 'dólar') || str_contains($lower, '$')) {
            return '$';
        }

        if (str_contains($lower, 'euro') || str_contains($lower, '€')) {
            return '€';
        }

        if (str_contains($lower, 'sol')) {
            return 'S/';
        }

        return 'S/';
    }
}
