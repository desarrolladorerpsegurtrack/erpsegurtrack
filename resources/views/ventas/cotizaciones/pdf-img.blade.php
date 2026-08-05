@php use Illuminate\Support\Carbon; @endphp
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Cotización {{ $quote->nroCotizacion ?? '' }}</title>
    <style>
        @page {
            margin: 50px 30px 30px 30px;
            /* El tercer valor agrandado da espacio al footer fijo abajo */
        }

        body {
            font-family: 'Helvetica Neue', 'Helvetica', 'Arial', sans-serif;
            color: #2c3e50;
            font-size: 11px;
            line-height: 1.4;
            margin: 0;
            padding: 0;
            position: relative;
        }   

        /* ===== HEADER ===== */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        .header-table td {
            border: none;
            padding: 0;
            vertical-align: top;
        }

        .brand-section {
            text-align: left;
        }

        .brand-section img {
            max-height: 100px;
            display: block;
            margin-bottom: 2px;
        }

        .company-details {
            font-size: 10px;
            color: #000000;
            margin-top: 2px;
            line-height: 1.5;
        }

        .document-details {
            text-align: right;
        }

        /* Título grande estilo PDF */
        .document-title {
            font-size: 48px;
            font-weight: normal;
            color: #2c3e50;
            letter-spacing: -1px;
            line-height: 1;
            margin-bottom: 4px;
        }

        .document-date {
            font-size: 15px;
            color: #000000;
            margin-bottom: 10px;
        }

        .cotizacion-numero {
            font-size: 13px;
            font-weight: 700;
            color: #000000;
        }

        .vendedor-dni {
            margin-top: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #000000;
        }

        .document-number {
            font-size: 18px;
            font-weight: 700;
            color: #e74c3c;
        }

        /* ===== DATOS DEL CLIENTE (Estilo PDF Original) ===== */
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #000000;
            letter-spacing: 0.5px;
            margin-bottom: 1px;
            margin-top: 15px;
        }

        .customer-data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            text-transform: uppercase;
            table-layout: fixed;
        }

        /* Forzamos que las dos mitades midan exactamente el 50% de la hoja */
        .customer-data-table td.col-half-left {
            width: 60%;
            padding-right: 10px; /* Este es tu "gap" o espacio de separación del medio */
            vertical-align: top;
        }

        .customer-data-table td.col-half-right {
            width: 30%;
            padding-left: 10px; /* Este es tu "gap" o espacio de separación del medio */
            vertical-align: top;
        }

        /* Tabla interna para los datos de cada bloque */
        .inner-data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .inner-data-table td {
            padding: 3px 0;
            border: none !important;
            vertical-align: top;
            font-size: 11px;
        }
        /* Etiquetas Bloque Izquierdo: Al ser textos cortos basta con un 22% */
        .lbl-field-left {
            font-weight: 700;
            color: #000000;
            width: 15%;
            white-space: nowrap;
        }

        /* Etiquetas Bloque Derecho: Al ser textos más largos ('Nº CONTACTO:') necesitan más espacio (32%) */
        .lbl-field-right {
            font-weight: 700;
            color: #000000;
            width: 38%;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .val-field {
            color: #000000;
            text-transform: uppercase;
            word-wrap: break-word;
        }


        /* ===== TABLA DE ÍTEMS  */ 
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;                    
        }
        
        .items-section {
            display: block;
            page-break-inside: avoid;
            margin-bottom: 10px;
        }

        /* Encabezado de sección sobre la tabla */
        .items-table thead tr.section-head th {
            background-color: #444444;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 0px 15px;
            border: 1px solid #c0c0c0;
            text-align: left;
        }

        /* Fila de cabeceras de columna */
        .items-table thead tr.col-head th {
            background-color: #b9b9b9ff;
            color: #000000;
            font-size: 13px;
            font-weight: 700;
            padding: 0px 10px;
            border-bottom: none;
            border-left: none;
            border-right: none;
            border-top: none;
        }

        /* Bordes extremos para simular el recuadro */
        .items-table thead tr.col-head th:first-child {
            border-left: 1px solid #c0c0c0;
        }

        .items-table thead tr.col-head th:last-child {
            border-right: 1px solid #c0c0c0;
        }

        .items-table tbody tr,
        .items-table tbody tr td {
            height: 60px;
            
        }

        .items-table tbody td {
            padding: 3px 10px;
            border: none;
            color: #000000;
            font-size: 11px;
            text-transform: uppercase;
            vertical-align: middle;
        }

        .items-table tbody td:first-child {
            border-left: 1px solid #c0c0c0;
            /* Borde izquierdo externo */
        }

        .items-table tbody td:last-child {
            border-right: 1px solid #c0c0c0;
            /* Borde derecho externo */
        }

        /* Forzar línea inferior externa fuerte en la última fila del cuerpo */
        .items-table tbody tr:last-child td {
            border-bottom: 1px solid #c0c0c0;
        }

        /* Fila de total al pie de la tabla */
        .items-table tfoot tr th {
            background-color: #444444;
            color: #ffffff;
            font-weight: 700;
            font-size: 13px;
            padding: 4px 10px;
            border: 1px solid #c0c0c0;
            text-align: center;
        }

        .items-table tfoot tr td.total-amount {
            background-color: #f0f0f0;
            color: #000000;
            font-weight: 700;
            font-size: 12px;
            padding: 5px 10px;
            border: 1px solid #c0c0c0;
        }

        /* Alineaciones y Anchos */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-left {
            text-align: left;
        }

        .col-cant {
            width: 10%;
            text-align: center;
        }

        .col-prod {
            width: 55%;
            text-align: center;
        }

        .col-descuento {
            width: 17%;
            text-align: center;
        }

        .col-punit {
            width: 17%;
            text-align: center;
        }

        .col-total {
            width: 18%;
            text-align: center;
        }

        /* ===== PIE DE PÁGINA ===== */
        .thank-you {
            font-size: 12px;
            font-weight: 700;
            color: #000000;
            margin-top: 19px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            border-top: none;
            page-break-inside: avoid;
        }

        .footer-table td {
            border: none;
            vertical-align: top;
            padding: 5px;
        }

        .footer-fixed {
            position: fixed;
            bottom: 0;
            left: 10px;
            right: 10px;
            width: auto;
        }

        .col-terms {
            width: 38%;
        }

        .col-bcp {
            width: 32%;
        }

        .col-bn {
            width: 22%;
        }

        .terms-list {
            margin: 0;
            padding-left: 15px;
            font-size: 12px;
            list-style-type: decimal;
        }

        .terms-list li {
            text-align: left;
        }

        .footer-block-title {
            font-size: 12px;
            font-weight: 700;
            color: #000000;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .footer-accent-text {
            font-weight: 700;
            font-size: 12px;
            color: #000000;
        }

        .divider-line {
            border-top: 1px solid #000000;
            border-bottom: 1px solid #000000;
            height: 1px;
            margin-top: 5px;
            margin-bottom: 10px;
            width: 100%;
        }

    </style>
