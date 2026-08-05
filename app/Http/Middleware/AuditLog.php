<?php

namespace App\Http\Middleware;

use App\Support\ErpPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AuditLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $authData = $request->session()->get('erp_auth', []);
        $contexto = $this->resolverContextoAuditoria($request);
        $registroAnterior = $this->capturarRegistroAnterior($request, $contexto);
        $registrosMasivos = $this->capturarRegistrosMasivos($request, $contexto);
        $vistaPreviaImportacion = $this->capturarVistaPreviaImportacion($request, $contexto);

        try {
            $response = $next($request);

            $this->registrarAuditoria(
                $request,
                $response->getStatusCode(),
                $authData,
                $contexto,
                $registroAnterior,
                $registrosMasivos,
                $vistaPreviaImportacion
            );

            return $response;
        } catch (Throwable $exception) {
            $statusCode = method_exists($exception, 'getStatusCode')
                ? (int) $exception->getStatusCode()
                : 500;

            $this->registrarAuditoria(
                $request,
                $statusCode,
                $authData,
                $contexto,
                $registroAnterior,
                $registrosMasivos,
                $vistaPreviaImportacion,
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    private function registrarAuditoria(
        Request $request,
        int $statusCode,
        array $authData,
        array $contexto,
        ?array $registroAnterior,
        array $registrosMasivos,
        array $vistaPreviaImportacion,
        ?string $error = null
    ): void
    {
        try {
            DB::connection('audit')->table('auditoria')->insert([
                'usuario' => (string) ($authData['usuario'] ?? 'anonimo'),
                'personal_dni' => isset($authData['personal_dni']) ? (string) $authData['personal_dni'] : null,
                'modulo' => $this->resolverModulo($request),
                'accion' => $this->resolverAccion($request, $contexto, $registroAnterior, $registrosMasivos, $vistaPreviaImportacion),
                'metodo_http' => strtoupper($request->method()),
                'ruta' => (string) $request->path(),
                'nombre_ruta' => (string) ($request->route()?->getName() ?? ''),
                'parametros' => json_encode($this->sanitizarDatos($request->all()), JSON_UNESCAPED_UNICODE),
                'ip_address' => (string) ($request->ip() ?? ''),
                'user_agent' => (string) ($request->userAgent() ?? ''),
                'resultado' => $statusCode >= 400 ? 'error' : 'success',
                'codigo_http' => $statusCode,
                'mensaje' => $error,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            logger()->error('Audit insert failed', [
                'exception' => $exception,
                'route' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'user' => $authData['usuario'] ?? null,
                'params' => $request->all(),
            ]);
            // La auditoria nunca debe romper el flujo principal.
        }
    }

    private function resolverContextoAuditoria(Request $request): array
    {
        $routeName = (string) ($request->route()?->getName() ?? '');
        $permissionKey = ErpPermission::resolvePermissionKeyFromRouteName($routeName) ?? '';

        return match ($permissionKey) {
            'personal' => ['label' => 'personal', 'table' => 'personal', 'primaryKey' => 'dniPersonal'],
            'roles' => ['label' => 'rol', 'table' => 'rol', 'primaryKey' => 'idrol'],
            'usuarios' => ['label' => 'usuario', 'table' => 'usuario', 'primaryKey' => 'usuario'],
            'clientes.cliente' => ['label' => 'cliente', 'table' => 'cliente', 'primaryKey' => 'idcliente'],
            'clientes' => ['label' => 'cliente', 'table' => 'cliente', 'primaryKey' => 'idcliente'],
            'clientes.grupo_cliente' => ['label' => 'grupo de cliente', 'table' => 'grupocliente', 'primaryKey' => 'idgrupoCliente'],
            'clientes.credenciales' => ['label' => 'credencial', 'table' => 'credencialcliente', 'primaryKey' => 'idcredencialCliente'],
            'configuracion.estado' => ['label' => 'estado cliente', 'table' => 'estadocliente', 'primaryKey' => 'idestadoCliente'],
            'configuracion.tipo_contacto' => ['label' => 'tipo de contacto', 'table' => 'tipocontacto', 'primaryKey' => 'idtipoContacto'],
            'configuracion.ubigeo' => ['label' => 'ubigeo', 'table' => 'ubigeo', 'primaryKey' => 'idubigeo'],
            'configuracion.cargo' => ['label' => 'cargo', 'table' => 'cargopersonal', 'primaryKey' => 'idcargoPersonal'],
            'configuracion.moneda' => ['label' => 'moneda', 'table' => 'moneda', 'primaryKey' => 'idmoneda'],
            'configuracion.tributo' => ['label' => 'tributo', 'table' => 'tributo', 'primaryKey' => 'idtributo'],
            'configuracion.unidad_medida' => ['label' => 'unidad de medida', 'table' => 'unidadmedida', 'primaryKey' => 'idunidadMedida'],
            'configuracion.empresapropietaria' => ['label' => 'empresa propietaria', 'table' => 'empresapropietaria', 'primaryKey' => 'RUC'],
            'configuracion.modelo' => ['label' => 'modelo', 'table' => 'modelo', 'primaryKey' => 'idmodelo'],
            'configuracion.marca' => ['label' => 'marca', 'table' => 'marca', 'primaryKey' => 'idmarca'],
            'configuracion.tecnologia' => ['label' => 'tecnología', 'table' => 'tecnologia', 'primaryKey' => 'idtecnologia'],
            'configuracion.tipo_gasto' => ['label' => 'tipo de gasto', 'table' => 'tipogasto', 'primaryKey' => 'idtipoGasto'],
            'configuracion.tipo_cobro' => ['label' => 'tipo de cobro', 'table' => 'tipocobro', 'primaryKey' => 'idtipoCobros'],
            'configuracion.tipo_plataforma' => ['label' => 'tipo de plataforma', 'table' => 'tipoplataforma', 'primaryKey' => 'idtipoPlataforma'],
            'configuracion.plataforma' => ['label' => 'plataforma', 'table' => 'plataforma', 'primaryKey' => 'idplataforma'],
            'configuracion.tipo_elemento' => ['label' => 'tipo de elemento', 'table' => 'tipoelemento', 'primaryKey' => 'idtipoElemento'],
            'configuracion.tipo_documento' => ['label' => 'tipo de documento', 'table' => 'tipodocumento', 'primaryKey' => 'idtipoDocumento'],
            'configuracion.forma_pago' => ['label' => 'forma de pago', 'table' => 'formapago', 'primaryKey' => 'idformaPago'],
            'configuracion.paquetes' => ['label' => 'paquete', 'table' => 'paquetes', 'primaryKey' => 'idpaquetes'],
            'configuracion.entidad_bancaria' => ['label' => 'entidad bancaria', 'table' => 'entidadbancaria', 'primaryKey' => 'identidadBancaria'],
            'configuracion.operador' => ['label' => 'operador', 'table' => 'operador', 'primaryKey' => 'idoperador'],
            'configuracion.tipo_vehiculo' => ['label' => 'tipo de vehículo', 'table' => 'tipovehiculo', 'primaryKey' => 'idtipoVehiculo'],
            'configuracion.tipo_operacion' => ['label' => 'tipo de operación', 'table' => 'tipooperacion', 'primaryKey' => 'idtipoOperacion'],
            'configuracion.lista_precio' => ['label' => 'lista de precio', 'table' => 'listaprecio', 'primaryKey' => 'idListaPrecio'],
            'configuracion.detalle_lista_precio' => ['label' => 'detalle de lista de precio', 'table' => 'detallelistaprecio', 'primaryKey' => 'iddetalleListaPrecio'],
            'almacen.elemento_almacen' => ['label' => 'elemento de almacén', 'table' => 'elementoalmacen', 'primaryKey' => 'imei'],
            'almacen.nota_ingreso' => ['label' => 'nota de ingreso', 'table' => 'compras', 'primaryKey' => 'idcompras'],
            'almacen.nota_salida' => ['label' => 'nota de salida', 'table' => 'compras', 'primaryKey' => 'idcompras'],
            'configuracion.tipo_pedido' => ['label' => 'tipo de pedido', 'table' => 'tipopedido', 'primaryKey' => 'idtipoPedido'],
            'configuracion.proveedor' => ['label' => 'proveedor', 'table' => 'proveedor', 'primaryKey' => 'idproveedor'],
            'configuracion.certificadosunat' => ['label' => 'certificado SUNAT', 'table' => 'certificadosunat', 'primaryKey' => 'idcertificadoSUNAT'],
            'configuracion.vigencia_oferta' => ['label' => 'vigencia de oferta', 'table' => 'vigenciaoferta', 'primaryKey' => 'idvigenciaOferta'],
            'configuracion.auditoria' => ['label' => 'auditoría', 'table' => 'auditoria', 'primaryKey' => 'id'],
            'lineas_chips.numero_telefonico' => ['label' => 'número telefónico', 'table' => 'numerotelefonico', 'primaryKey' => 'numeroTelefonico'],
            'lineas_chips.simcard' => ['label' => 'simcard', 'table' => 'simcard', 'primaryKey' => 'idsimCard'],
            'lineas_chips.detallesimcard' => ['label' => 'detalle simcard', 'table' => 'detallesimcard', 'primaryKey' => 'iddetalleSimCard'],
            'lineas_chips.numero_dispositivo' => ['label' => 'número de dispositivo', 'table' => 'detnumerosdispositivo', 'primaryKey' => 'iddetNumerosDispositivo'],
            default => ['label' => $this->etiquetaContextoDesdeRuta($routeName, $permissionKey), 'table' => null, 'primaryKey' => null],
        };
    }

    private function etiquetaContextoDesdeRuta(string $routeName, string $permissionKey): string
    {
        if ($permissionKey !== '') {
            $label = str_replace(['_', '.'], ' ', $permissionKey);
            return trim($label);
        }

        $routeName = str_replace(['modules.', '-', '_'], ' ', $routeName);
        $routeName = preg_replace('/\b(index|create|store|edit|update|destroy|export|bulk destroy|bulk deactivate|import preview|import process|parse file)\b/i', '', $routeName) ?? $routeName;

        return trim(preg_replace('/\s+/', ' ', $routeName) ?? 'registro');
    }

    private function capturarRegistroAnterior(Request $request, array $contexto): ?array
    {
        $table = (string) ($contexto['table'] ?? '');
        $primaryKey = (string) ($contexto['primaryKey'] ?? '');

        if ($table === '' || $primaryKey === '') {
            return null;
        }

        $identifier = $this->resolverIdentificadorAuditoria($request, $contexto);
        if ($identifier === null || $identifier === '') {
            return null;
        }

        try {
            $record = DB::table($table)->where($primaryKey, $identifier)->first();

            return $record ? (array) $record : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function capturarRegistrosMasivos(Request $request, array $contexto): array
    {
        $table = (string) ($contexto['table'] ?? '');
        $primaryKey = (string) ($contexto['primaryKey'] ?? '');
        $routeName = (string) ($request->route()?->getName() ?? '');

        if ($table === '' || $primaryKey === '' || !str_contains($routeName, 'bulk-destroy')) {
            return [];
        }

        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            return [];
        }

        $selectedIds = array_values(array_filter(array_map('trim', $selectedIds), fn ($value) => $value !== ''));
        if (empty($selectedIds)) {
            return [];
        }

        try {
            return DB::table($table)
                ->whereIn($primaryKey, $selectedIds)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function capturarVistaPreviaImportacion(Request $request, array $contexto): array
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        if (!str_contains($routeName, 'import')) {
            return [];
        }

        $preview = $request->session()->get('detallesimcard_import_preview', []);
        return is_array($preview) ? $preview : [];
    }

    private function resolverAccion(Request $request, array $contexto, ?array $registroAnterior, array $registrosMasivos, array $vistaPreviaImportacion): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');
        $operacion = $this->resolverOperacionAuditoria($request);
        $label = (string) ($contexto['label'] ?? 'registro');

        if ($operacion === 'exportar') {
            $format = mb_strtolower((string) ($request->route('format') ?? ''));
            $format = $format !== '' ? $format : 'archivo';

            return trim("exportar {$label} en {$format}");
        }

        if (str_contains($routeName, '.edit')) {
            $identifier = $this->resolverIdentificadorAuditoria($request, $contexto);

            return $identifier !== null && $identifier !== ''
                ? "abrir edición de {$label} (id: {$identifier})"
                : "abrir edición de {$label}";
        }

        if ($routeName === 'locks.acquire' || $routeName === 'locks.release') {
            $resource = (string) ($request->route('resource') ?? '');
            $identifier = trim((string) ($request->route('id') ?? ''));
            $resourceLabel = $this->resolverEtiquetaDesdeRecursoBloqueo($resource);
            $verbo = $routeName === 'locks.acquire' ? 'adquirir bloqueo' : 'liberar bloqueo';

            if ($identifier !== '') {
                return "{$verbo} de {$resourceLabel} (recurso: {$resource}, id: {$identifier})";
            }

            return $resource !== ''
                ? "{$verbo} de {$resourceLabel} (recurso: {$resource})"
                : $verbo;
        }

        if (str_contains($routeName, 'import.preview')) {
            $detalle = $this->resumirCargaDetallada($request, $vistaPreviaImportacion);
            return $detalle !== ''
                ? "previsualizar carga de {$label} ({$detalle})"
                : "previsualizar carga de {$label}";
        }

        if (str_contains($routeName, 'import.process')) {
            $detalle = $this->resumirCargaDetallada($request, $vistaPreviaImportacion);
            return $detalle !== ''
                ? "cargar {$label} ({$detalle})"
                : "cargar {$label}";
        }

        if (str_contains($routeName, 'parse-file')) {
            $detalle = $this->resumirBajaDesdeArchivo($request);
            return $detalle !== ''
                ? "previsualizar baja de {$label} ({$detalle})"
                : "previsualizar baja de {$label}";
        }

        if (str_contains($routeName, 'bulk-deactivate')) {
            $detalle = $this->resumenBajaMasiva($request);
            return $detalle !== ''
                ? "dar de baja {$label} ({$detalle})"
                : "dar de baja {$label}";
        }

        if (str_contains($routeName, 'bulk-destroy')) {
            $detalle = $this->formatearRegistrosAuditoria($registrosMasivos);
            return $detalle !== ''
                ? "eliminar {$label} ({$detalle})"
                : "eliminar {$label}";
        }

        if ($operacion === 'crear') {
            $datos = $this->extraerDatosFormulario($request);
            $detalle = $this->formatearDatosAuditoria($datos);

            return $detalle !== ''
                ? "crear {$label} ({$detalle})"
                : "crear {$label}";
        }

        if ($operacion === 'editar') {
            $datosActuales = $this->extraerDatosFormulario($request);
            $detalleAntes = $registroAnterior ? $this->formatearDatosAuditoria($registroAnterior) : '';
            $detalleDespues = $this->formatearDatosAuditoria($datosActuales);

            if ($detalleAntes !== '' || $detalleDespues !== '') {
                return trim("editar {$label} (antes: {$detalleAntes}; después: {$detalleDespues})");
            }

            return "editar {$label}";
        }

        if ($operacion === 'eliminar') {
            if ($registroAnterior !== null) {
                $detalle = $this->formatearDatosAuditoria($registroAnterior);
                return $detalle !== ''
                    ? "eliminar {$label} ({$detalle})"
                    : "eliminar {$label}";
            }

            $datos = $this->extraerDatosFormulario($request);
            $detalle = $this->formatearDatosAuditoria($datos);

            return $detalle !== ''
                ? "eliminar {$label} ({$detalle})"
                : "eliminar {$label}";
        }

        if ($operacion === 'ver') {
            return "ver {$label}";
        }

        return $operacion !== '' ? trim("{$operacion} {$label}") : $label;
    }

    private function resolverOperacionAuditoria(Request $request): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');
        $path = strtolower((string) $request->path());

        if (str_contains($path, '/export/') || str_contains($routeName, '.export')) {
            return 'exportar';
        }

        if ($routeName === 'locks.acquire') {
            return 'adquirir bloqueo';
        }

        if ($routeName === 'locks.release') {
            return 'liberar bloqueo';
        }

        if (str_contains($routeName, '.import.preview')) {
            return 'previsualizar';
        }

        if (str_contains($routeName, '.import.process')) {
            return 'cargar';
        }

        if (str_contains($routeName, '.bulk-deactivate')) {
            return 'dar de baja';
        }

        if (str_contains($routeName, '.bulk-destroy')) {
            return 'eliminar';
        }

        return match (strtoupper($request->method())) {
            'GET' => 'ver',
            'POST' => 'crear',
            'PUT', 'PATCH' => 'editar',
            'DELETE' => 'eliminar',
            default => strtolower($request->method()),
        };
    }

    private function resolverIdentificadorAuditoria(Request $request, array $contexto): ?string
    {
        $primaryKey = (string) ($contexto['primaryKey'] ?? '');
        if ($primaryKey !== '' && $request->filled($primaryKey)) {
            return trim((string) $request->input($primaryKey, ''));
        }

        $routeParameters = $request->route()?->parameters() ?? [];
        foreach ($routeParameters as $parameter) {
            if (is_scalar($parameter) && $parameter !== '') {
                return trim((string) $parameter);
            }
        }

        return null;
    }

    private function extraerDatosFormulario(Request $request): array
    {
        $data = $request->except(['_token', '_method', 'importToken']);
        $files = $request->allFiles();

        foreach ($files as $key => $file) {
            $data[$key] = $this->normalizarValorAuditoria($file);
        }

        return $data;
    }

    private function resolverEtiquetaDesdeRecursoBloqueo(string $resource): string
    {
        $resourceKey = ErpPermission::normalizePermissionKey($resource) ?? $resource;

        return match ($resourceKey) {
            'configuracion.estado' => 'estado cliente',
            'configuracion.tipo_contacto' => 'tipo de contacto',
            'configuracion.cargo' => 'cargo',
            'configuracion.moneda' => 'moneda',
            'configuracion.tributo' => 'tributo',
            'configuracion.unidad_medida' => 'unidad de medida',
            'configuracion.empresapropietaria' => 'empresa propietaria',
            'configuracion.modelo' => 'modelo',
            'configuracion.marca' => 'marca',
            'configuracion.tecnologia' => 'tecnología',
            'configuracion.tipo_gasto' => 'tipo de gasto',
            'configuracion.tipo_cobro' => 'tipo de cobro',
            'configuracion.tipo_plataforma' => 'tipo de plataforma',
            'configuracion.plataforma' => 'plataforma',
            'configuracion.tipo_elemento' => 'tipo de elemento',
            'configuracion.tipo_documento' => 'tipo de documento',
            'configuracion.forma_pago' => 'forma de pago',
            'configuracion.entidad_bancaria' => 'entidad bancaria',
            'configuracion.operador' => 'operador',
            'almacen.elemento_almacen' => 'elemento de almacén',
            'almacen.nota_ingreso' => 'nota de ingreso',
            'almacen.nota_salida' => 'nota de salida',
            'configuracion.tipo_vehiculo' => 'tipo de vehículo',
            'configuracion.tipo_operacion' => 'tipo de operación',
            'configuracion.lista_precio' => 'lista de precio',
            'configuracion.detalle_lista_precio' => 'detalle de lista de precio',
            'configuracion.tipo_pedido' => 'tipo de pedido',
            'configuracion.proveedor' => 'proveedor',
            'configuracion.certificadosunat' => 'certificado SUNAT',
            'configuracion.vigencia_oferta' => 'vigencia de oferta',
            'clientes.cliente' => 'cliente',
            'clientes.credenciales' => 'credencial',
            'clientes.grupo_cliente' => 'grupo de cliente',
            'lineas_chips.numero_telefonico' => 'número telefónico',
            'lineas_chips.simcard' => 'simcard',
            'lineas_chips.detallesimcard' => 'detalle simcard',
            'lineas_chips.numero_dispositivo' => 'número de dispositivo',
            'personal' => 'personal',
            'roles' => 'rol',
            'usuarios' => 'usuario',
            'vehiculos' => 'vehículo',
            'servicio_cliente' => 'servicio cliente',
            default => trim(str_replace(['_', '.'], ' ', $resourceKey)) ?: 'recurso',
        };
    }

    private function resumenBajaMasiva(Request $request): string
    {
        $selectedNumbers = $request->input('selectedNumbers', []);
        if (!is_array($selectedNumbers)) {
            $selectedNumbers = [];
        }

        $manualNumbers = trim((string) $request->input('manualNumbers', ''));
        if ($manualNumbers !== '') {
            $manual = array_filter(array_map('trim', explode(',', $manualNumbers)), fn ($value) => $value !== '');
            $selectedNumbers = array_unique(array_merge($selectedNumbers, $manual));
        }

        $selectedNumbers = array_values(array_filter(array_map('trim', $selectedNumbers), fn ($value) => $value !== ''));
        if (empty($selectedNumbers)) {
            return '';
        }

        $resumen = implode(', ', array_slice($selectedNumbers, 0, 20));
        if (count($selectedNumbers) > 20) {
            $resumen .= ' ...';
        }

        $deactivateSimCards = (bool) $request->input('deactivateSimCards', false);
        if ($deactivateSimCards) {
            return "números: {$resumen}; también simcards";
        }

        return "números: {$resumen}";
    }

    private function resumirCargaDetallada(Request $request, array $vistaPreviaImportacion): string
    {
        $rows = $vistaPreviaImportacion['allRows'] ?? $vistaPreviaImportacion['previewRows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            $detalleArchivo = $this->resumirCargaDesdeArchivo($request, 'importFile');
            if ($detalleArchivo !== '') {
                return $detalleArchivo;
            }

            $fileNames = [];
            foreach ($request->allFiles() as $file) {
                if ($file instanceof UploadedFile) {
                    $fileNames[] = $file->getClientOriginalName();
                    continue;
                }

                if (is_array($file)) {
                    foreach ($file as $nestedFile) {
                        if ($nestedFile instanceof UploadedFile) {
                            $fileNames[] = $nestedFile->getClientOriginalName();
                        }
                    }
                }
            }

            return empty($fileNames) ? '' : 'archivo: ' . implode(', ', array_unique($fileNames));
        }

        $resumen = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $numero = trim((string) ($row['numero'] ?? $row['numeroTelefonico'] ?? ''));
            $simcard = trim((string) ($row['simcard'] ?? ''));
            $operador = trim((string) ($row['operador'] ?? ''));

            $partes = [];
            if ($simcard !== '') {
                $partes[] = 'simcard:' . $simcard;
            }
            if ($numero !== '') {
                $partes[] = 'numero:' . $numero;
            }
            if ($operador !== '') {
                $partes[] = 'operador:' . $operador;
            }

            if (!empty($partes)) {
                $resumen[] = implode(' ', $partes);
            }
        }

        if (empty($resumen)) {
            return '';
        }

        $detalle = implode(' | ', $resumen);
        if (count($rows) > 20) {
            $detalle .= ' ...';
        }

        return $detalle;
    }

    private function resumirCargaDesdeArchivo(Request $request, string $fieldName): string
    {
        $file = $request->file($fieldName);
        if (!$file instanceof UploadedFile) {
            return '';
        }

        $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));
        if ($extension !== 'xlsx' || !class_exists(IOFactory::class)) {
            return 'archivo: ' . $file->getClientOriginalName();
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            $resumen = [];

            foreach (array_slice($rows, 0, 25) as $row) {
                $columns = array_map('trim', array_values($row));
                if (count(array_filter($columns, fn ($value) => $value !== '')) === 0) {
                    continue;
                }

                $simcard = trim((string) ($columns[0] ?? ''));
                $operador = trim((string) ($columns[1] ?? ''));
                $numero = trim((string) ($columns[2] ?? ''));

                $partes = [];
                if ($simcard !== '') {
                    $partes[] = 'simcard:' . $simcard;
                }
                if ($numero !== '') {
                    $partes[] = 'numero:' . $numero;
                }
                if ($operador !== '') {
                    $partes[] = 'operador:' . $operador;
                }

                if (!empty($partes)) {
                    $resumen[] = implode(' ', $partes);
                }
            }

            if (empty($resumen)) {
                return 'archivo: ' . $file->getClientOriginalName();
            }

            $detalle = 'archivo: ' . $file->getClientOriginalName() . '; ' . implode(' | ', $resumen);
            if (count($rows) > 25) {
                $detalle .= ' ...';
            }

            return $detalle;
        } catch (Throwable) {
            return 'archivo: ' . $file->getClientOriginalName();
        }
    }

    private function resumirBajaDesdeArchivo(Request $request): string
    {
        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return '';
        }

        $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));
        if ($extension !== 'xlsx' || !class_exists(IOFactory::class)) {
            return 'archivo: ' . $file->getClientOriginalName();
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            $numbers = [];

            foreach (array_slice($rows, 0, 25) as $row) {
                $columns = array_map('trim', array_values($row));
                if (count(array_filter($columns, fn ($value) => $value !== '')) === 0) {
                    continue;
                }

                $numero = trim((string) ($columns[0] ?? ''));
                if ($numero === '' || !preg_match('/^\d+$/', str_replace(' ', '', $numero))) {
                    continue;
                }

                $numbers[] = $numero;
            }

            if (empty($numbers)) {
                return 'archivo: ' . $file->getClientOriginalName();
            }

            $detalle = 'archivo: ' . $file->getClientOriginalName() . '; números: ' . implode(', ', array_slice($numbers, 0, 20));
            if (count($numbers) > 20) {
                $detalle .= ' ...';
            }

            return $detalle;
        } catch (Throwable) {
            return 'archivo: ' . $file->getClientOriginalName();
        }
    }

    private function formatearRegistrosAuditoria(array $registros): string
    {
        if (empty($registros)) {
            return '';
        }

        $resumen = [];
        foreach (array_slice($registros, 0, 10) as $registro) {
            if (is_array($registro)) {
                $resumen[] = $this->formatearDatosAuditoria($registro);
            }
        }

        if (empty($resumen)) {
            return '';
        }

        $detalle = implode(' | ', $resumen);
        if (count($registros) > 10) {
            $detalle .= ' ...';
        }

        return $detalle;
    }

    private function formatearDatosAuditoria(array $datos): string
    {
        $excluir = [
            '_token',
            '_method',
            'importToken',
            'created_at',
            'updated_at',
            'deleted_at',
            'resultado',
            'codigo_http',
            'mensaje',
            'user_agent',
        ];

        $datos = $this->ordenarDatosAuditoria($datos, $excluir);
        $partes = [];
        foreach ($datos as $clave => $valor) {
            $valorNormalizado = $this->normalizarValorAuditoria($valor);
            if ($valorNormalizado === '') {
                continue;
            }

            $partes[] = $this->etiquetaCampoAuditoria((string) $clave) . ': ' . $valorNormalizado;
        }

        return implode('; ', $partes);
    }

    private function ordenarDatosAuditoria(array $datos, array $excluir): array
    {
        $filtrados = [];
        foreach ($datos as $clave => $valor) {
            if (in_array((string) $clave, $excluir, true)) {
                continue;
            }

            $filtrados[$clave] = $valor;
        }

        $prioridades = [
            'id' => 0,
            'dniPersonal' => 0,
            'idcliente' => 0,
            'idtipoContacto' => 0,
            'idestadoCliente' => 0,
            'idcargoPersonal' => 0,
            'numeroTelefonico' => 0,
            'idsimCard' => 0,
            'iddetalleSimCard' => 0,
            'iddetNumerosDispositivo' => 0,
            'usuario' => 1,
            'nombre' => 1,
            'razonSocial' => 1,
            'apellido' => 1,
            'apellidoPaterno' => 1,
            'apellidoMaterno' => 1,
            'detalle' => 2,
            'descripcion' => 2,
            'observacion' => 2,
            'estado' => 3,
            'activo' => 3,
            'tipo' => 3,
            'operador' => 3,
            'simcard' => 3,
            'numero' => 3,
            'email' => 4,
            'correo' => 4,
            'telefono' => 4,
            'celular' => 4,
        ];

        uksort($filtrados, function (string $leftKey, string $rightKey) use ($prioridades): int {
            $leftPriority = $prioridades[$leftKey] ?? 100;
            $rightPriority = $prioridades[$rightKey] ?? 100;

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return strcasecmp($leftKey, $rightKey);
        });

        return $filtrados;
    }

    private function etiquetaCampoAuditoria(string $clave): string
    {
        return match ($clave) {
            'id' => 'id',
            'dniPersonal' => 'dni',
            'idcliente' => 'id cliente',
            'idtipoContacto' => 'id tipo contacto',
            'idestadoCliente' => 'id estado cliente',
            'idcargoPersonal' => 'id cargo',
            'numeroTelefonico' => 'número telefónico',
            'idsimCard' => 'id simcard',
            'iddetalleSimCard' => 'id detalle simcard',
            'iddetNumerosDispositivo' => 'id número dispositivo',
            'usuario' => 'usuario',
            'nombre' => 'nombre',
            'razonSocial' => 'razón social',
            'apellido' => 'apellido',
            'apellidoPaterno' => 'apellido paterno',
            'apellidoMaterno' => 'apellido materno',
            'detalle' => 'detalle',
            'descripcion' => 'descripción',
            'observacion' => 'observación',
            'estado' => 'estado',
            'activo' => 'activo',
            'tipo' => 'tipo',
            'operador' => 'operador',
            'simcard' => 'simcard',
            'numero' => 'número',
            'email' => 'correo',
            'correo' => 'correo',
            'telefono' => 'teléfono',
            'celular' => 'celular',
            default => trim(str_replace(['_', '.'], ' ', $clave)),
        };
    }

    private function normalizarValorAuditoria(mixed $valor): string
    {
        if ($valor instanceof UploadedFile) {
            return $valor->getClientOriginalName();
        }

        if (is_array($valor)) {
            $partes = [];
            foreach ($valor as $key => $item) {
                $normalizado = $this->normalizarValorAuditoria($item);
                if ($normalizado === '') {
                    continue;
                }
                $partes[] = is_string($key) ? ($key . ':' . $normalizado) : $normalizado;
            }

            return '[' . implode('; ', $partes) . ']';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if ($valor === null) {
            return 'null';
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return '';
        }

        return str_replace(["\r", "\n"], ' ', $texto);
    }

    private function resolverModulo(Request $request): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        if (str_starts_with($routeName, 'modules.personal')) {
            return 'personal';
        }
        if (str_starts_with($routeName, 'modules.roles')) {
            return 'roles';
        }
        if (str_starts_with($routeName, 'modules.usuarios')) {
            return 'usuarios';
        }
        if (str_starts_with($routeName, 'modules.clientes')) {
            return 'clientes';
        }
        if (str_starts_with($routeName, 'modules.lineas-chips')) {
            return 'lineas_chips';
        }
        if (str_starts_with($routeName, 'modules.vehiculos')) {
            return 'vehiculos';
        }
        if (str_starts_with($routeName, 'modules.dispositivo-cliente')) {
            return 'dispositivo_cliente';
        }
        if (str_starts_with($routeName, 'modules.servicio-cliente')) {
            return 'servicio_cliente';
        }
        if (str_starts_with($routeName, 'modules.almacen')) {
            return 'almacen';
        }
        if (str_starts_with($routeName, 'modules.configuracion')) {
            return 'configuracion';
        }
        if (str_starts_with($routeName, 'modules.ticket')) {
            return 'tickets';
        }
        if (str_starts_with($routeName, 'modules.sistema')) {
            return 'sistema';
        }

        return 'sistema';
    }

    private function sanitizarDatos(array $data): array
    {
        $sensibles = [
            'password',
            'clave',
            'token',
            '_token',
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ];

        $clean = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, $sensibles, true)) {
                $clean[$key] = '[OCULTO]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizarDatos($value);
                continue;
            }

            if (is_object($value)) {
                $clean[$key] = '[OBJETO]';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
