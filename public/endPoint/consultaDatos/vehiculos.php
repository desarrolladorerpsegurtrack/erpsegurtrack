<?php
declare(strict_types=1);

/**
 * API de consulta de vehículos por placa - SegurTrack
 *
 * Endpoint : /vehiculos.php
 * GET      : /vehiculos.php?placa=A0C889
 * POST     : /vehiculos.php   body JSON: {"placa": "A0C889"}
 *
 * Respuestas JSON:
 *   200 encontrada | 404 válida sin resultados | 400 placa/JSON inválido
 *   405 método no permitido | 503 error de conexión BD | 500 error interno
 *
 * Normalización de placa (resumen):
 *   - Mayúsculas; cualquier carácter no alfanumérico actúa como separador.
 *   - Si los fragmentos suman 6 caracteres -> esa es la placa.
 *   - Si suman más de 6 -> se buscan grupos de fragmentos consecutivos que sumen 6
 *     con formato LLLDDD, LDLDDD o DDDLLL (en ese orden de prioridad).
 *     Si hay dos candidatas distintas con igual prioridad -> ambigua (se rechaza).
 *   - Si suman menos de 6 -> inválida.
 */

// ============================================================
// 1. RESPUESTA JSON
// ============================================================
function responder(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function responderError(int $status, string $code, string $mensaje): never
{
    responder($status, ['success' => false, 'error' => ['code' => $code, 'message' => $mensaje]]);
}

// ============================================================
// 2. CONFIGURACIÓN (.env)
// ============================================================
function cargarEnv(): array
{
    // Busca el .env junto a este archivo o un nivel arriba (fuera del web root).
    foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $ruta) {
        if (is_readable($ruta)) {
            $env = [];
            foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
                $linea = trim($linea);
                if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
                    continue;
                }
                [$clave, $valor] = explode('=', $linea, 2);
                $env[trim($clave)] = trim(trim($valor), "\"'");
            }
            return $env;
        }
    }
    return [];
}

// ============================================================
// 3. NORMALIZACIÓN Y VALIDACIÓN DE PLACA
// ============================================================
const PLACA_LONGITUD = 6;
const PLACA_ENTRADA_MAX = 50;
// Orden = prioridad ante ambigüedades. L = letra, D = dígito.
const PLACA_FORMATOS = [
    '/^[A-Z]{3}\d{3}$/',      // LLLDDD  ADS845
    '/^[A-Z]\d[A-Z]\d{3}$/',  // LDLDDD  A0C889
    '/^\d{3}[A-Z]{3}$/',      // DDDLLL  457DFE
];

function prioridadFormato(string $candidata): int
{
    foreach (PLACA_FORMATOS as $i => $regex) {
        if (preg_match($regex, $candidata) === 1) {
            return $i;
        }
    }
    return -1;
}

/** @return array{valida: bool, placa: ?string, motivo: ?string} */
function normalizarPlaca(mixed $entrada): array
{
    $invalida = fn(string $m): array => ['valida' => false, 'placa' => null, 'motivo' => $m];

    if (!is_string($entrada)) {
        return $invalida('La placa debe enviarse como texto.');
    }
    if (strlen($entrada) > PLACA_ENTRADA_MAX) {
        return $invalida('La entrada supera los ' . PLACA_ENTRADA_MAX . ' caracteres.');
    }

    $fragmentos = preg_split('/[^A-Z0-9]+/', strtoupper($entrada), -1, PREG_SPLIT_NO_EMPTY);
    $total = implode('', $fragmentos);

    if (strlen($total) < PLACA_LONGITUD) {
        return $invalida('La placa debe tener ' . PLACA_LONGITUD . ' caracteres alfanuméricos.');
    }
    if (strlen($total) === PLACA_LONGITUD) {
        return ['valida' => true, 'placa' => $total, 'motivo' => null];
    }

    // Más de 6: ventanas de fragmentos consecutivos que sumen exactamente 6.
    $candidatas = []; // placa => prioridad
    $n = count($fragmentos);
    for ($i = 0; $i < $n; $i++) {
        $acum = '';
        for ($j = $i; $j < $n; $j++) {
            $acum .= $fragmentos[$j];
            if (strlen($acum) > PLACA_LONGITUD) {
                break;
            }
            if (strlen($acum) === PLACA_LONGITUD) {
                $p = prioridadFormato($acum);
                if ($p !== -1) {
                    $candidatas[$acum] = $p;
                }
                break;
            }
        }
    }

    if (!$candidatas) {
        return $invalida('No se encontró una placa con formato válido dentro de la entrada.');
    }

    $mejor = min($candidatas);
    $ganadoras = array_keys(array_filter($candidatas, fn($p) => $p === $mejor));
    if (count($ganadoras) > 1) {
        return $invalida('La entrada es ambigua: contiene más de una placa posible.');
    }
    return ['valida' => true, 'placa' => (string)$ganadoras[0], 'motivo' => null];
}

