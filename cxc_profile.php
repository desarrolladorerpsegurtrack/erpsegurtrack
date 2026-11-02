<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(App\Services\CuentasPorCobrarService::class);
$timings = [];
$serviceQuery = null;
\Illuminate\Support\Facades\DB::listen(function ($query) use (&$serviceQuery): void {
    if (
        str_starts_with(ltrim($query->sql), 'select')
        && str_contains($query->sql, '`c`.`idcuentasPorCobrar`')
    ) {
        $serviceQuery = [$query->sql, $query->bindings];
    }
});
$filters = [
    'service_client' => '',
    'service_months' => ['octubre'],
    'service_year' => '2026',
];
$invoke = function (string $name, array $arguments = []) use ($service, &$timings): mixed {
    $method = new ReflectionMethod($service, $name);
    $method->setAccessible(true);
    $start = microtime(true);
    $result = $method->invokeArgs($service, $arguments);
    $timings[] = [$name, round(microtime(true) - $start, 3)];

    return $result;
};

$services = $invoke('loadServices', [$filters]);
$plan = $serviceQuery
    ? \Illuminate\Support\Facades\DB::select('EXPLAIN ' . $serviceQuery[0], $serviceQuery[1])
    : [];
$indexes = [];
foreach (['cuentasporcobrar', 'serviciocliente', 'cuentasporcobrar_serviciocliente'] as $table) {
    $indexes[$table] = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM `' . $table . '`');
}
$maps = $invoke('loadDeviceMaps', [$services]);
$history = $invoke('loadHistoricalCharges', [$services, $filters]);
$deadlines = $invoke('loadCreditDeadlines', [$history]);
$types = DB::table('tipocobro')
    ->get(['idtipoCobros', 'recurrencia', 'tiempo'])
    ->keyBy('idtipoCobros');
$services = $invoke('decorateServices', [$services, $maps]);
$services = $invoke('appendHistoricalPeriods', [$services, $history]);
[$services, $children] = $invoke('appendCreditInstallmentReceivables', [$services, $filters]);
$linked = $invoke('loadLinkedServiceIdsByCxc', [$services]);
$groups = $invoke('groupServices', [$services, $history, $deadlines, $types, $children, $linked]);
$invoke('sortGroups', [&$groups]);

var_export($timings);
echo PHP_EOL . 'services=' . count($services) . ' groups=' . count($groups) . PHP_EOL;
echo 'EXPLAIN=' . PHP_EOL;
foreach ($plan as $row) {
    echo json_encode([
        $row->table,
        $row->type,
        $row->possible_keys,
        $row->key,
        $row->rows,
        $row->Extra,
    ]) . PHP_EOL;
}
echo PHP_EOL . 'SERVICE_STATE_COLUMN=' . json_encode(
    \Illuminate\Support\Facades\DB::select('SHOW FULL COLUMNS FROM serviciocliente WHERE Field = ?', ['estado'])
) . PHP_EOL;
echo 'INDEXES=' . PHP_EOL;
foreach ($indexes as $table => $rows) {
    echo $table . ': ' . implode(', ', array_unique(array_map(fn($row) => $row->Key_name, $rows))) . PHP_EOL;
}
