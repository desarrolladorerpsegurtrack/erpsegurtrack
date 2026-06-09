<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ConsultaDocumentoController extends Controller
{
    public function consultar(Request $request)
    {
        // 1. Validar los datos de entrada que vienen del frontend
        $request->validate([
            'tipo' => 'required|in:ruc,dni',
            'valor' => 'required|string',
        ]);

        $tipo = $request->input('tipo');
        $valor = $request->input('valor');
        
        // 2. Obtener la API_KEY de forma segura desde el archivo .env
        $apiKey = env('SEGURTRACK_API_KEY');

        if (empty($apiKey)) {
            return response()->json([
                'status' => 'error', 
                'message' => 'API Key no configurada en el servidor.'
            ], 500);
        }

        // 3. Preparar y realizar la petición GET a la API externa
        $url = "https://tools.segurtrack.com/STKsearch/apiJTI.php";

        try {
            // Http::get construye automáticamente la query string (url?tipo=X&valor=Y&key=Z)
            $response = Http::get($url, [
                'tipo'  => $tipo,
                'valor' => $valor,
                'key'   => $apiKey
            ]);

            // Si la respuesta HTTP es 2xx, devolvemos el JSON tal cual al frontend
            if ($response->successful()) {
                return $response->json();
            }

            // Manejo de errores si la API responde con error
            return response()->json([
                'status' => 'error',
                'message' => 'Error al comunicarse con el servicio de consulta.'
            ], $response->status());

        } catch (\Exception $e) {
            // Manejo de excepciones (ej. problemas de conexión, timeout)
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }
}