// ============================================================
// 4. CONEXIÓN A BD
// ============================================================
function conectarBD(array $env): PDO
{
    foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_NAME'] as $k) {
        if (empty($env[$k])) {
            throw new RuntimeException("Variable de entorno faltante: $k");
        }
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'], (int)$env['DB_PORT'], $env['DB_NAME']);

    return new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => (int)($env['DB_CONNECT_TIMEOUT'] ?? 10),
    ]);
}

// ============================================================
// 5. CONSULTA (parametrizada)
// ============================================================
function buscarVehiculoPorPlaca(PDO $pdo, string $placa): ?array
{
    $sql = "
        SELECT
            v.placa,
            c.razonSocial,
            ct.nombreApellido AS contacto_nombre,
            ct.numero         AS contacto_numero,
            tc.detalle        AS tipo_contacto
        FROM vehiculo v
        INNER JOIN cliente c
            ON c.idcliente = v.cliente_idcliente
        LEFT JOIN contacto ct
            ON ct.cliente_idcliente = c.idcliente
        LEFT JOIN tipoContacto tc
            ON tc.idtipoContacto = ct.tipoContacto_idtipoContacto
        WHERE v.placa = :placa
        ORDER BY
            CASE WHEN tc.detalle = 'Emergencia' THEN 0 ELSE 1 END,
            ct.idcontacto ASC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':placa' => $placa]);
    $fila = $stmt->fetch();
    return $fila === false ? null : $fila;
}

// ============================================================
// 6. CONTROLADOR
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    responderError(405, 'METODO_NO_PERMITIDO', 'Método no permitido. Use GET o POST.');
}

// --- Obtener la placa cruda según el método ---
if ($metodo === 'GET') {
    $entrada = $_GET['placa'] ?? null;
} else {
    $cuerpo = file_get_contents('php://input');
    if ($cuerpo !== '' && $cuerpo !== false) {
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            responderError(400, 'JSON_INVALIDO', 'El cuerpo de la solicitud no es un JSON válido.');
        }
        $entrada = $datos['placa'] ?? null;
    } else {
        $entrada = $_POST['placa'] ?? null; // también acepta formulario
    }
}

// --- Normalizar y validar (misma lógica para GET y POST) ---
$resultado = normalizarPlaca($entrada);
if (!$resultado['valida']) {
    responderError(400, 'PLACA_INVALIDA', 'La placa enviada no es válida. ' . $resultado['motivo']);
}
$placa = $resultado['placa'];

// --- Consultar ---
$env = cargarEnv();

try {
    $pdo = conectarBD($env);
} catch (Throwable $e) {
    error_log('[vehiculos] Fallo de conexión BD: ' . $e->getMessage());
    responderError(503, 'ERROR_BASE_DATOS', 'No fue posible conectar con la base de datos. Intente nuevamente más tarde.');
}

try {
    $fila = buscarVehiculoPorPlaca($pdo, $placa);
} catch (PDOException $e) {
    error_log('[vehiculos] Error en consulta: ' . $e->getMessage());
    // 2006 / 2013: servidor MySQL desconectado durante la consulta
    $perdida = in_array((int)($e->errorInfo[1] ?? 0), [2006, 2013], true);
    if ($perdida) {
        responderError(503, 'ERROR_BASE_DATOS', 'No fue posible conectar con la base de datos. Intente nuevamente más tarde.');
    }
    responderError(500, 'ERROR_INTERNO', 'Ocurrió un error interno en el servidor.');
} catch (Throwable $e) {
    error_log('[vehiculos] Error interno: ' . $e->getMessage());
    responderError(500, 'ERROR_INTERNO', 'Ocurrió un error interno en el servidor.');
}

if ($fila === null) {
    responderError(404, 'VEHICULO_NO_ENCONTRADO', "No se encontró ningún vehículo con la placa $placa.");
}

responder(200, [
    'success'          => true,
    'placaNormalizada' => $placa,
    'data'             => [
        'placa'          => $fila['placa'],
        'razonSocial'    => $fila['razonSocial'],
        'contactoNombre' => $fila['contacto_nombre'],
        'contactoNumero' => $fila['contacto_numero'],
        'tipoContacto'   => $fila['tipo_contacto'],
    ],
]);
