<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Exportación Clientes</title>
    <style>
        body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; }
        .client-block { margin-bottom: 24px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
        th, td { border: 1px solid #333; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; }
        .section-title { font-weight: bold; margin: 8px 0; }
        .pdf-header {
            position: relative;
            padding: 12px 0 15px;
            text-align: center;
        }
        .pdf-logo {
            position: absolute;
            top: 0;
            left: 0;
            width: 100px;
            height: auto;
        }
        h2 {
            font-size: 28px;
            margin: 0;
            line-height: 1.2;
            font-weight: 800;
            white-space: normal;
            word-break: break-word;
        }
    </style>
</head>
@php
    $logoPath = public_path('images/logo-main.png');
    $logo = '';
    if (file_exists($logoPath)) {
        $logo = base64_encode(file_get_contents($logoPath));
    }
@endphp
<body>
    <div class="pdf-header">
        @if($logo)
            <img
                src="data:image/png;base64,{{ $logo }}"
                alt="Logo"
                class="pdf-logo"
            >
        @endif
        <h2>Listado de Clientes (Selección)</h2>
    </div>

    @foreach($groups as $group)
        @php $cliente = $group['cliente'] ?? null; @endphp
        <div class="client-block">
            @if($cliente)
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>RUC/DNI Cliente</th>
                            <th>Nombre Comercial</th>
                            <th>Razón Social</th>
                            <th>Grupo Asignado</th>
                            <th>Rubro</th>
                            <th>Dirección</th>
                            <th>Estado Cliente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Cliente</td>
                            <td>{{ $cliente->idcliente ?? '' }}</td>
                            <td>{{ $cliente->nombreComercial ?? '' }}</td>
                            <td>{{ $cliente->razonSocial ?? '' }}</td>
                            <td>{{ $cliente->grupo_asignado ?? '' }}</td>
                            <td>{{ $cliente->rubro ?? '' }}</td>
                            <td>{!! nl2br(e($cliente->direccion_completa ?? '')) !!}</td>
                            <td>{{ $cliente->estadoDetalle ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @if(!empty($group['servicios']))
                <div class="section-title">Servicios</div>
                <table>
                    <thead>
                        <tr>
                            <th>ID Servicio</th>
                            <th>Placa Vehículo</th>
                            <th>Almacén</th>
                            <th>Fecha Inicio</th>
                            <th>Fecha Vencimiento</th>
                            <th>Monto</th>
                            <th>Estado Servicio</th>
                            <th>Documento Referencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['servicios'] as $s)
                            <tr>
                                <td>{{ $s->idservicioCliente ?? '' }}</td>
                                <td>{{ $s->vehiculo_placa ?? '' }}</td>
                                <td>{{ $s->almacen_detalle ?? '' }}</td>
                                <td>{{ $s->fechaInicio ?? '' }}</td>
                                <td>{{ $s->fecheVencimiento ?? '' }}</td>
                                <td>{{ $s->monto ?? '' }}</td>
                                <td>{{ $s->estado ?? '' }}</td>
                                <td>{{ $s->docReferencia ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if(!empty($group['vehiculos']))
                <div class="section-title">Vehículos</div>
                <table>
                    <thead>
                        <tr>
                            <th>Placa</th>
                            <th>Tipo Vehículo</th>
                            <th>Año</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Color</th>
                            <th>Tracto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['vehiculos'] as $v)
                            <tr>
                                <td>{{ $v->placa ?? '' }}</td>
                                <td>{{ $v->tipo_vehiculo ?? '' }}</td>
                                <td>{{ $v->anio ?? '' }}</td>
                                <td>{{ $v->marca ?? '' }}</td>
                                <td>{{ $v->modelo ?? '' }}</td>
                                <td>{{ $v->color ?? '' }}</td>
                                <td>{{ $v->tracto ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if(!empty($group['dispositivos']))
                <div class="section-title">Dispositivos</div>
                <table>
                    <thead>
                        <tr>
                            <th>ID Dispositivo</th>
                            <th>Placa Vehículo</th>
                            <th>Marca Dispositivo</th>
                            <th>Modelo Dispositivo</th>
                            <th>Fecha Instalación</th>
                            <th>Fecha Baja</th>
                            <th>Estado Dispositivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['dispositivos'] as $d)
                            <tr>
                                <td>{{ $d->iddispositivoCliente ?? '' }}</td>
                                <td>{{ $d->vehiculo_placa ?? '' }}</td>
                                <td>{{ $d->marcaDispositivo ?? '' }}</td>
                                <td>{{ $d->modeloDispositivo ?? '' }}</td>
                                <td>{{ $d->fechaInstalacion ?? '' }}</td>
                                <td>{{ $d->fechaBaja ?? '' }}</td>
                                <td>{{ $d->estado ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

</body>
</html>