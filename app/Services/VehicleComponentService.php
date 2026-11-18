<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleComponentService
{
    public function forVehicle(?string $plate): Collection
    {
        $plate = trim((string) $plate);
        if ($plate === '') {
            return collect();
        }

        return DB::table('dispositivocliente as dc')
            ->join('elementoalmacen as ea', 'ea.imei', '=', 'dc.iddispositivoCliente')
            ->join('almacen as a', 'a.idalmacen', '=', 'ea.dispositivo_iddispositivo')
            ->join('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->where('dc.vehiculo_placa', $plate)
            ->where('dc.estado', '1')
            ->where(function ($query) {
                $query->whereRaw('LOWER(te.nombre) like ?', ['%sensor%'])
                    ->orWhereRaw('LOWER(te.nombre) like ?', ['%accesorio%']);
            })
            ->select([
                'dc.iddispositivoCliente as imei',
                'a.detalle as producto',
                'te.nombre as categoria',
                'a.usaRedMovil',
            ])
            ->get()
            ->map(fn($component) => $this->formatComponent($component))
            ->values();
    }

    private function formatComponent(object $component): object
    {
        $category = mb_strtolower(trim((string) ($component->categoria ?? '')), 'UTF-8');
        $component->categoria = str_contains($category, 'sensor') ? 'Sensor' : 'Accesorio';

        $network = mb_strtolower(trim((string) ($component->usaRedMovil ?? '')), 'UTF-8');
        $component->usa_red_movil_label = \in_array($network, ['s', 'si', 'sí', '1', 'yes'], true)
            ? 'Sí'
            : (\in_array($network, ['n', 'no', '0'], true) ? 'No' : 'No especificado');

        return $component;
    }
}