</head>

<body>
    <div class="page">
        {{-- ===== TABLA DE ÍTEMS ===== --}}
        @php
            $maxRows = 5;
            $itemsArray = collect($items);
            $chunks = $itemsArray->isEmpty() ? collect([collect([])]) : $itemsArray->chunk($maxRows);
            $documentType = strtoupper(trim((string) ($quote->tipoDocumentoIDCliente ?? '')));
            $documentValue = $quote->cliente_idcliente ?? '-';
            $rucValue = in_array($documentType, ['RUC', '6'], true) ? $documentValue : '-';
            $dniValue = in_array($documentType, ['DNI', '1'], true) ? $documentValue : '-';
        @endphp

        @foreach($chunks as $chunkIndex => $chunk)
            @if($chunkIndex > 0)
                <div style="page-break-before: always;"></div>
            @endif

            {{-- ===== ENCABEZADO ===== --}}
            <table class="header-table">
                <tr>
                    <td class="brand-section" style="width: 55%;">
                        <img src="{{ public_path('images/logo-main.png') }}" alt="Logo">
                        <div class="company-details">
                            EMPRESA: SEGURTRAK S.A.C.<br>
                            RUC: 20603959061<br>
                            CORREO: ventas@segurtrack.com<br>
                            DIRECCIÓN: MZ A LOTE 41 URB. EL ASESOR II<br>
                            SANTA ANITA - LIMA - LIMA
                        </div>
                    </td>
                    <td class="document-details" style="width: 45%;">
                        <div class="document-title">COTIZACIÓN</div>
                        <div class="document-date">
                            {{ $quote->fechaHoraEmision ? Carbon::parse($quote->fechaHoraEmision)->locale('es')->isoFormat('D [de] MMMM YYYY') : '-' }}
                        </div>
                        <div class="cotizacion-numero">N° COTIZACIÓN</div>
                        <div class="document-number">{{ $quote->nroCotizacion ?? '-' }}</div>
                        <div class="vendedor-dni">VENDEDOR:
                            {{ ((string) ($quote->personal_dniPersonal ?? '')) . ' - ' . ($quote->personal_nombre ?? '') . ' ' . trim((string) ($quote->personal_apellido ?? '')) }}
                        </div>
                    </td>
                </tr>
            </table>

            {{-- ===== DATOS DEL CLIENTE ===== --}}
            <div class="section-title">DATOS DEL CLIENTE:</div>
            <table class="customer-data-table">
                <tr>
                    <td class="col-half-left">
                        <table class="inner-data-table">
                            <tr>
                                <td class="lbl-field-left">NOMBRE:</td>
                                <td class="val-field">{{ $quote->cliente_label ?? '-' }}</td>
                            </tr>
                            @if(in_array($documentType, ['RUC', '6'], true))
                                <tr>
                                    <td class="lbl-field-left">RUC:</td>
                                    <td class="val-field">{{ $rucValue }}</td>
                                </tr>
                            @else
                                <tr>
                                    <td class="lbl-field-left">DNI:</td>
                                    <td class="val-field">{{ $dniValue }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="lbl-field-left">DIRECCION:</td>
                                <td class="val-field">{{ $quote->direccion ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td class="lbl-field-left">VIGENCIA:</td>
                                <td class="val-field">{{ $quote->vigencia_detalle ?? '-' }}</td>
                            </tr>
                        </table>
                    </td>

                    <td class="col-half-right">
                        <table class="inner-data-table">
                            <tr>
                                <td class="lbl-field-right">CORREO:</td>
                                <td class="val-field">{{ $quote->correo ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td class="lbl-field-right">Nº CONTACTO:</td>
                                <td class="val-field">{{ $quote->telefono ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td class="lbl-field-right">F. PAGO:</td>
                                <td class="val-field">{{ $quote->formaPago_detalle ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td class="lbl-field-right">MONEDA:</td>
                                <td class="val-field">{{ $quote->moneda_detalle ?? '-' }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class="divider-line"></div>
            
            {{-- ===== TABLA DE ÍTEMS ===== --}}
            <div class="items-section">
                <table class="items-table">
                <thead>
                    <tr class="section-head">
                        <th colspan="5">{{ $section_title ?? 'EQUIPAMIENTO' }}</th>
                    </tr>
                    <tr class="col-head">
                        <th class="col-cant">Cant.</th>
                        <th class="col-prod ">Descripción</th>
                        <th class="col-punit ">P. Unitario</th>
                        <th class="col-descuento ">Desct %</th>
                        <th class="col-total ">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($chunk as $index => $item)
                        <tr>
                            <td class="text-center">{{ number_format($item->cantidad) }}</td>
                            <td class="text-left" style="vertical-align: middle;">
                                @if($include_image && !empty($item->producto_imagen))
                                    
                                    {{-- Estructura de tabla interna para forzar alineación vertical perfecta en PDFs --}}
                                    <table style="width: 100%; border: none; padding: 0; margin: 0; border-collapse: collapse;">
                                        <tr>
                                            {{-- Celda para el texto: alineada a la izquierda y centrada verticalmente --}}
                                            <td style="border: none; padding: 0; text-align: left; vertical-align: middle;">
                                                <span>{{ $item->producto ?? '-' }}</span>
                                            </td>
                                            
                                            {{-- Celda para la imagen: pegada al extremo derecho y centrada verticalmente --}}
                                            <td style="border: none; padding: 0; text-align: right; vertical-align: middle; width: 38px;">
                                                <img 
                                                    src="{{ public_path('storage/' . $item->producto_imagen) }}" 
                                                    alt="Producto" 
                                                    style="width: 60px; height: 60px; object-fit: cover; border-radius: 2px; display: block; margin-left: auto;" 
                                                />
                                            </td>
                                        </tr>
                                    </table>
                                @else
                                    {{ $item->producto ?? '-' }}
                                @endif
                            </td>
                            <td class="text-center">{{ $item->precio_label }}</td>
                            <td class="text-center">{{ $item->descuento_label }}</td>
                            <td class="text-center">{{ $item->total_label }}</td>
                        </tr>
                    @endforeach

                    {{-- Rellenar filas hasta $maxRows para mantener el recuadro estático --}}
                    @for ($i = $chunk->count(); $i < $maxRows; $i++)
                        <tr>
                            <td class="text-center">&nbsp;</td>
                            <td class="text-left">&nbsp;</td>
                            <td class="text-center">&nbsp;</td>
                            <td class="text-center">&nbsp;</td>
                            <td class="text-center">&nbsp;</td>
                        </tr>
                    @endfor
                </tbody>
                <tfoot>
                    <tr>
                        <td rowspan="5" colspan="3" style="border: none; background: transparent;"></td>
                        <th class="text-center">Importe</th>
                        <td class="text-center total-amount">{{ $importe_label }}</td>
                    </tr>
                    <tr>
                        <th class="text-center">Descuento</th>
                        <td class="text-center total-amount">{{ $descuento_amount_label }}</td>
                    </tr>
                    <tr>
                        <th class="text-center">SubTotal</th>
                        <td class="text-center total-amount">{{ $subtotal_after_discount_label }}</td>
                    </tr>
                    <tr>
                        <th class="text-center">IGV(18%)</th>
                        <td class="text-center total-amount">{{ $igv_amount_label }}</td>
                    </tr>
                    <tr>
                        <th class="text-center">Total</th>
                        <td class="text-center total-amount">{{ $total_general_label }}</td>
                    </tr>
                </tfoot>
                </table>

            </div>
        @endforeach
    </div>
    
    {{-- ===== PIE DE PÁGINA ===== --}}
    <div class="footer-fixed">
        <table class="footer-table">
            <tr>
                <td class="col-terms">
                    <div class="footer-block-title">TÉRMINOS Y CONDICIONES</div>
                    <ul class="terms-list">
                        <li>Todos los precios incluyen IGV.</li>
                        <li>Garantía de 1 año.</li>
                        <li>Entregado el producto o ejecutado<br>el servicio, no existen devoluciones.</li>
                    </ul>
                    <div class="thank-you">¡Gracias por la Preferencia!</div>
                </td>

                <td class="col-bcp">
                    <div class="footer-block-title">SEGURTRAK S.A.C</div>
                    <div class="footer-accent-text">DATOS DE PAGO</div>
                    <div class="footer-accent-text">Banco de Crédito Del Perú (BCP)</div>
                    <span class="footer-accent-text">Soles</span> 191-2581664-0-12<br>
                    <span class="footer-accent-text">CCI:</span> 00219100258166401259<br>
                    <span class="footer-accent-text">Dólares</span> 191-2559634-1-97<br>
                    <span class="footer-accent-text">CCI:</span> 00219100255963419751
                </td>

                <td class="col-bn">
                    <div class="footer-block-title">BANCO DE LA NACION</div>
                    <div class="footer-accent-text">Cuenta de Detracciones</div>
                    <span class="footer-accent-text">Soles</span> 00-099-162104
                </td>
            </tr>
        </table>
    </div>
</body>
</html>