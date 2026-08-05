@php use Illuminate\Support\Carbon; @endphp
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Nota de ingreso</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }

        .page {
            padding: 24px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .header-table td {
            vertical-align: middle;
            border: none;
            padding: 0;
        }

        .logo {
            width: 30%;
        }

        .logo img {
            max-height: 70px;
            display: block;
        }

        .title-block {
            width: 70%;
            text-align: right;
        }

        .title-block h1 {
            margin: 0;
            font-size: 20px;
            letter-spacing: .05em;
        }

        .section {
            margin-bottom: 16px;
        }

        .section-header {
            font-size: 14px;
            font-weight: 700;
            border-bottom: 2px solid #000;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }

        .details,
        .summary {
            width: 100%;
            border-collapse: collapse;
        }

        .details td,
        .details th,
        .summary td,
        .summary th {
            padding: 6px 8px;
            border: 1px solid #ccc;
            vertical-align: top;
        }

        .details th {
            background: #f6f6f6;
            font-weight: 700;
            text-align: left;
        }

        .details td {
            font-size: 11px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .items-table th,
        .items-table td {
            padding: 8px 10px;
            border: 1px solid #ccc;
        }

        .items-table th {
            background: #f6f6f6;
            font-size: 11px;
            text-align: left;
        }

        .items-table td {
            font-size: 11px;
        }

        .items-table .imeis {
            white-space: pre-wrap;
            word-break: break-all;
        }

        .footer {
            margin-top: 18px;
            font-size: 11px;
        }
    </style>
</head>

<body>
    <div class="page">
        <table class="header-table">
            <tr>
                <td class="logo">
                    <img src="{{ public_path('images/logo-main.png') }}" alt="Logo ERP">
                </td>
                <td class="title-block">
                    <h1>NOTA DE INGRESO AL ALMACÉN</h1>
                </td>
            </tr>
        </table>

        <div class="section">
            <div class="section-header">Información de la nota</div>
            <table class="details">
                <tr>
                    <th>ID Compra</th>
                    <td>{{ $note->idcompras ?? '-' }}</td>
                    <th>Fecha</th>
                    <td>{{ $note->fechaRealizacion ? Carbon::parse($note->fechaRealizacion)->locale('es')->isoFormat('D MMM YYYY, HH:mm') : '-' }}</td>
                </tr>
                <tr>
                    <th>Usuario</th>
                    <td>{{ $note->usuario_usuario ?? '-' }}</td>
                    <th>Tipo documento</th>
                    <td>{{ $note->tipoDocumento_nombre ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Documento referencia</th>
                    <td colspan="3">{{ $note->docReferencia ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Motivo</th>
                    <td colspan="3">{{ $note->motivo ?? '-' }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-header">Dispositivos y IMEIs</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: 42%;">Dispositivo</th>
                        <th style="width: 15%; text-align:center;">Cantidad</th>
                        <th style="width: 35%;">IMEIs</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $index => $item)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $item['dispositivo'] }}</td>
                            <td style="text-align:center;">{{ $item['cantidad'] }}</td>
                            <td class="imeis">{{ implode("\n", $item['imeis']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="footer">
            <table class="summary" style="width: 360px; float: right;">
                <tr>
                    <th>Total unidades</th>
                    <td>{{ $note->cantidadTotal }}</td>
                </tr>
            </table>
            <div style="clear: both;"></div>
        </div>
    </div>
</body>

</html>