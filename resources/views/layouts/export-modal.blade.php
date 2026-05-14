<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #222;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        .pdf-header {
            position: relative;
            padding: 12px 100px 18px 0;
            text-align: right;
        }
        .pdf-logo {
            position: absolute;
            top: 0;
            left: 0;
            width: 100px;
            height: auto;
        }
        h1 {
            font-size: 28px;
            margin: 0;
            line-height: 1.2;
            font-weight: 800;
            white-space: normal;
            word-break: break-word;
        }
        .pdf-table {
            margin-top: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #444;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #f3f3f3;
            font-weight: 700;
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
        <h1>{{ $title }}</h1>
    </div>
    <table class="pdf-table">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        @php
                            $value = null;
                            if (isset($column['value']) && is_callable($column['value'])) {
                                $value = $column['value']($row);
                            } else {
                                $value = data_get($row, $column['key']);
                            }
                        @endphp
                        <td>{!! $value !!}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
